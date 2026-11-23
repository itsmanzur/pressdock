import type { OutboxOp } from '../db/repo';
import type { Entity, RecordData } from '../schema/types';

export interface CollapseResult {
  /** ops to send this round (rewritten where merged) */
  send: OutboxOp[];
  /** outbox rows to delete (merged away or cancelled) */
  dropOpIds: string[];
  /** rewritten rows whose payload changed and must be saved back before sending */
  rewrites: OutboxOp[];
  /** uuids whose create+delete cancelled out: local row can be removed if never on the server */
  cancelled: string[];
}

const parse = (p: string | null): RecordData => (p ? (JSON.parse(p) as RecordData) : {});
const isFrozen = (o: OutboxOp) => o.attempts > 0 && !o.last_error;

/**
 * Collapse redundant ops per record, keeping creation order between records.
 *  - create + update*      -> create with final data
 *  - create + ... + delete -> nothing (record never existed on the server)
 *  - update + update       -> one update (merged payload)
 *  - update* + delete      -> delete
 * An op that was already sent but whose outcome is unknown ("frozen") is never rewritten
 * (the server stored its result by op_id); only that op goes out this round, later ops wait.
 */
export function collapseOps(ops: OutboxOp[]): CollapseResult {
  const byUuid = new Map<string, OutboxOp[]>();
  for (const o of ops) byUuid.set(o.uuid, [...(byUuid.get(o.uuid) ?? []), o]);

  const send: OutboxOp[] = [];
  const dropOpIds: string[] = [];
  const rewrites: OutboxOp[] = [];
  const cancelled: string[] = [];

  for (const [uuid, list] of byUuid) {
    if (isFrozen(list[0])) {
      send.push(list[0]);
      continue;
    }
    const hasCreate = list[0].op === 'create';
    const last = list[list.length - 1];

    if (hasCreate && last.op === 'delete') {
      list.forEach((o) => dropOpIds.push(o.op_id));
      cancelled.push(uuid);
      continue;
    }
    if (last.op === 'delete') {
      // keep only the delete (earlier updates are moot)
      list.slice(0, -1).forEach((o) => dropOpIds.push(o.op_id));
      send.push(last);
      continue;
    }
    // merge payloads into the first op
    const first = list[0];
    let payload = parse(first.payload_json);
    for (const o of list.slice(1)) payload = { ...payload, ...parse(o.payload_json) };
    const merged: OutboxOp = { ...first, payload_json: JSON.stringify(payload), last_error: null };
    if (list.length > 1 || first.last_error) {
      rewrites.push(merged);
      list.slice(1).forEach((o) => dropOpIds.push(o.op_id));
    }
    send.push(merged);
  }

  // keep global creation order of the surviving first ops
  const order = new Map(ops.map((o, i) => [o.op_id, i]));
  send.sort((a, b) => (order.get(a.op_id) ?? 0) - (order.get(b.op_id) ?? 0));
  return { send, dropOpIds, rewrites, cancelled };
}

/**
 * Order so that a `create` precedes ops that reference its uuid through a relation field
 * (Kahn's algorithm, stable). Cycles fall back to the original order.
 */
export function orderByDependency(ops: OutboxOp[], entities: Map<string, Entity>): OutboxOp[] {
  const createByUuid = new Map<string, OutboxOp>();
  for (const o of ops) if (o.op === 'create') createByUuid.set(o.uuid, o);

  const deps = new Map<string, Set<string>>(); // op_id -> op_ids it needs first
  for (const o of ops) {
    const need = new Set<string>();
    if (o.op !== 'delete') {
      const schema = entities.get(o.entity)?.schema ?? {};
      const payload = parse(o.payload_json);
      for (const [k, def] of Object.entries(schema)) {
        if (def.type !== 'relation') continue;
        const v = payload[k];
        if (typeof v === 'string' && createByUuid.has(v) && createByUuid.get(v)!.op_id !== o.op_id) {
          need.add(createByUuid.get(v)!.op_id);
        }
      }
      // an update of a record whose create is also queued must follow that create
      if (o.op === 'update' && createByUuid.has(o.uuid)) need.add(createByUuid.get(o.uuid)!.op_id);
    }
    deps.set(o.op_id, need);
  }

  const out: OutboxOp[] = [];
  const done = new Set<string>();
  const remaining = [...ops];
  while (remaining.length) {
    const idx = remaining.findIndex((o) => [...(deps.get(o.op_id) ?? [])].every((d) => done.has(d) || !remaining.some((r) => r.op_id === d)));
    if (idx === -1) {
      out.push(...remaining); // cycle: keep request order, the server also falls back to it
      break;
    }
    const [o] = remaining.splice(idx, 1);
    out.push(o);
    done.add(o.op_id);
  }
  return out;
}
