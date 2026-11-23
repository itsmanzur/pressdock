import type { SiteRepo, OutboxOp } from '../db/repo';
import { newId } from '../db/repo';
import type { Stmt } from '../platform/types';
import type { Entity, LocalRecord, RecordData, ServerSnapshot } from '../schema/types';
import { collapseOps, orderByDependency } from './collapse';
import {
  CONFIRM_THRESHOLD,
  DEFAULT_BATCH,
  type PullItem,
  type PushOp,
  type PushResult,
  type SyncApi,
  type SyncPlan,
  type SyncSummary,
} from './types';

export interface EngineOptions {
  siteId: string;
  repo: SiteRepo;
  api: SyncApi;
  batchSize?: number;
  /** retries for network / 5xx errors (same op_ids every time) */
  maxRetries?: number;
  sleep?: (ms: number) => Promise<void>;
  onProgress?: (text: string) => void;
}

export interface RunOptions {
  /** called when the plan contains deletes or > CONFIRM_THRESHOLD changes; return false to cancel */
  confirm?: (plan: SyncPlan) => Promise<boolean>;
}

/** One sync at a time per site (module-level so UI + scheduler share it). */
const running = new Set<string>();
export const isSyncing = (siteId: string) => running.has(siteId);

const defaultSleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

function isRetryable(e: unknown): boolean {
  const x = e as { kind?: string; status?: number };
  return x?.kind === 'network' || x?.kind === 'timeout' || (typeof x?.status === 'number' && x.status >= 500);
}

const sameJson = (a: unknown, b: unknown) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);

export class SyncEngine {
  private readonly repo: SiteRepo;
  private readonly api: SyncApi;
  private readonly sleep: (ms: number) => Promise<void>;
  private readonly retries: number;

  constructor(private readonly opts: EngineOptions) {
    this.repo = opts.repo;
    this.api = opts.api;
    this.sleep = opts.sleep ?? defaultSleep;
    this.retries = opts.maxRetries ?? 4;
  }

  private async retry<T>(fn: () => Promise<T>): Promise<T> {
    let delay = 1000;
    for (let attempt = 0; ; attempt++) {
      try {
        return await fn();
      } catch (e) {
        if (attempt >= this.retries || !isRetryable(e)) throw e;
        await this.sleep(Math.min(delay, 30_000));
        delay *= 2;
      }
    }
  }

  private async entityMap(): Promise<Map<string, Entity>> {
    return new Map((await this.repo.listEntities()).map((e) => [e.key, e]));
  }

  // ------------------------------------------------------------------ plan

  /** What a push would send (after collapsing), for the preview dialog. Read-only. */
  async plan(): Promise<SyncPlan> {
    const { send } = collapseOps(await this.repo.listOutbox());
    const groups = new Map<string, SyncPlan['groups'][number]>();
    let creates = 0;
    let updates = 0;
    let deletes = 0;
    for (const o of send) {
      const g = groups.get(o.entity) ?? { entity: o.entity, creates: 0, updates: 0, deletes: 0, samples: [] };
      if (o.op === 'create') (g.creates++, creates++);
      else if (o.op === 'update') (g.updates++, updates++);
      else (g.deletes++, deletes++);
      if (g.samples.length < 5) g.samples.push({ op: o.op, uuid: o.uuid });
      groups.set(o.entity, g);
    }
    const total = creates + updates + deletes;
    return {
      total,
      creates,
      updates,
      deletes,
      groups: [...groups.values()],
      needsConfirm: deletes > 0 || total > CONFIRM_THRESHOLD,
    };
  }

  // ------------------------------------------------------------------- run

  async run(run: RunOptions = {}): Promise<SyncSummary> {
    const { siteId } = this.opts;
    const summary: SyncSummary = { pushed: 0, pulled: 0, conflicts: 0, errors: 0, batchIds: [] };
    if (running.has(siteId)) return { ...summary, aborted: 'busy' };
    running.add(siteId);
    let logId: number | null = null;
    try {
      let info;
      try {
        info = await this.retry(() => this.api.handshake());
      } catch (e) {
        return { ...summary, aborted: 'offline', message: errText(e) };
      }
      const batchSize = Math.max(1, Math.min(this.opts.batchSize ?? DEFAULT_BATCH, info.limits?.max_ops ?? DEFAULT_BATCH));

      // safety preview BEFORE anything is sent
      const plan = await this.plan();
      if (plan.total > 0 && plan.needsConfirm) {
        const ok = run.confirm ? await run.confirm(plan) : false;
        if (!ok) return { ...summary, aborted: 'cancelled' };
      }

      await this.repo.db.execute(`INSERT INTO sync_log (started_at) VALUES ($1)`, [new Date().toISOString()]);
      logId = Number((await this.repo.db.select<{ id: number }>('SELECT MAX(id) AS id FROM sync_log'))[0].id);

      try {
        await this.push(summary, batchSize);
        await this.pull(summary, Math.min(500, info.limits?.pull_max ?? 500));
      } catch (e) {
        summary.aborted = isRetryable(e) ? 'offline' : 'failed';
        summary.message = errText(e);
      }
      await this.finishLog(logId, summary);
      return summary;
    } finally {
      running.delete(siteId);
      this.repo.notify();
    }
  }

