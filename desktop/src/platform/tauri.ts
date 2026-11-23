import { invoke } from '@tauri-apps/api/core';
import { getCurrentWindow } from '@tauri-apps/api/window';
import Database from '@tauri-apps/plugin-sql';
import { fetch as tauriFetch } from '@tauri-apps/plugin-http';
import { load, type Store } from '@tauri-apps/plugin-store';
import { openUrl } from '@tauri-apps/plugin-opener';
import { save } from '@tauri-apps/plugin-dialog';
import { writeFile } from '@tauri-apps/plugin-fs';
import { transactionScript } from '../db/inline';
import type { Platform, SqlDb, SqlValue, Stmt } from './types';

async function openDb(name: string): Promise<SqlDb> {
  const db = await Database.load(`sqlite:${name}.db`);
  return {
    select: <T,>(sql: string, params: SqlValue[] = []) => db.select<T[]>(sql, params),
    execute: async (sql, params = []) => {
      const r = await db.execute(sql, params);
      return { rowsAffected: r.rowsAffected };
    },
    batch: async (stmts: Stmt[]) => {
      if (!stmts.length) return;
      try {
        await db.execute(transactionScript(stmts));
      } catch (e) {
        // A failed script may leave the transaction open on its connection; try to close it.
        try {
          await db.execute('ROLLBACK');
        } catch {
          /* nothing open */
        }
        throw e;
      }
    },
    close: async () => {
      await db.close();
    },
  };
}

let storePromise: Promise<Store> | null = null;
function store(): Promise<Store> {
  storePromise ??= load('pressdock-settings.json', { autoSave: false, defaults: {} });
  return storePromise;
}

export const tauriPlatform: Platform = {
  isTauri: true,
  openDb,
  kv: {
    async get<T>(key: string) {
      return (await (await store()).get<T>(key)) ?? undefined;
    },
    async set(key, value) {
      const s = await store();
      await s.set(key, value);
      await s.save();
    },
    async delete(key) {
      const s = await store();
      await s.delete(key);
      await s.save();
    },
  },
  secrets: {
    // Application Passwords live ONLY in the OS keychain (Rust `keyring` bridge).
    set: (account, secret) => invoke('secret_set', { account, secret }),
    get: (account) => invoke<string | null>('secret_get', { account }),
    delete: (account) => invoke('secret_delete', { account }),
  },
  fetch: tauriFetch as typeof fetch,
  openUrl: (url) => openUrl(url),
  async saveFile(defaultName, bytes) {
    const path = await save({ defaultPath: defaultName });
    if (!path) return false;
    await writeFile(path, bytes);
    return true;
  },
  setWindowTitle: (t) => getCurrentWindow().setTitle(t),
};
