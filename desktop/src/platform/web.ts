/**
 * Browser platform: the hosted web app, `npm run dev` in a plain browser, and unit tests.
 * Uses sql.js (SQLite compiled to WASM) so the real SQL and migrations run unchanged.
 * Each site database is kept as one snapshot in IndexedDB (written shortly after every change and
 * when the tab is hidden). Passwords stay in sessionStorage unless the user opts in to remember them.
 */
import initSqlJs, { type Database as SqlJsDb, type SqlJsStatic } from 'sql.js';
import type { Platform, SqlDb, SqlValue, Stmt } from './types';

/* ------------------------------------------------------------------ IndexedDB snapshot store */
const IDB_NAME = 'pressdock';
const IDB_STORE = 'databases';
let idbPromise: Promise<IDBDatabase | null> | null = null;

function idb(): Promise<IDBDatabase | null> {
  if (typeof indexedDB === 'undefined') return Promise.resolve(null);
  idbPromise ??= new Promise((resolve) => {
    try {
      const req = indexedDB.open(IDB_NAME, 1);
      req.onupgradeneeded = () => req.result.createObjectStore(IDB_STORE);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
      req.onblocked = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
  return idbPromise;
}

async function idbGet(key: string): Promise<Uint8Array | null> {
  const db = await idb();
  if (!db) return null;
  return new Promise((resolve) => {
    const req = db.transaction(IDB_STORE, 'readonly').objectStore(IDB_STORE).get(key);
    req.onsuccess = () => resolve(req.result instanceof Uint8Array ? req.result : null);
    req.onerror = () => resolve(null);
  });
}

async function idbPut(key: string, value: Uint8Array): Promise<boolean> {
  const db = await idb();
  if (!db) return false;
  return new Promise((resolve) => {
    const tx = db.transaction(IDB_STORE, 'readwrite');
    tx.objectStore(IDB_STORE).put(value, key);
    tx.oncomplete = () => resolve(true);
    tx.onerror = () => resolve(false);
    tx.onabort = () => resolve(false);
  });
}

/** Every open database registers its flush here so a hidden/closing tab can save at once. */
const flushers = new Set<() => void>();
if (typeof window !== 'undefined') {
  const flushAll = () => flushers.forEach((f) => f());
  window.addEventListener('pagehide', flushAll);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flushAll();
  });
}

/**
 * Only one tab may open the databases: each tab writes whole snapshots, so two tabs would
 * overwrite each other's changes. Returns false when another tab already holds the lock.
 */
export function acquireTabLock(): Promise<boolean> {
  const locks = typeof navigator !== 'undefined' ? (navigator as Navigator & { locks?: LockManager }).locks : undefined;
  if (!locks) return Promise.resolve(true);
  return new Promise((resolve) => {
    void locks.request('pressdock-app', { ifAvailable: true }, (lock) => {
      resolve(lock !== null);
      // hold the lock for the life of the tab
      return lock ? new Promise<void>(() => {}) : undefined;
    });
  });
}

const REMEMBER_KEY = 'pressdock-kv:remember-passwords';
const rememberPasswords = () => {
  try {
    return localStorage.getItem(REMEMBER_KEY) === 'true';
  } catch {
    return false;
  }
};

let sqlJs: Promise<SqlJsStatic> | null = null;
function getSqlJs(): Promise<SqlJsStatic> {
  sqlJs ??= (async () => {
    if (typeof window === 'undefined') return initSqlJs();
    const wasm = (await import('sql.js/dist/sql-wasm.wasm?url')).default;
    return initSqlJs({ locateFile: () => wasm } as unknown as Parameters<typeof initSqlJs>[0]);
  })();
  return sqlJs;
}

const toPositional = (sql: string) => sql.replace(/\$\d+/g, '?');
const unb64 = (s: string) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));

