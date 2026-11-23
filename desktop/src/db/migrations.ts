import type { SqlDb, Stmt } from '../platform/types';

interface Migration {
  version: number;
  statements: string[];
}

/**
 * Append-only list. Each migration runs in one transaction together with
 * `PRAGMA user_version = N`, so a half-applied migration can never happen.
 */
export const MIGRATIONS: Migration[] = [
  {
    version: 1,
    statements: [
      `CREATE TABLE entities (
        key TEXT PRIMARY KEY,
        label TEXT NOT NULL,
        "group" TEXT NOT NULL DEFAULT '',
        schema_json TEXT NOT NULL,
        cursor INTEGER,
        actions_json TEXT,
        updated_at TEXT
      )`,
      `CREATE TABLE records (
        uuid TEXT PRIMARY KEY,
        entity TEXT NOT NULL,
        remote_id INTEGER,
        data_json TEXT NOT NULL,
        base_revision INTEGER NOT NULL DEFAULT 0,
        server_json TEXT,
        status TEXT NOT NULL CHECK (status IN ('synced','dirty','new','deleted','conflict')),
        updated_at TEXT NOT NULL
      )`,
      `CREATE INDEX idx_records_entity_status ON records (entity, status)`,
      `CREATE INDEX idx_records_entity_remote ON records (entity, remote_id)`,
      `CREATE TABLE outbox (
        op_id TEXT PRIMARY KEY,
        uuid TEXT NOT NULL,
        entity TEXT NOT NULL,
        op TEXT NOT NULL CHECK (op IN ('create','update','delete')),
        payload_json TEXT,
        base_revision INTEGER,
        created_at TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT
      )`,
      `CREATE INDEX idx_outbox_uuid ON outbox (uuid)`,
      `CREATE TABLE sync_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        started_at TEXT NOT NULL,
        finished_at TEXT,
        pushed INTEGER NOT NULL DEFAULT 0,
        pulled INTEGER NOT NULL DEFAULT 0,
        conflicts INTEGER NOT NULL DEFAULT 0,
        errors INTEGER NOT NULL DEFAULT 0,
        batch_id TEXT
      )`,
      // Files chosen for "media" fields while offline; the sync engine uploads them via POST /media.
      `CREATE TABLE media_pending (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        mime TEXT,
        size INTEGER NOT NULL DEFAULT 0,
        data_b64 TEXT NOT NULL,
        created_at TEXT NOT NULL
      )`,
    ],
  },
];

export const LATEST_VERSION = MIGRATIONS[MIGRATIONS.length - 1].version;

export async function currentVersion(db: SqlDb): Promise<number> {
  const rows = await db.select<{ user_version: number }>('PRAGMA user_version');
  return Number(rows[0]?.user_version ?? 0);
}

export async function migrate(db: SqlDb): Promise<number> {
  let version = await currentVersion(db);
  for (const m of MIGRATIONS) {
    if (m.version <= version) continue;
    const stmts: Stmt[] = m.statements.map((sql) => ({ sql }));
    stmts.push({ sql: `PRAGMA user_version = ${m.version}` });
    await db.batch(stmts);
    version = m.version;
  }
  return version;
}
