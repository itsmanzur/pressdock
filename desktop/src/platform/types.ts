export type SqlValue = string | number | null;

export interface Stmt {
  sql: string;
  params?: SqlValue[];
}

/** Minimal SQLite surface the app needs. Placeholders are `$1, $2, …` used in order, each once. */
export interface SqlDb {
  select<T = Record<string, unknown>>(sql: string, params?: SqlValue[]): Promise<T[]>;
  execute(sql: string, params?: SqlValue[]): Promise<{ rowsAffected: number }>;
  /** Run all statements in ONE SQLite transaction (all or nothing). */
  batch(stmts: Stmt[]): Promise<void>;
  close(): Promise<void>;
}

export interface KeyValueStore {
  get<T>(key: string): Promise<T | undefined>;
  set(key: string, value: unknown): Promise<void>;
  delete(key: string): Promise<void>;
}

export interface Secrets {
  set(account: string, secret: string): Promise<void>;
  get(account: string): Promise<string | null>;
  delete(account: string): Promise<void>;
}

export interface Platform {
  /** true inside the Tauri shell; false in the plain-browser dev preview. */
  isTauri: boolean;
  openDb(name: string): Promise<SqlDb>;
  kv: KeyValueStore;
  secrets: Secrets;
  fetch: typeof fetch;
  openUrl(url: string): Promise<void>;
  setWindowTitle(title: string): Promise<void>;
}