  private async finishLog(id: number, s: SyncSummary) {
    await this.repo.db.execute(
      `UPDATE sync_log SET finished_at = $1, pushed = $2, pulled = $3, conflicts = $4, errors = $5, batch_id = $6, error = $7 WHERE id = $8`,
      [new Date().toISOString(), s.pushed, s.pulled, s.conflicts, s.errors, s.batchIds.join(',') || null, s.message ?? null, id],
    );
  }

  // ------------------------------------------------------------------ push

  private async push(summary: SyncSummary, batchSize: number): Promise<void> {
    const entities = await this.entityMap();
    const skip = new Set<string>(); // uuids that errored/conflicted in this run
    for (let round = 0; round < 6; round++) {
      const all = (await this.repo.listOutbox()).filter((o) => !skip.has(o.uuid));
      if (all.length === 0) return;
      const col = collapseOps(all);
      await this.persistCollapse(col.dropOpIds, col.rewrites, col.cancelled);
      const ordered = orderByDependency(col.send, entities);
      if (ordered.length === 0) return;

      let progressed = false;
      for (let i = 0; i < ordered.length; i += batchSize) {
        const chunk = ordered.slice(i, i + batchSize);
        const batchId = chunk.find((o) => o.batch_id && o.attempts > 0 && !o.last_error)?.batch_id ?? newId();
        const r = await this.sendBatch(batchId, chunk, summary);
        if (r.applied) progressed = true;
        r.blocked.forEach((u) => skip.add(u));
        this.opts.onProgress?.(`push ${Math.min(i + batchSize, ordered.length)}/${ordered.length}`);
      }
      if (!progressed) return;
    }
  }

  private async persistCollapse(drop: string[], rewrites: OutboxOp[], cancelled: string[]) {
    const stmts: Stmt[] = [];
    for (const id of drop) stmts.push({ sql: `DELETE FROM outbox WHERE op_id = $1`, params: [id] });
    for (const o of rewrites) {
      stmts.push({
        sql: `UPDATE outbox SET payload_json = $1, last_error = NULL WHERE op_id = $2`,
        params: [o.payload_json, o.op_id],
      });
    }
    for (const u of cancelled) {
      // create+delete: never reached the server
      stmts.push({ sql: `DELETE FROM records WHERE uuid = $1 AND remote_id IS NULL`, params: [u] });
    }
    if (stmts.length) await this.repo.runTx(stmts);
  }

  private async sendBatch(batchId: string, chunk: OutboxOp[], summary: SyncSummary): Promise<{ applied: number; blocked: string[] }> {
    const recs = new Map((await this.repo.getRecords([...new Set(chunk.map((o) => o.uuid))])).map((r) => [r.uuid, r]));
    const sent: { op: OutboxOp; rec: LocalRecord }[] = [];
    const orphan: string[] = [];
    for (const op of chunk) {
      const rec = recs.get(op.uuid);
      if (!rec) orphan.push(op.op_id);
      else sent.push({ op, rec });
    }
    if (orphan.length) await this.repo.runTx(orphan.map((id) => ({ sql: `DELETE FROM outbox WHERE op_id = $1`, params: [id] })));
    if (sent.length === 0) return { applied: 0, blocked: [] };

    // stamp BEFORE sending: a crash from here on resends the same op_ids/batch (server is idempotent)
    await this.repo.runTx(
      sent.map(({ op }) => ({
        sql: `UPDATE outbox SET attempts = attempts + 1, last_error = NULL, batch_id = $1 WHERE op_id = $2`,
        params: [batchId, op.op_id],
      })),
    );

    const wire = sent.map(({ op, rec }) => toWire(op, rec));
    const resp = await this.retry(() => this.api.push(batchId, wire));
    if (!summary.batchIds.includes(batchId)) summary.batchIds.push(batchId);

    const results = new Map(resp.results.map((r) => [r.op_id, r]));
    const stmts: Stmt[] = [];
    const blocked: string[] = [];
    let applied = 0;
    for (const { op, rec } of sent) {
      const res = results.get(op.op_id);
      if (!res) continue;
      if (res.status === 'applied') {
        applied++;
        summary.pushed++;
        stmts.push(...applyOk(op, rec, res));
      } else if (res.status === 'conflict') {
        summary.conflicts++;
        blocked.push(op.uuid);
        stmts.push(...applyConflict(op, res));
      } else {
        summary.errors++;
        blocked.push(op.uuid);
        const e = res.error;
        stmts.push({
          sql: `UPDATE outbox SET last_error = $1 WHERE op_id = $2`,
          params: [e ? `${e.code}: ${e.message}${e.field ? ` (${e.field})` : ''}` : 'unknown error', op.op_id],
        });
      }
    }
    await this.repo.runTx(stmts);
    return { applied, blocked };
  }

