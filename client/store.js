// Local records and the outgoing operation queue.
import * as db from './db.js';

export const newId = () => crypto.randomUUID();
export const recKey = (entity, uuid) => `${entity}:${uuid}`;
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// Empty inputs (null, empty string, empty object) count as "not set", so untouched fields never travel.
export function norm(v) {
	if (v === null || v === undefined || v === '') return undefined;
	if (Array.isArray(v)) return v.length ? v : undefined;
	if (typeof v === 'object') {
		const out = {};
		for (const [k, x] of Object.entries(v)) {
			const n = norm(x);
			if (n !== undefined) out[k] = n;
		}
		return Object.keys(out).length ? out : undefined;
	}
	return v;
}

export function diff(base, next) {
	const out = {};
	for (const k of Object.keys(next)) {
		if (same(norm(next[k]), norm((base || {})[k]))) continue;
		const n = norm(next[k]);
		out[k] = n === undefined ? (typeof next[k] === 'object' && next[k] !== null ? {} : '') : n;
	}
	return out;
}

export const effective = (r) => r.local || r.data || {};

function writable(data, schema) {
	return Object.fromEntries(Object.entries(data).filter(([k]) => !(schema[k] && schema[k].readonly)));
}

function createData(data, schema) {
	return Object.fromEntries(Object.entries(writable(data, schema)).map(([k, v]) => [k, norm(v)]).filter(([, v]) => v !== undefined));
}

async function opsFor(r) {
	return (await db.all('ops')).filter((o) => o.entity === r.entity && o.uuid === r.uuid);
}

export async function listRecords(entity) {
	return (await db.byEntity(entity)).filter((r) => !r.removing);
}

// Save form data (new record when r is null) and queue the change.
export async function saveRecord(entity, r, formData, schema) {
	if (!r) {
		const id = newId();
		r = { key: recKey(entity, id), entity, uuid: id, remote_id: null, revision: 0, data: {}, local: formData };
	} else {
		r.local = formData;
	}
	r.error = null;
	await db.put('records', r);
	await queueRecord(r, schema);
	return r;
}

// Turn a record's unsent changes into one queued op (replacing an unsent op for the same record).
export async function queueRecord(r, schema) {
	const ops = await opsFor(r);
	const unsent = ops.find((o) => !o.batch_id);
	const sentCreate = ops.find((o) => o.batch_id && o.op === 'create');
	if (r.removing || sentCreate) return; // handled after the sent batch is settled
	const full = r.local || r.data;

	if (r.remote_id == null) {
		const op = unsent || { op_id: newId(), entity: r.entity, uuid: r.uuid };
		op.op = 'create';
		op.data = createData(full, schema);
		await db.put('ops', op);
		return;
	}
	const data = writable(diff(r.data, full), schema);
	if (!Object.keys(data).length) {
		if (unsent) await db.del('ops', unsent.op_id);
		r.local = null;
		await db.put('records', r);
		return;
	}
	const op = unsent || { op_id: newId(), entity: r.entity, uuid: r.uuid };
	Object.assign(op, { op: 'update', remote_id: r.remote_id, base_revision: r.revision, data });
	await db.put('ops', op);
}

export async function deleteRecord(r, force = false) {
	const ops = await opsFor(r);
	for (const o of ops.filter((x) => !x.batch_id)) await db.del('ops', o.op_id);
	const sent = ops.some((o) => o.batch_id);
	if (r.remote_id == null && !sent) {
		await db.del('records', r.key);
		return;
	}
	r.removing = true;
	r.force = force;
	await db.put('records', r);
	await queueDelete(r);
}

export async function queueDelete(r) {
	if (r.remote_id == null) return;
	const ops = await opsFor(r);
	if (ops.some((o) => o.op === 'delete')) return;
	await db.put('ops', { op_id: newId(), op: 'delete', entity: r.entity, uuid: r.uuid, remote_id: r.remote_id, base_revision: r.revision, force: !!r.force });
}

// After a batch settled: queue whatever changed locally in the meantime.
export async function requeueAll(schemas) {
	for (const r of await db.all('records')) {
		const schema = schemas[r.entity] || {};
		if (r.removing) await queueDelete(r);
		else if (r.error || r.conflict) continue;
		else if (r.local || r.remote_id == null) await queueRecord(r, schema);
	}
}

export async function pendingCount() {
	return (await db.all('ops')).length;
}

// Label shown in lists.
export function labelOf(r, schema) {
	const d = effective(r);
	for (const k of ['title', 'name', 'label', 'subject']) {
		if (d[k]) return String(d[k]);
	}
	for (const [k, f] of Object.entries(schema || {})) {
		if ((f.type === 'string' || f.type === 'text') && d[k]) return String(d[k]);
	}
	return r.remote_id != null ? `#${r.remote_id}` : r.uuid.slice(0, 8);
}

export function statusOf(r, ops) {
	if (r.conflict) return 'conflict';
	if (r.error) return 'error';
	if (ops.some((o) => o.entity === r.entity && o.uuid === r.uuid) || r.local) return 'pending';
	return 'synced';
}
