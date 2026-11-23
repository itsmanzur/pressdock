import type { RecordData, ServerSnapshot } from '../schema/types';
import type { HandshakeInfo, PullItem, PullResponse, PushOp, PushResponse, PushResult, SyncApi } from './types';

interface ServerRecord {
  remote_id: number;
  uuid: string;
  entity: string;
  data: RecordData;
  revision: number;
  deleted: boolean;
}
interface Change {
  cursor: number;
  remote_id: number;
}
interface Snapshot {
  batch: string;
  remote_id: number;
  before: ServerRecord | null; // null => the record was created by the batch
}

export interface MockSchema {
  [entity: string]: { required: string[]; relations: string[]; unique?: string };
}

/**
 * In-memory stand-in for the Pressdock Connector (docs/api-contract.md): revisions, conflicts,
 * idempotent op_ids, tombstones, relation-uuid resolution + ordering, paged pull, undo.
 * Test knobs: failNext / loseResponses / calls.
 */
export class MockConnector implements SyncApi {
  records = new Map<number, ServerRecord>();
  changes: Change[] = [];
  private cursor = 0;
  private nextId = 100;
  private ops = new Map<string, PushResult>();
  private snapshots: Snapshot[] = [];
  /** throw a network error on the next N calls of the given method */
  failNext: Record<string, number> = {};
  /** process the next N pushes on the server, but throw before the client sees the response */
  loseResponses = 0;
  /** throw a network error on the Nth pull call from now (1-based), once */
  failPullOn: number | null = null;
  private pullCalls = 0;
  pushLog: PushOp[][] = [];
  batchIds: string[] = [];

  constructor(public schema: MockSchema = {}) {}

  // ---- helpers for tests ("someone edits in wp-admin")
  seed(entity: string, data: RecordData, uuid = crypto.randomUUID()): ServerRecord {
    const r: ServerRecord = { remote_id: this.nextId++, uuid, entity, data: { ...data }, revision: 1, deleted: false };
    this.records.set(r.remote_id, r);
    this.touch(r);
    return r;
  }
  adminEdit(remoteId: number, patch: RecordData) {
    const r = this.records.get(remoteId)!;
    r.data = { ...r.data, ...patch };
    r.revision++;
    this.touch(r);
  }
  adminDelete(remoteId: number) {
    const r = this.records.get(remoteId)!;
    r.deleted = true;
    r.revision++;
    this.touch(r);
  }
  byUuid(uuid: string) {
    return [...this.records.values()].find((r) => r.uuid === uuid);
  }
  live(entity: string) {
    return [...this.records.values()].filter((r) => r.entity === entity && !r.deleted);
  }
  private touch(r: ServerRecord) {
    this.changes.push({ cursor: ++this.cursor, remote_id: r.remote_id });
  }
  private maybeFail(method: string) {
    if ((this.failNext[method] ?? 0) > 0) {
      this.failNext[method]--;
      throw Object.assign(new Error('network down'), { kind: 'network' });
    }
  }
  private snap(r: ServerRecord): ServerSnapshot {
    return { entity: r.entity, remote_id: r.remote_id, uuid: r.uuid, revision: r.revision, deleted: r.deleted, data: r.deleted ? null : { ...r.data } };
  }

  // ---- SyncApi
  async handshake(): Promise<HandshakeInfo> {
    this.maybeFail('handshake');
    return { site: { api_version: '1.0' }, limits: { max_ops: 100, pull_default: 200, pull_max: 500 }, cursor: this.cursor };
  }

  async push(batchId: string, ops: PushOp[]): Promise<PushResponse> {
    this.maybeFail('push');
    this.pushLog.push(ops);
    this.batchIds.push(batchId);
    // server runs an op after the create it references through a relation uuid
    const order = [...ops];
    const creates = new Map(ops.filter((o) => o.op === 'create').map((o) => [o.uuid!, o]));
    order.sort((a, b) => {
      const need = (o: PushOp) =>
        (this.schema[o.entity]?.relations ?? []).some((f) => typeof o.data?.[f] === 'string' && creates.get(o.data[f] as string) === a);
      return need(b) && !need(a) ? -1 : need(a) && !need(b) ? 1 : 0;
    });
    const out = new Map<string, PushResult>();
    for (const op of order) out.set(op.op_id, this.applyOp(batchId, op));
    const resp: PushResponse = { batch_id: batchId, results: ops.map((o) => out.get(o.op_id)!), cursor: this.cursor };
    if (this.loseResponses > 0) {
      this.loseResponses--;
      throw Object.assign(new Error('connection reset'), { kind: 'network' });
    }
    return resp;
  }

