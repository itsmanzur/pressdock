import type { SqlDb, SqlValue, Stmt } from '../platform/types';
import type { Entity, EntitySchema, LocalRecord, RecordData, RecordStatus } from '../schema/types';
import { migrate } from './migrations';

interface RecordRow {
  uuid: string;
  entity: string;
  remote_id: number | null;
  data_json: string;
  base_revision: number;
  status: RecordStatus;
  updated_at: string;
}

interface EntityRow {
  key: string;
  label: string;
  group: string;
  schema_json: string;
  cursor: number | null;
  actions_json: string | null;
}

export interface OutboxOp {
  op_id: string;
  uuid: string;
  entity: string;
  op: 'create' | 'update' | 'delete';
  payload_json: string | null;
  base_revision: number | null;
  created_at: string;
  attempts: number;
  last_error: string | null;
}

const now = () => new Date().toISOString();
export const newId = (): string => crypto.randomUUID();
const CHUNK = 400;

function chunks<T>(arr: T[], n = CHUNK): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < arr.length; i += n) out.push(arr.slice(i, i + n));
  return out;
}

/** Fields the server accepts in a push: not read-only (and, for updates, not create-only). */
export function writablePayload(schema: EntitySchema, data: RecordData, forUpdate: boolean): RecordData {
  const out: RecordData = {};
  for (const [k, v] of Object.entries(data)) {
    const def = schema[k];
    if (!def || def.readonly || (forUpdate && def.create_only)) continue;
    out[k] = v;
  }
  return out;
}

function toRecord(r: RecordRow): LocalRecord {
  let data: RecordData = {};
  try {
    data = JSON.parse(r.data_json) as RecordData;
  } catch {
    /* corrupt row: show empty */
  }
  return {
    uuid: r.uuid,
    entity: r.entity,
    remote_id: r.remote_id,
    data,
    base_revision: r.base_revision,
    status: r.status,
    updated_at: r.updated_at,
  };
}

const sameValue = (a: unknown, b: unknown) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);

/**
 * All local reads/writes for ONE site database.
 * Rule (spec §4): every create/edit/delete changes `records` and `outbox` in ONE SQLite transaction.
 */
export class SiteRepo {
  private listeners = new Set<() => void>();

  private constructor(readonly db: SqlDb) {}

  static async open(db: SqlDb): Promise<SiteRepo> {
    await migrate(db);
    return new SiteRepo(db);
  }

  subscribe(fn: () => void): () => void {
    this.listeners.add(fn);
    return () => this.listeners.delete(fn);
  }
  private emit() {
    this.listeners.forEach((l) => l());
  }

  // ---------------------------------------------------------------- entities

  async saveEntities(entities: Entity[]): Promise<void> {
    const stmts: Stmt[] = entities.map((e) => ({
      sql: `INSERT INTO entities (key, label, "group", schema_json, cursor, actions_json, updated_at)
            VALUES ($1, $2, $3, $4, NULL, $5, $6)
            ON CONFLICT(key) DO UPDATE SET label = excluded.label, "group" = excluded."group",
              schema_json = excluded.schema_json, actions_json = excluded.actions_json, updated_at = excluded.updated_at`,
      params: [e.key, e.label, e.group, JSON.stringify(e.schema), JSON.stringify(e.actions), now()],
    }));
    // Drop entities the server no longer exposes, unless local data still references them.
    const keys = entities.map((e) => e.key);
    stmts.push({
      sql: `DELETE FROM entities WHERE key NOT IN (${keys.map((_, i) => `$${i + 1}`).join(',') || "''"})
            AND key NOT IN (SELECT DISTINCT entity FROM records)
            AND key NOT IN (SELECT DISTINCT entity FROM outbox)`,
      params: keys,
    });
    await this.db.batch(stmts);
    this.emit();
  }

  async listEntities(): Promise<Entity[]> {
    const rows = await this.db.select<EntityRow>('SELECT * FROM entities ORDER BY "group", label');
    return rows.map((r) => ({
      key: r.key,
      label: r.label,
      group: r.group,
      cursor: r.cursor,
      schema: JSON.parse(r.schema_json),
      actions: r.actions_json
        ? JSON.parse(r.actions_json)
        : { read: true, create: true, update: true, delete: true },
    }));
  }

  // ----------------------------------------------------------------- reads

  /** Live records of an entity (soft-deleted ones only with `includeDeleted`). */
  async listRecords(entity: string, includeDeleted = false): Promise<LocalRecord[]> {
    const rows = await this.db.select<RecordRow>(
      `SELECT uuid, entity, remote_id, data_json, base_revision, status, updated_at FROM records
       WHERE entity = $1 ${includeDeleted ? '' : "AND status != 'deleted'"} ORDER BY rowid`,
      [entity],
    );
    return rows.map(toRecord);
  }

  async getRecord(uuid: string): Promise<LocalRecord | null> {
    const rows = await this.db.select<RecordRow>(`SELECT * FROM records WHERE uuid = $1`, [uuid]);
    return rows[0] ? toRecord(rows[0]) : null;
  }