export async function openMemoryDb(name: string, persist: boolean): Promise<SqlDb> {
  const SQL = await getSqlJs();
  const key = `pressdock-web-db:${name}`;
  let saved: Uint8Array | null = null;
  if (persist) {
    saved = await idbGet(key);
    if (!saved) {
      // one-time move from the old localStorage format
      try {
        const legacy = localStorage.getItem(key);
        if (legacy) {
          saved = unb64(legacy);
          if (await idbPut(key, saved)) localStorage.removeItem(key);
        }
      } catch {
        /* ignore */
      }
    }
  }
  const db: SqlJsDb = saved ? new SQL.Database(saved) : new SQL.Database();
  let timer: ReturnType<typeof setTimeout> | undefined;
  let dirty = false;
  const saveNow = () => {
    clearTimeout(timer);
    if (!dirty) return;
    dirty = false;
    void idbPut(key, db.export()).then((ok) => {
      if (!ok) dirty = true; // retry on the next change or hide
    });
  };
  if (persist) flushers.add(saveNow);
  const flush = () => {
    if (!persist) return;
    dirty = true;
    clearTimeout(timer);
    timer = setTimeout(saveNow, 400);
  };
  const select = async <T,>(sql: string, params: SqlValue[] = []) => {
    const st = db.prepare(toPositional(sql));
    try {
      st.bind(params);
      const out: T[] = [];
      while (st.step()) out.push(st.getAsObject() as T);
      return out;
    } finally {
      st.free();
    }
  };
  const execute = async (sql: string, params: SqlValue[] = []) => {
    db.run(toPositional(sql), params);
    flush();
    return { rowsAffected: db.getRowsModified() };
  };
  return {
    select,
    execute,
    batch: async (stmts: Stmt[]) => {
      db.run('BEGIN IMMEDIATE');
      try {
        for (const s of stmts) db.run(toPositional(s.sql), s.params ?? []);
        db.run('COMMIT');
      } catch (e) {
        db.run('ROLLBACK');
        throw e;
      }
      flush();
    },
    close: async () => {
      saveNow();
      flushers.delete(saveNow);
    },
  };
}

const dbs = new Map<string, Promise<SqlDb>>();

export const webPlatform: Platform = {
  isTauri: false,
  openDb(name) {
    let p = dbs.get(name);
    if (!p) {
      p = openMemoryDb(name, true);
      dbs.set(name, p);
    }
    return p;
  },
  kv: {
    async get<T>(key: string) {
      try {
        const v = localStorage.getItem('pressdock-kv:' + key);
        return v === null ? undefined : (JSON.parse(v) as T);
      } catch {
        return undefined;
      }
    },
    async set(key, value) {
      localStorage.setItem('pressdock-kv:' + key, JSON.stringify(value));
    },
    async delete(key) {
      localStorage.removeItem('pressdock-kv:' + key);
    },
  },
  secrets: {
    async set(a, s) {
      const k = 'pressdock-secret:' + a;
      try {
        if (rememberPasswords()) {
          localStorage.setItem(k, s);
          sessionStorage.removeItem(k);
        } else {
          sessionStorage.setItem(k, s);
          localStorage.removeItem(k);
        }
      } catch {
        /* storage blocked: the password is asked again */
      }
    },
    async get(a) {
      const k = 'pressdock-secret:' + a;
      try {
        return sessionStorage.getItem(k) ?? localStorage.getItem(k);
      } catch {
        return null;
      }
    },
    async delete(a) {
      const k = 'pressdock-secret:' + a;
      try {
        sessionStorage.removeItem(k);
        localStorage.removeItem(k);
      } catch {
        /* ignore */
      }
    },
  },
  fetch: (...args) => fetch(...args),
  async saveFile(defaultName, bytes, mime) {
    const url = URL.createObjectURL(new Blob([bytes as BlobPart], { type: mime }));
    const a = document.createElement('a');
    a.href = url;
    a.download = defaultName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
    return true;
  },
  async openUrl(url) {
    window.open(url, '_blank', 'noopener');
  },
  async setWindowTitle(t) {
    document.title = t;
  },
};
