// Pull / push loops following docs/api-contract.md section 8.
import * as db from './db.js';
import { diff, newId, recKey, requeueAll } from './store.js';

const CHUNK = 100;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function loadMeta(api) {
	const [hello, ents] = await Promise.all([api.handshake(), api.entities()]);
	const schemas = {};
	for (const e of ents.entities) schemas[e.key] = e.schema;
	await db.put('meta', hello, 'hello');
	await db.put('meta', ents.entities, 'entities');
	await db.put('meta', schemas, 'schemas');
	return { hello, entities: ents.entities, schemas };
}

export async function cachedMeta() {
	const [hello, entities, schemas] = await Promise.all([db.get('meta', 'hello'), db.get('meta', 'entities'), db.get('meta', 'schemas')]);
	return hello ? { hello, entities, schemas } : null;
}

// ---- pull ---------------------------------------------------------------

async function applyItem(item, busy) {
	if (!item.uuid) return;
	const key = recKey(item.entity, item.uuid);
	const existing = await db.get('records', key);

	if (item.deleted) {
		if (existing) await db.del('records', key);
		return;
	}
	if (existing && (existing.local || busy.has(key) || existing.conflict)) return; // settled at push time
	if (existing && existing.revision > item.revision) return;
	await db.put('records', {
		key,
		entity: item.entity,
		uuid: item.uuid,
		remote_id: item.remote_id,
		revision: item.revision,
		data: item.data || {},
		local: null,
		removing: false,
	});
}

export async function pull(api, onProgress = () => {}) {
	let since = (await db.get('meta', 'cursor')) || 0;
	let guard = 0;
	let total = 0;
	for (;;) {
		const busy = new Set((await db.all('ops')).map((o) => recKey(o.entity, o.uuid)));
		const res = await api.pull(since);
		for (const item of res.items) await applyItem(item, busy);
		total += res.items.length;
		since = res.next_cursor;
		await db.put('meta', since, 'cursor');
		onProgress(total);
		if (!res.has_more) break;
		if (res.items.length === 0) await sleep(300); // baseline still walking
		if (++guard > 5000) break;
	}
	return total;
}

// ---- push ---------------------------------------------------------------

const wireOp = (o) => {
	const out = { op_id: o.op_id, op: o.op, entity: o.entity };
	if (o.op === 'create') Object.assign(out, { uuid: o.uuid, data: o.data });
	else out.remote_id = o.remote_id;
	if (o.op !== 'create') out.base_revision = o.base_revision;
	if (o.op === 'update') out.data = o.data;
	if (o.op === 'delete') out.force = !!o.force;
	return out;
};

async function settle(op, res) {
	await db.del('ops', op.op_id);
	const key = recKey(op.entity, op.uuid);
	const r = await db.get('records', key);
	if (!r) return;

	if (res.status === 'applied') {
		if (op.op === 'delete') {
			await db.del('records', key);
			return;
		}
		r.remote_id = res.remote_id ?? r.remote_id;
		r.revision = res.revision;
		r.data = op.op === 'create' ? { ...op.data } : { ...r.data, ...op.data };
		r.error = null;
		r.conflict = null;
		if (r.local && !Object.keys(diff(r.data, r.local)).length) r.local = null;
	} else if (res.status === 'conflict') {
		if (res.reason === 'unique' && op.op === 'create') {
			// Another record already owns the value: bind to it, the edit is re-sent as an update.
			r.remote_id = res.remote_id;
			r.revision = res.revision;
			r.data = (res.server && res.server.data) || {};
			r.conflict = null;
		} else {
			r.removing = false;
			r.conflict = { kind: op.op, reason: res.reason || 'revision', server: res.server || null, revision: res.revision, mine: op.data || null };
		}
	} else {
		r.removing = false;
		r.error = res.error || { message: 'Rejected' };
	}
	await db.put('records', r);
}

async function sendBatch(api, batchId, ops) {
	const res = await api.push(batchId, ops.map(wireOp));
	const byId = new Map(ops.map((o) => [o.op_id, o]));
	let applied = 0;
	for (const result of res.results) {
		const op = byId.get(result.op_id);
		if (!op) continue;
		if (result.status === 'applied') applied++;
		await settle(op, result);
	}
	await db.put('batches', { batch_id: batchId, at: Date.now(), total: ops.length, applied });
	return res;
}

const RANK = { create: 0, update: 1, delete: 2 };

export async function push(api, schemas, onProgress = () => {}) {
	let sent = 0;

	// 1. A batch that was cut off by a network failure: resend with the same ids.
	const sentOps = (await db.all('ops')).filter((o) => o.batch_id);
	const batches = new Map();
	for (const o of sentOps) batches.set(o.batch_id, [...(batches.get(o.batch_id) || []), o]);
	for (const [id, ops] of batches) {
		await sendBatch(api, id, ops);
		sent += ops.length;
		onProgress(sent);
	}

	// 2. Changes made while that was in flight, then new batches.
	await requeueAll(schemas);
	for (;;) {
		const fresh = (await db.all('ops')).filter((o) => !o.batch_id).sort((a, b) => RANK[a.op] - RANK[b.op]).slice(0, CHUNK);
		if (!fresh.length) break;
		const id = newId();
		for (const o of fresh) {
			o.batch_id = id;
			await db.put('ops', o);
		}
		await sendBatch(api, id, fresh);
		sent += fresh.length;
		onProgress(sent);
		await requeueAll(schemas);
	}
	return sent;
}

let running = false;
// Push local changes, then pull everyone's.
export async function syncAll(api, schemas, onProgress = () => {}) {
	if (running) return null;
	running = true;
	try {
		const pushed = await push(api, schemas, (n) => onProgress(`push ${n}`));
		const pulled = await pull(api, (n) => onProgress(`pull ${n}`));
		return { pushed, pulled };
	} finally {
		running = false;
	}
}

// ---- conflict resolution -----------------------------------------------------

// Keep my edit: take the server's current revision as the base and re-send.
export async function keepMine(r) {
	const c = r.conflict;
	if (!c) return;
	r.revision = c.revision;
	if (c.server && !c.server.deleted) r.data = c.server.data || r.data;
	if (c.kind === 'delete') {
		r.removing = true;
	} else if (!r.local) {
		r.local = { ...r.data, ...(c.mine || {}) };
	}
	r.conflict = null;
	await db.put('records', r);
}

// Take the server's version, drop my edit.
export async function useServer(r) {
	const c = r.conflict;
	if (!c) return;
	if (c.server && c.server.deleted) {
		await db.del('records', r.key);
		return;
	}
	r.revision = c.revision;
	r.data = (c.server && c.server.data) || r.data;
	r.local = null;
	r.removing = false;
	r.conflict = null;
	await db.put('records', r);
}