  private async getMany(uuids: string[]): Promise<LocalRecord[]> {
    const out: LocalRecord[] = [];
    for (const part of chunks(uuids)) {
      const rows = await this.db.select<RecordRow>(
        `SELECT * FROM records WHERE uuid IN (${part.map((_, i) => `$${i + 1}`).join(',')})`,
        part,
      );
      out.push(...rows.map(toRecord));
    }
    return out;
  }

  async countByEntity(): Promise<Record<string, number>> {
    const rows = await this.db.select<{ entity: string; n: number }>(
      `SELECT entity, COUNT(*) AS n FROM records WHERE status != 'deleted' GROUP BY entity`,
    );
    return Object.fromEntries(rows.map((r) => [r.entity, Number(r.n)]));
  }

  async pendingCount(): Promise<number> {
    const rows = await this.db.select<{ n: number }>('SELECT COUNT(*) AS n FROM outbox');
    return Number(rows[0]?.n ?? 0);
  }

  async conflictCount(): Promise<number> {
    const rows = await this.db.select<{ n: number }>(`SELECT COUNT(*) AS n FROM records WHERE status = 'conflict'`);
    return Number(rows[0]?.n ?? 0);
  }

  async listOutbox(): Promise<OutboxOp[]> {
    return this.db.select<OutboxOp>('SELECT * FROM outbox ORDER BY rowid');
  }

  // ---------------------------------------------------------------- writes

  private buildCreate(entity: Entity, data: RecordData, uuid = newId()): { uuid: string; stmts: Stmt[] } {
    const ts = now();
    return {
      uuid,
      stmts: [
        {
          sql: `INSERT INTO records (uuid, entity, remote_id, data_json, base_revision, server_json, status, updated_at)
                VALUES ($1, $2, NULL, $3, 0, NULL, 'new', $4)`,
          params: [uuid, entity.key, JSON.stringify(data), ts],
        },
        {
          sql: `INSERT INTO outbox (op_id, uuid, entity, op, payload_json, base_revision, created_at)
                VALUES ($1, $2, $3, 'create', $4, NULL, $5)`,
          params: [newId(), uuid, entity.key, JSON.stringify(writablePayload(entity.schema, data, false)), ts],
        },
      ],
    };
  }

  /** New records get a UUID v4. Returns it. */
  async createRecord(entity: Entity, data: RecordData): Promise<string> {
    const { uuid, stmts } = this.buildCreate(entity, data);
    await this.db.batch(stmts);
    this.emit();
    return uuid;
  }

  private buildUpdate(entity: Entity, rec: LocalRecord, patch: RecordData): Stmt[] {
    // keep only fields that really change
    const changed: RecordData = {};
    for (const [k, v] of Object.entries(patch)) if (!sameValue(rec.data[k], v)) changed[k] = v;
    if (Object.keys(changed).length === 0) return [];
    const merged = { ...rec.data, ...changed };
    const ts = now();
    const stmts: Stmt[] = [];

    if (rec.status === 'new') {
      // Never synced: fold the edit into the still-pending create op (server must see ONE create).
      stmts.push({
        sql: `UPDATE records SET data_json = $1, updated_at = $2 WHERE uuid = $3`,
        params: [JSON.stringify(merged), ts, rec.uuid],
      });
      stmts.push({
        sql: `UPDATE outbox SET payload_json = $1 WHERE uuid = $2 AND op = 'create'`,
        params: [JSON.stringify(writablePayload(entity.schema, merged, false)), rec.uuid],
      });
      return stmts;
    }

    const nextStatus: RecordStatus = rec.status === 'conflict' ? 'conflict' : 'dirty';
    stmts.push({
      sql: `UPDATE records SET data_json = $1, status = $2, updated_at = $3 WHERE uuid = $4`,
      params: [JSON.stringify(merged), nextStatus, ts, rec.uuid],
    });
    const payload = writablePayload(entity.schema, changed, true);
    if (Object.keys(payload).length) {
      stmts.push({
        sql: `INSERT INTO outbox (op_id, uuid, entity, op, payload_json, base_revision, created_at)
              VALUES ($1, $2, $3, 'update', $4, $5, $6)`,
        params: [newId(), rec.uuid, entity.key, JSON.stringify(payload), rec.base_revision, ts],
      });
    }
    return stmts;
  }

  async updateRecord(entity: Entity, uuid: string, patch: RecordData): Promise<void> {
    const rec = await this.getRecord(uuid);
    if (!rec || rec.status === 'deleted') throw new Error('Record not found');
    await this.db.batch(this.buildUpdate(entity, rec, patch));
    this.emit();
  }

  /** Bulk edit: same patch for many records, one transaction. */
  async updateMany(entity: Entity, uuids: string[], patch: RecordData): Promise<number> {
    const recs = (await this.getMany(uuids)).filter((r) => r.status !== 'deleted');
    const stmts = recs.flatMap((r) => this.buildUpdate(entity, r, patch));
    await this.db.batch(stmts);
    this.emit();
    return recs.length;
  }

