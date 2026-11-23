/**
 * Browser fallback used by `npm run dev` in a plain browser and by unit tests.
 * Uses sql.js (SQLite compiled to WASM) so the real SQL and migrations run unchanged.
 * NOT for production: data is kept in localStorage and secrets in sessionStorage.
 */
import initSqlJs, { type Database as SqlJsDb, type SqlJsStatic } from 'sql.js';
import type { Platform, SqlDb, SqlValue, Stmt } from './types';

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
const b64 = (u: Uint8Array) => btoa(Array.from(u, (c) => String.fromCharCode(c)).join(''));
const unb64 = (s: string) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));

export async function openMemoryDb(name: string, persist: boolean): Promise<SqlDb> {
  const SQL = await getSqlJs();
  const key = `pressdock-web-db:${name}`;
  let saved: string | null = null;
  try {
    saved = persist ? localStorage.getItem(key) : null;
  } catch {
    /* ignore */
  }
  const db: SqlJsDb = saved ? new SQL.Database(unb64(saved)) : new SQL.Database();
  let timer: ReturnType<typeof setTimeout> | undefined;
  const flush = () => {
    if (!persist) return;
    clearTimeout(timer);
    timer = setTimeout(() => {
      try {
        localStorage.setItem(key, b64(db.export()));
      } catch {
        /* quota */
      }
    }, 300);
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
      flush();
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
      sessionStorage.setItem('pressdock-secret:' + a, s);
    },
    async get(a) {
      return sessionStorage.getItem('pressdock-secret:' + a);
    },
    async delete(a) {
      sessionStorage.removeItem('pressdock-secret:' + a);
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