  // ------------------------------------------------------------------ pull

  private async pull(summary: SyncSummary, limit: number): Promise<void> {
    const entities = (await this.repo.listEntities()).filter((e) => e.actions.read);
    for (const e of entities) {
      let since = e.cursor ?? 0;
      for (let guard = 0; guard < 100_000; guard++) {
        const page = await this.retry(() => this.api.pull(e.key, since, limit));
        const stmts = await this.pageStatements(e.key, page.items, summary);
        // cursor is saved in the SAME transaction as the page it belongs to
        stmts.push({ sql: `UPDATE entities SET cursor = $1 WHERE key = $2`, params: [page.next_cursor, e.key] });
        await this.repo.runTx(stmts);
        this.opts.onProgress?.(`pull ${e.label} ${summary.pulled}`);
        since = page.next_cursor;
        if (!page.has_more) break;
      }
    }
  }

  private async pageStatements(entity: string, items: PullItem[], summary: SyncSummary): Promise<Stmt[]> {
    if (items.length === 0) return [];
    const byUuid = new Map<string, LocalRecord>();
    const byRemote = new Map<number, LocalRecord>();
    for (const r of await this.repo.getRecords(items.map((i) => i.uuid))) byUuid.set(r.uuid, r);
    const remoteIds = items.map((i) => i.remote_id);
    for (const r of await this.repo.recordsByRemoteIds(entity, remoteIds)) if (r.remote_id != null) byRemote.set(r.remote_id, r);

    const stmts: Stmt[] = [];
    const ts = new Date().toISOString();
    for (const it of items) {
      const local = byUuid.get(it.uuid) ?? byRemote.get(it.remote_id);
      const snap: ServerSnapshot = { entity, remote_id: it.remote_id, uuid: it.uuid, revision: it.revision, deleted: it.deleted, data: it.data };
      const dropOps: Stmt = { sql: `DELETE FROM outbox WHERE uuid = $1`, params: [local?.uuid ?? it.uuid] };
      const markConflict = (deletedIntent: boolean): Stmt[] => [
        dropOps,
        {
          sql: `UPDATE records SET status = 'conflict', server_json = $1, local_deleted = $2, updated_at = $3 WHERE uuid = $4`,
          params: [JSON.stringify(snap), deletedIntent ? 1 : 0, ts, local!.uuid],
        },
      ];

      // echo of our own push / already known. A clean record at the same revision is still refreshed when the server
      // data differs (server-computed fields of a record we just created).
      if (local && local.remote_id != null && it.revision <= local.base_revision && local.status !== 'conflict') {
        if (local.status !== 'synced' || (it.revision === local.base_revision && sameJson(local.data, it.data ?? {}))) continue;
      }

      if (it.deleted) {
        if (!local || local.status === 'new') continue;
        if (local.status === 'synced') {
          stmts.push({ sql: `DELETE FROM records WHERE uuid = $1`, params: [local.uuid] });
        } else if (local.status === 'deleted') {
          stmts.push(dropOps, { sql: `DELETE FROM records WHERE uuid = $1`, params: [local.uuid] });
        } else if (local.status === 'conflict') {
          stmts.push({ sql: `UPDATE records SET server_json = $1 WHERE uuid = $2`, params: [JSON.stringify(snap), local.uuid] });
          continue;
        } else {
          stmts.push(...markConflict(false));
          summary.conflicts++;
        }
        summary.pulled++;
        continue;
      }

      const data = JSON.stringify(it.data ?? {});
      if (!local) {
        stmts.push({
          sql: `INSERT INTO records (uuid, entity, remote_id, data_json, base_revision, server_json, status, updated_at)
                VALUES ($1, $2, $3, $4, $5, NULL, 'synced', $6)`,
          params: [it.uuid, entity, it.remote_id, data, it.revision, ts],
        });
      } else if (local.status === 'synced') {
        stmts.push({
          sql: `UPDATE records SET remote_id = $1, data_json = $2, base_revision = $3, server_json = NULL, updated_at = $4 WHERE uuid = $5`,
          params: [it.remote_id, data, it.revision, ts, local.uuid],
        });
      } else if (local.status === 'conflict') {
        stmts.push({ sql: `UPDATE records SET server_json = $1 WHERE uuid = $2`, params: [JSON.stringify(snap), local.uuid] });
        continue;
      } else if (local.status === 'deleted') {
        stmts.push(...markConflict(true));
        summary.conflicts++;
      } else if (local.status === 'dirty' && sameJson(local.data, it.data ?? {})) {
        // both sides ended up identical: nothing to resolve
        stmts.push(dropOps, {
          sql: `UPDATE records SET status = 'synced', remote_id = $1, base_revision = $2, server_json = NULL WHERE uuid = $3`,
          params: [it.remote_id, it.revision, local.uuid],
        });
      } else if (local.status === 'dirty') {
        stmts.push(...markConflict(false));
        summary.conflicts++;
      } else {
        continue; // 'new' matched by uuid: leave for the push path
      }
      summary.pulled++;
    }
    return stmts;
  }
}