  private buildDelete(rec: LocalRecord): Stmt[] {
    if (rec.status === 'deleted') return [];
    if (rec.status === 'new') {
      // Never reached the server: remove it and its pending ops entirely.
      return [
        { sql: `DELETE FROM outbox WHERE uuid = $1`, params: [rec.uuid] },
        { sql: `DELETE FROM records WHERE uuid = $1`, params: [rec.uuid] },
      ];
    }
    return [
      // pending edits are moot once the record is deleted
      { sql: `DELETE FROM outbox WHERE uuid = $1 AND op = 'update'`, params: [rec.uuid] },
      {
        sql: `UPDATE records SET status = 'deleted', updated_at = $1 WHERE uuid = $2`,
        params: [now(), rec.uuid],
      },
      {
        sql: `INSERT INTO outbox (op_id, uuid, entity, op, payload_json, base_revision, created_at)
              VALUES ($1, $2, $3, 'delete', NULL, $4, $5)`,
        params: [newId(), rec.uuid, rec.entity, rec.base_revision, now()],
      },
    ];
  }

  /** Soft delete (status = deleted) until synced. Returns how many records were affected. */
  async deleteRecords(uuids: string[]): Promise<number> {
    const recs = await this.getMany(uuids);
    const stmts = recs.flatMap((r) => this.buildDelete(r));
    await this.db.batch(stmts);
    this.emit();
    return recs.length;
  }

  // ------------------------------------------------------- relation lookups

  /** Resolve a relation value (remote_id number or uuid string) to a local record. */
  async findByRef(entity: string, ref: unknown): Promise<LocalRecord | null> {
    if (ref === null || ref === undefined || ref === '') return null;
    const rows = await this.db.select<RecordRow>(
      `SELECT * FROM records WHERE entity = $1 AND status != 'deleted' AND (uuid = $2 OR remote_id = $3) LIMIT 1`,
      [entity, String(ref), Number.isInteger(Number(ref)) ? Number(ref) : -1],
    );
    return rows[0] ? toRecord(rows[0]) : null;
  }

  /** Substring search over the stored JSON of an entity (picker). */
  async searchRecords(entity: string, q: string, limit = 50): Promise<LocalRecord[]> {
    const like = '%' + q.replace(/[\\%_]/g, (c) => '\\' + c) + '%';
    const rows = await this.db.select<RecordRow>(
      `SELECT * FROM records WHERE entity = $1 AND status != 'deleted' AND data_json LIKE $2 ESCAPE '\\' ORDER BY rowid LIMIT ${Math.max(1, Math.floor(limit))}`,
      [entity, like],
    );
    return rows.map(toRecord);
  }

  // ------------------------------------------------------------ media / misc

  async savePendingMedia(file: { name: string; mime: string; size: number; dataB64: string }): Promise<string> {
    const id = newId();
    await this.db.execute(
      `INSERT INTO media_pending (id, name, mime, size, data_b64, created_at) VALUES ($1,$2,$3,$4,$5,$6)`,
      [id, file.name, file.mime, file.size, file.dataB64, now()],
    );
    return id;
  }

  async getPendingMediaMeta(id: string): Promise<{ id: string; name: string; mime: string; size: number } | null> {
    const rows = await this.db.select<{ id: string; name: string; mime: string; size: number }>(
      `SELECT id, name, mime, size FROM media_pending WHERE id = $1`,
      [id],
    );
    return rows[0] ?? null;
  }

  /** Remove everything stored for this site (used when a site is removed). */
  async wipe(): Promise<void> {
    await this.db.batch(
      ['sync_log', 'outbox', 'records', 'entities', 'media_pending'].map((t) => ({ sql: `DELETE FROM ${t}` })),
    );
    await this.db.execute('VACUUM');
    this.emit();
  }

  /** DEV ONLY: bulk-insert synthetic "synced" rows to exercise grid virtualization. */
  async seedDemo(entity: Entity, count: number): Promise<void> {
    const fields = Object.entries(entity.schema);
    const startId = 1_000_000;
    const ts = now();
    for (let off = 0; off < count; off += 500) {
      const stmts: Stmt[] = [];
      for (let i = off; i < Math.min(count, off + 500); i++) {
        const data: RecordData = {};
        for (const [k, def] of fields) {
          switch (def.type) {
            case 'int':
            case 'float':
              data[k] = i;
              break;
            case 'bool':
              data[k] = i % 2 === 0;
              break;
            case 'enum': {
              const o = Array.isArray(def.options) ? def.options : Object.keys(def.options ?? {});
              data[k] = o.length ? o[i % o.length] : '';
              break;
            }
            case 'date':
              data[k] = `2026-${String((i % 12) + 1).padStart(2, '0')}-${String((i % 28) + 1).padStart(2, '0')}`;
              break;
            case 'string':
            case 'text':
              data[k] = `${def.label || k} #${i + 1}`;
              break;
            default:
              break;
          }
        }
        stmts.push({
          sql: `INSERT INTO records (uuid, entity, remote_id, data_json, base_revision, server_json, status, updated_at)
                VALUES ($1, $2, $3, $4, 1, NULL, 'synced', $5)`,
          params: [newId(), entity.key, startId + i, JSON.stringify(data), ts] as SqlValue[],
        });
      }
      await this.db.batch(stmts);
    }
    this.emit();
  }
}
