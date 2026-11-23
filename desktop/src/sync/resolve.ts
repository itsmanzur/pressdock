import type { SiteRepo, SyncLogRow } from '../db/repo';
import { newId, writablePayload } from '../db/repo';
import type { Stmt } from '../platform/types';
import type { Entity, FieldDef, LocalRecord, RecordData } from '../schema/types';
import type { SyncApi } from './types';

export type Choice = { pick: 'mine' } | { pick: 'server' } | { pick: 'manual'; value: unknown };
export type Resolution = 'mine' | 'server' | Record<string, Choice>;

const same = (a: unknown, b: unknown) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
const now = () => new Date().toISOString();

const blank = (v: unknown) => v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0);

/** Default for a field the user did not decide: keep mine, unless I never had a value and the server does. */
export const defaultPick = (local: unknown, server: unknown): 'mine' | 'server' => (blank(local) && !blank(server) ? 'server' : 'mine');

export interface FieldDiff {
  key: string;
  def: FieldDef;
  local: unknown;
  server: unknown;
  differs: boolean;
}

/** Per-field local vs server comparison for the conflict screen. */
export function diffFields(entity: Entity, rec: LocalRecord): FieldDiff[] {
  const server = rec.server?.data ?? {};
  return Object.entries(entity.schema).map(([key, def]) => ({
    key,
    def,
    local: rec.data[key],
    server: server[key],
    differs: !same(rec.data[key], server[key]),
  }));
}

/**
 * Resolve one conflicted record. Never loses the local data until the user chose:
 * the outcome is ONE new update op with base_revision = server revision (or a delete / re-create).
 */
export async function resolveConflict(repo: SiteRepo, entity: Entity, uuid: string, resolution: Resolution): Promise<void> {
  const rec = await repo.getRecord(uuid);
  if (!rec || rec.status !== 'conflict' || !rec.server) throw new Error('Record is not in conflict');
  const snap = rec.server;
  const wantsMine = resolution === 'mine' || (typeof resolution === 'object' && Object.values(resolution).some((c) => c.pick !== 'server'));
  const stmts: Stmt[] = [];
  const clearOps: Stmt = { sql: `DELETE FROM outbox WHERE uuid = $1`, params: [uuid] };

  // server deleted the record
  if (snap.deleted || snap.data === null) {
    if (!wantsMine || rec.local_deleted) {
      stmts.push(clearOps, { sql: `DELETE FROM records WHERE uuid = $1`, params: [uuid] });
    } else {
      // keep mine: the old uuid belongs to a deleted record, so re-create under a fresh uuid
      const fresh = newId();
      const ts = now();
      stmts.push(
        clearOps,
        { sql: `DELETE FROM records WHERE uuid = $1`, params: [uuid] },
        {
          sql: `INSERT INTO records (uuid, entity, remote_id, data_json, base_revision, server_json, status, updated_at)
                VALUES ($1, $2, NULL, $3, 0, NULL, 'new', $4)`,
          params: [fresh, rec.entity, JSON.stringify(rec.data), ts],
        },
        {
          sql: `INSERT INTO outbox (op_id, uuid, entity, op, payload_json, base_revision, created_at) VALUES ($1, $2, $3, 'create', $4, NULL, $5)`,
          params: [newId(), fresh, rec.entity, JSON.stringify(writablePayload(entity.schema, rec.data, false)), ts],
        },
      );
    }
    await repo.runTx(stmts);
    return;
  }

  const remoteId = snap.remote_id ?? rec.remote_id;

  // the user had asked to delete; server edited it meanwhile
  if (rec.local_deleted) {
    stmts.push(clearOps);
    if (wantsMine) {
      stmts.push(
        {
          sql: `UPDATE records SET status = 'deleted', remote_id = $1, base_revision = $2, server_json = NULL, local_deleted = 0, updated_at = $3 WHERE uuid = $4`,
          params: [remoteId ?? null, snap.revision, now(), uuid],
        },
        {
          sql: `INSERT INTO outbox (op_id, uuid, entity, op, payload_json, base_revision, created_at) VALUES ($1, $2, $3, 'delete', NULL, $4, $5)`,
          params: [newId(), uuid, rec.entity, snap.revision, now()],
        },
      );
    } else {
      stmts.push({
        sql: `UPDATE records SET status = 'synced', remote_id = $1, data_json = $2, base_revision = $3, server_json = NULL, local_deleted = 0, updated_at = $4 WHERE uuid = $5`,
        params: [remoteId ?? null, JSON.stringify(snap.data), snap.revision, now(), uuid],
      });
    }
    await repo.runTx(stmts);
    return;
  }

  // field-level merge
  const serverData = snap.data;
  const final: RecordData = { ...serverData };
  for (const [key, def] of Object.entries(entity.schema)) {
    if (def.readonly) continue; // computed by the server: always take its value
    const choice: Choice = resolution === 'mine' ? { pick: 'mine' } : resolution === 'server' ? { pick: 'server' } : (resolution[key] ?? { pick: defaultPick(rec.data[key], serverData[key]) });
    if (choice.pick === 'mine') final[key] = rec.data[key];
    else if (choice.pick === 'manual') final[key] = choice.value;
    else final[key] = serverData[key];
  }
  // keep any non-schema keys the server sent
  const changed: RecordData = {};
  for (const [key, def] of Object.entries(entity.schema)) {
    if (def.readonly || def.create_only) continue;
    if (!same(final[key], serverData[key])) changed[key] = final[key];
  }
  const hasChange = Object.keys(changed).length > 0;
  stmts.push(
    clearOps,
    {
      sql: `UPDATE records SET data_json = $1, base_revision = $2, remote_id = $3, server_json = NULL, local_deleted = 0, status = $4, updated_at = $5 WHERE uuid = $6`,
      params: [JSON.stringify(final), snap.revision, remoteId ?? null, hasChange ? 'dirty' : 'synced', now(), uuid],
    },
  );
  if (hasChange) {
    stmts.push({
      sql: `INSERT INTO outbox (op_id, uuid, entity, op, payload_json, base_revision, created_at) VALUES ($1, $2, $3, 'update', $4, $5, $6)`,
      params: [newId(), uuid, rec.entity, JSON.stringify(changed), snap.revision, now()],
    });
  }
  await repo.runTx(stmts);
}

/** POST /undo for every batch of a sync-log row (newest first). Admin only on the server. */
export async function undoSyncBatch(repo: SiteRepo, api: SyncApi, row: SyncLogRow): Promise<{ restored: number; errors: number }> {
  const ids = (row.batch_id ?? '').split(',').filter(Boolean).reverse();
  let restored = 0;
  let errors = 0;
  for (const id of ids) {
    const r = await api.undo(id);
    restored += r.restored;
    errors += r.errors.length;
  }
  await repo.runTx([{ sql: `UPDATE sync_log SET undone_at = $1 WHERE id = $2`, params: [now(), row.id] }]);
  return { restored, errors };
}