// -------------------------------------------------------------- helpers

function errText(e: unknown): string {
  return e instanceof Error ? e.message : String(e);
}

function toWire(op: OutboxOp, rec: LocalRecord): PushOp {
  const payload = op.payload_json ? (JSON.parse(op.payload_json) as RecordData) : undefined;
  if (op.op === 'create') return { op_id: op.op_id, op: 'create', entity: op.entity, uuid: op.uuid, data: payload ?? {} };
  const id = rec.remote_id != null ? { remote_id: rec.remote_id } : { uuid: op.uuid };
  if (op.op === 'update') {
    return { op_id: op.op_id, op: 'update', entity: op.entity, ...id, base_revision: op.base_revision ?? rec.base_revision, data: payload ?? {} };
  }
  return { op_id: op.op_id, op: 'delete', entity: op.entity, ...id, base_revision: op.base_revision ?? rec.base_revision, force: false };
}

function applyOk(op: OutboxOp, rec: LocalRecord, res: PushResult): Stmt[] {
  const stmts: Stmt[] = [{ sql: `DELETE FROM outbox WHERE op_id = $1`, params: [op.op_id] }];
  if (op.op === 'delete') {
    stmts.push({ sql: `DELETE FROM outbox WHERE uuid = $1`, params: [op.uuid] }, { sql: `DELETE FROM records WHERE uuid = $1`, params: [op.uuid] });
    return stmts;
  }
  const remoteId = res.remote_id ?? rec.remote_id;
  const revision = res.revision ?? rec.base_revision;
  stmts.push(
    {
      sql: `UPDATE records SET remote_id = $1, base_revision = $2, server_json = NULL,
              status = CASE WHEN status IN ('deleted','conflict') THEN status
                            WHEN EXISTS (SELECT 1 FROM outbox WHERE uuid = $3) THEN 'dirty'
                            ELSE 'synced' END
            WHERE uuid = $4`,
      params: [remoteId ?? null, revision, op.uuid, op.uuid],
    },
    // later ops of this record were written against the old revision
    { sql: `UPDATE outbox SET base_revision = $1 WHERE uuid = $2 AND op IN ('update','delete')`, params: [revision, op.uuid] },
  );
  return stmts;
}

function applyConflict(op: OutboxOp, res: PushResult): Stmt[] {
  const snap: ServerSnapshot = {
    ...(res.server ?? { remote_id: res.remote_id ?? null, revision: res.revision ?? 0, deleted: false, data: null }),
    remote_id: res.server?.remote_id ?? res.remote_id ?? null,
    revision: res.server?.revision ?? res.revision ?? 0,
  };
  if (res.reason) {
    snap._reason = res.reason;
    snap._field = res.field;
  }
  return [
    // the op is NOT retried; the resolution creates a fresh op with the server revision
    { sql: `DELETE FROM outbox WHERE uuid = $1`, params: [op.uuid] },
    {
      sql: `UPDATE records SET status = 'conflict', server_json = $1, local_deleted = $2 WHERE uuid = $3`,
      params: [JSON.stringify(snap), op.op === 'delete' ? 1 : 0, op.uuid],
    },
  ];
}

export { CONFIRM_THRESHOLD };