  private applyOp(batch: string, op: PushOp): PushResult {
    const stored = this.ops.get(op.op_id);
    if (stored) return { ...stored, replayed: true };
    const cfg = this.schema[op.entity] ?? { required: [], relations: [] };
    const err = (code: string, message: string, field?: string): PushResult => ({ op_id: op.op_id, status: 'error', error: { code, message, field } });
    const done = (r: PushResult) => (this.ops.set(op.op_id, r), r);

    if (op.op === 'create') {
      const existing = this.byUuid(op.uuid!);
      if (existing && !existing.deleted) return done({ op_id: op.op_id, status: 'applied', remote_id: existing.remote_id, revision: existing.revision });
      for (const f of cfg.required) if (op.data?.[f] === undefined || op.data[f] === '') return err('pdock_required', `${f} is required`, f);
      const data: RecordData = { ...(op.data ?? {}) };
      for (const f of cfg.relations) {
        const v = data[f];
        if (typeof v === 'string') {
          const t = this.byUuid(v);
          if (!t) return err('pdock_unresolved_relation', 'unknown relation', f);
          data[f] = t.remote_id;
        }
      }
      if (cfg.unique) {
        const dup = this.live(op.entity).find((r) => r.data[cfg.unique!] === data[cfg.unique!]);
        if (dup) return done({ op_id: op.op_id, status: 'conflict', reason: 'unique', field: cfg.unique, remote_id: dup.remote_id, revision: dup.revision, server: this.snap(dup) });
      }
      const r: ServerRecord = { remote_id: this.nextId++, uuid: op.uuid!, entity: op.entity, data, revision: 1, deleted: false };
      this.records.set(r.remote_id, r);
      this.touch(r);
      this.snapshots.push({ batch, remote_id: r.remote_id, before: null });
      return done({ op_id: op.op_id, status: 'applied', remote_id: r.remote_id, revision: 1 });
    }

    const rec = op.remote_id != null ? this.records.get(op.remote_id) : this.byUuid(op.uuid!);
    if (!rec) return err('pdock_not_found', 'not found');
    if (rec.deleted && op.op === 'delete' && op.base_revision === rec.revision) return done({ op_id: op.op_id, status: 'applied', remote_id: rec.remote_id, revision: rec.revision });
    if (rec.deleted || op.base_revision !== rec.revision) {
      return done({ op_id: op.op_id, status: 'conflict', remote_id: rec.remote_id, revision: rec.revision, server: this.snap(rec) });
    }
    this.snapshots.push({ batch, remote_id: rec.remote_id, before: { ...rec, data: { ...rec.data } } });
    if (op.op === 'delete') rec.deleted = true;
    else {
      const data: RecordData = { ...(op.data ?? {}) };
      for (const f of cfg.required) if (f in data && (data[f] === '' || data[f] == null)) return err('pdock_required', `${f} is required`, f);
      for (const f of cfg.relations) {
        if (typeof data[f] === 'string') {
          const t = this.byUuid(data[f] as string);
          if (!t) return err('pdock_unresolved_relation', 'unknown relation', f);
          data[f] = t.remote_id;
        }
      }
      rec.data = { ...rec.data, ...data };
    }
    rec.revision++;
    this.touch(rec);
    return done({ op_id: op.op_id, status: 'applied', remote_id: rec.remote_id, revision: rec.revision });
  }

  async pull(entity: string, since: number, limit: number): Promise<PullResponse> {
    this.maybeFail('pull');
    this.pullCalls++;
    if (this.failPullOn !== null && this.pullCalls === this.failPullOn) {
      this.failPullOn = null;
      throw Object.assign(new Error('network down'), { kind: 'network' });
    }
    const rows = this.changes.filter((c) => c.cursor > since && this.records.get(c.remote_id)!.entity === entity).slice(0, limit);
    const seen = new Set<number>();
    const items: PullItem[] = [];
    // collapse to latest state per record
    for (const c of [...rows].reverse()) {
      if (seen.has(c.remote_id)) continue;
      seen.add(c.remote_id);
      const r = this.records.get(c.remote_id)!;
      items.unshift({ entity, remote_id: r.remote_id, uuid: r.uuid, revision: r.revision, deleted: r.deleted, data: r.deleted ? null : { ...r.data }, cursor: c.cursor });
    }
    const last = rows[rows.length - 1];
    const more = this.changes.some((c) => c.cursor > (last?.cursor ?? since) && this.records.get(c.remote_id)!.entity === entity);
    return { items, next_cursor: last?.cursor ?? since, has_more: more };
  }

  async undo(batchId: string): Promise<{ restored: number; errors: unknown[] }> {
    const snaps = this.snapshots.filter((s) => s.batch === batchId).reverse();
    for (const s of snaps) {
      const r = this.records.get(s.remote_id)!;
      if (s.before === null) r.deleted = true;
      else Object.assign(r, { data: { ...s.before.data }, deleted: s.before.deleted });
      r.revision++;
      this.touch(r);
    }
    this.snapshots = this.snapshots.filter((s) => s.batch !== batchId);
    return { restored: snaps.length, errors: [] };
  }
}
