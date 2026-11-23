import { describe, expect, it } from 'vitest';
import { openMemoryDb } from '../platform/web';
import { SiteRepo } from '../db/repo';
import type { OutboxOp } from '../db/repo';
import type { Entity } from '../schema/types';
import { SyncEngine } from './engine';
import { collapseOps, orderByDependency } from './collapse';
import { diffFields, resolveConflict, undoSyncBatch } from './resolve';
import { MockConnector } from './mockConnector';

const actions = { read: true, create: true, update: true, delete: true };
const pkg: Entity = {
  key: 'pkg', label: 'Packages', group: 'Hejazer Kafela', cursor: null, actions,
  schema: {
    name: { type: 'string', required: true },
    price: { type: 'int' },
    note: { type: 'string' },
    computed: { type: 'string', readonly: true },
  },
};
const inq: Entity = {
  key: 'inq', label: 'Inquiries', group: 'Hejazer Kafela', cursor: null, actions,
  schema: { who: { type: 'string', required: true }, package: { type: 'relation', target: 'pkg' } },
};

async function setup(opts: { batchSize?: number } = {}) {
  const db = await openMemoryDb('s' + Math.random(), false);
  const repo = await SiteRepo.open(db);
  await repo.saveEntities([pkg, inq]);
  const server = new MockConnector({ pkg: { required: ['name'], relations: [] }, inq: { required: ['who'], relations: ['package'] } });
  const engine = new SyncEngine({ siteId: 's' + Math.random(), repo, api: server, sleep: async () => {}, batchSize: opts.batchSize });
  const sync = () => engine.run({ confirm: async () => true });
  return { repo, server, engine, sync };
}

const op = (id: string, uuid: string, kind: OutboxOp['op'], payload: object | null, extra: Partial<OutboxOp> = {}): OutboxOp => ({
  op_id: id, uuid, entity: 'pkg', op: kind, payload_json: payload ? JSON.stringify(payload) : null,
  base_revision: kind === 'create' ? null : 1, created_at: '', attempts: 0, last_error: null, batch_id: null, ...extra,
});

describe('collapse + ordering (pure)', () => {
  it('create+update -> create with final data', () => {
    const r = collapseOps([op('a', 'u', 'create', { name: 'A' }), op('b', 'u', 'update', { price: 5 }), op('c', 'u', 'update', { price: 6 })]);
    expect(r.send).toHaveLength(1);
    expect(JSON.parse(r.send[0].payload_json!)).toEqual({ name: 'A', price: 6 });
    expect(r.dropOpIds.sort()).toEqual(['b', 'c']);
  });
  it('create+delete -> nothing', () => {
    const r = collapseOps([op('a', 'u', 'create', { name: 'A' }), op('b', 'u', 'update', { price: 5 }), op('c', 'u', 'delete', null)]);
    expect(r.send).toHaveLength(0);
    expect(r.cancelled).toEqual(['u']);
    expect(r.dropOpIds.sort()).toEqual(['a', 'b', 'c']);
  });
  it('updates -> one update; update+delete -> delete', () => {
    const r = collapseOps([op('a', 'u', 'update', { price: 1 }), op('b', 'u', 'update', { note: 'x' }), op('c', 'v', 'update', { price: 1 }), op('d', 'v', 'delete', null)]);
    expect(r.send.map((o) => o.op)).toEqual(['update', 'delete']);
    expect(JSON.parse(r.send[0].payload_json!)).toEqual({ price: 1, note: 'x' });
  });
  it('a frozen (already sent) op is not rewritten; later ops wait', () => {
    const r = collapseOps([op('a', 'u', 'update', { price: 1 }, { attempts: 1 }), op('b', 'u', 'update', { price: 2 })]);
    expect(r.send.map((o) => o.op_id)).toEqual(['a']);
    expect(r.dropOpIds).toEqual([]);
  });
  it('orders a create before ops referencing its uuid', () => {
    const entities = new Map([['pkg', pkg], ['inq', inq]]);
    const child = { ...op('i', 'ui', 'create', { who: 'W', package: 'up' }), entity: 'inq' };
    const parent = op('p', 'up', 'create', { name: 'P' });
    expect(orderByDependency([child, parent], entities).map((o) => o.op_id)).toEqual(['p', 'i']);
  });
});

describe('sync engine', () => {
  it('offline edits then sync: create, edit, delete reach the server; outbox empties', async () => {
    const { repo, server, sync } = await setup();
    const existing = server.seed('pkg', { name: 'Old', price: 1 });
    const gone = server.seed('pkg', { name: 'Gone' });
    await sync(); // initial pull
    expect((await repo.listRecords('pkg')).length).toBe(2);

    const a = await repo.createRecord(pkg, { name: 'New A', price: 10 });
    const local = (await repo.listRecords('pkg')).find((r) => r.remote_id === existing.remote_id)!;
    await repo.updateRecord(pkg, local.uuid, { price: 2 });
    await repo.updateRecord(pkg, local.uuid, { note: 'hello' });
    const g = (await repo.listRecords('pkg')).find((r) => r.remote_id === gone.remote_id)!;
    await repo.deleteRecords([g.uuid]);
    expect(await repo.pendingCount()).toBe(4); // create + 2 updates + delete

    const s = await sync();
    expect(s.pushed).toBe(3);
    expect(await repo.pendingCount()).toBe(0);
    expect(server.live('pkg').map((r) => r.data.name).sort()).toEqual(['New A', 'Old']);
    expect(server.records.get(existing.remote_id)!.data).toMatchObject({ price: 2, note: 'hello' });
    expect((await repo.getRecord(a))?.status).toBe('synced');
    expect((await repo.getRecord(a))?.remote_id).not.toBeNull();
    expect(await repo.getRecord(g.uuid)).toBeNull();
    // edits were collapsed into ONE update on the wire
    expect(server.pushLog.flat().filter((o) => o.op === 'update')).toHaveLength(1);
    const log = await repo.listSyncLog();
    expect(log[0]).toMatchObject({ pushed: 3 });
    expect(log[0].finished_at).not.toBeNull();
  });

  it('create then delete offline never reaches the server', async () => {
    const { repo, server, sync } = await setup();
    const u = await repo.createRecord(pkg, { name: 'tmp' });
    await repo.deleteRecords([u]);
    const s = await sync();
    expect(s.pushed).toBe(0);
    expect(server.pushLog).toHaveLength(0);
  });

  it('dependency ordering: new package + new inquiry referencing it (child created first, tiny batches)', async () => {
    const { repo, server, sync } = await setup({ batchSize: 1 });
    // inquiry gets created BEFORE the package locally, referencing the package uuid
    const pkgUuid = crypto.randomUUID();
    await repo.createRecord(inq, { who: 'Ali', package: pkgUuid });
    // create the package with a known uuid via direct insert helper
    await repo.db.batch([
      { sql: `INSERT INTO records (uuid, entity, data_json, status, updated_at) VALUES ($1,'pkg',$2,'new','x')`, params: [pkgUuid, JSON.stringify({ name: 'Umrah' })] },
      { sql: `INSERT INTO outbox (op_id, uuid, entity, op, payload_json, created_at) VALUES ($1,$2,'pkg','create',$3,'x')`, params: [crypto.randomUUID(), pkgUuid, JSON.stringify({ name: 'Umrah' })] },
    ]);
    const s = await sync();
    expect(s.errors).toBe(0);
    expect(s.pushed).toBe(2);
    const sentEntities = server.pushLog.flat().map((o) => o.entity);
    expect(sentEntities).toEqual(['pkg', 'inq']);
    const inquiry = server.live('inq')[0];
    expect(inquiry.data.package).toBe(server.live('pkg')[0].remote_id);
  });

  it('conflict when both sides edited; per-field resolution creates an update with server revision', async () => {
    const { repo, server, sync } = await setup();
    const sr = server.seed('pkg', { name: 'Base', price: 1, note: 'n0' });
    await sync();
    const local = (await repo.listRecords('pkg'))[0];
    await repo.updateRecord(pkg, local.uuid, { name: 'Mine', price: 50 });
    server.adminEdit(sr.remote_id, { name: 'Theirs', note: 'server note' });

    const s = await sync();
    expect(s.conflicts).toBe(1);
    const c = (await repo.listConflicts())[0];
    expect(c.status).toBe('conflict');
    expect(c.data.name).toBe('Mine'); // local edit kept
    expect(c.server?.data?.name).toBe('Theirs');
    expect(await repo.pendingCount()).toBe(0);
    const diff = diffFields(pkg, c).filter((d) => d.differs).map((d) => d.key);
    expect(diff).toEqual(expect.arrayContaining(['name', 'price', 'note']));

    await resolveConflict(repo, pkg, c.uuid, { name: { pick: 'server' }, price: { pick: 'mine' }, note: { pick: 'manual', value: 'merged' } });
    const ob = await repo.listOutbox();
    expect(ob).toHaveLength(1);
    expect(ob[0]).toMatchObject({ op: 'update', base_revision: c.server!.revision });
    expect(JSON.parse(ob[0].payload_json!)).toEqual({ price: 50, note: 'merged' });

    const s2 = await sync();
    expect(s2.pushed).toBe(1);
    expect(server.records.get(sr.remote_id)!.data).toMatchObject({ name: 'Theirs', price: 50, note: 'merged' });
    expect((await repo.getRecord(local.uuid))?.status).toBe('synced');
  });

  it('tombstone vs local edit: pull marks conflict; keep server removes, keep mine re-creates', async () => {
    const { repo, server, sync } = await setup();
    const a = server.seed('pkg', { name: 'A' });
    const b = server.seed('pkg', { name: 'B' });
    await sync();
    const recs = await repo.listRecords('pkg');
    const la = recs.find((r) => r.remote_id === a.remote_id)!;
    const lb = recs.find((r) => r.remote_id === b.remote_id)!;
    await repo.updateRecord(pkg, la.uuid, { name: 'A edited' });
    await repo.updateRecord(pkg, lb.uuid, { name: 'B edited' });
    server.adminDelete(a.remote_id);
    server.adminDelete(b.remote_id);
    // pull only (nothing pushed first): block push by confirm=false path is not needed; run full sync
    const s = await sync();
    expect(s.conflicts).toBe(2); // push hits deleted records -> conflict with deleted server snapshot
    const confl = await repo.listConflicts();
    expect(confl.every((c) => c.server?.deleted)).toBe(true);

    await resolveConflict(repo, pkg, la.uuid, 'server');
    expect(await repo.getRecord(la.uuid)).toBeNull();
    await resolveConflict(repo, pkg, lb.uuid, 'mine');
    expect(await repo.getRecord(lb.uuid)).toBeNull(); // old uuid replaced
    const fresh = (await repo.listRecords('pkg')).find((r) => r.data.name === 'B edited')!;
    expect(fresh.status).toBe('new');
    await sync();
    expect(server.live('pkg').map((r) => r.data.name)).toEqual(['B edited']);
  });

  it('tombstone on pull of a clean record removes it locally', async () => {
    const { repo, server, sync } = await setup();
    const a = server.seed('pkg', { name: 'A' });
    await sync();
    server.adminDelete(a.remote_id);
    await sync();
    expect(await repo.listRecords('pkg')).toHaveLength(0);
  });

  it('retry is idempotent: response lost after the server applied the batch', async () => {
    const { repo, server, sync } = await setup();
    const u = await repo.createRecord(pkg, { name: 'Once' });
    server.loseResponses = 1;
    const s = await sync();
    expect(s.aborted).toBeUndefined();
    expect(server.live('pkg')).toHaveLength(1); // not duplicated
    // same op_id and batch_id were re-sent
    expect(server.pushLog[0][0].op_id).toBe(server.pushLog[1][0].op_id);
    expect(server.batchIds[0]).toBe(server.batchIds[1]);
    expect((await repo.getRecord(u))?.status).toBe('synced');
    expect(await repo.pendingCount()).toBe(0);
  });

  it('crash mid-sync is resumable: unsent+unconfirmed ops stay in the outbox and resend with the same op_id', async () => {
    const { repo, server, engine, sync } = await setup();
    await repo.createRecord(pkg, { name: 'Crash' });
    server.failNext.push = 99; // every retry fails -> run aborts offline
    const s = await engine.run({ confirm: async () => true });
    expect(s.aborted).toBe('offline');
    const [stuck] = await repo.listOutbox();
    expect(stuck.attempts).toBe(1); // frozen: may have reached the server
    // user keeps editing meanwhile: must NOT rewrite the frozen create
    const rec = (await repo.listRecords('pkg'))[0];
    await repo.updateRecord(pkg, rec.uuid, { price: 7 });
    const ob = await repo.listOutbox();
    expect(ob.map((o) => o.op)).toEqual(['create', 'update']);
    server.failNext.push = 0;
    const s2 = await sync();
    expect(s2.errors).toBe(0);
    expect(server.pushLog.flat()[0].op_id).toBe(stuck.op_id);
    expect(server.live('pkg')[0].data).toMatchObject({ name: 'Crash', price: 7 });
    expect(await repo.pendingCount()).toBe(0);
  });

  it('interrupted pull resumes from the saved cursor', async () => {
    const { repo, server, engine } = await setup();
    for (let i = 0; i < 25; i++) server.seed('pkg', { name: 'P' + i });
    // pages of 500 would swallow everything; use a tiny page via a wrapped api
    const small = {
      handshake: () => server.handshake().then((h) => ({ ...h, limits: { ...h.limits, pull_max: 10 } })),
      push: server.push.bind(server), pull: server.pull.bind(server), undo: server.undo.bind(server),
    };
    const eng = new SyncEngine({ siteId: 'x' + Math.random(), repo, api: small, sleep: async () => {}, maxRetries: 0 });
    server.failPullOn = 4; // call 1 = empty "inq", 2-3 = two pkg pages, 4th fails
    const s = await eng.run({ confirm: async () => true });
    expect(s.aborted).toBe('offline');
    const partial = (await repo.listRecords('pkg')).length;
    expect(partial).toBe(20); // two committed pages of 10
    const cursor = (await repo.listEntities()).find((e) => e.key === 'pkg')!.cursor;
    expect(cursor).toBeGreaterThan(0);
    const s2 = await eng.run({ confirm: async () => true });
    expect(s2.aborted).toBeUndefined();
    expect((await repo.listRecords('pkg')).length).toBe(25);
    expect(s2.pulled).toBe(5); // resumed, not restarted
    void engine;
  });

  it('safety preview: cancelled confirm leaves everything untouched; auto mode never pushes deletes', async () => {
    const { repo, server, engine, sync } = await setup();
    const a = server.seed('pkg', { name: 'A' });
    await sync();
    const l = (await repo.listRecords('pkg'))[0];
    await repo.deleteRecords([l.uuid]);
    const plan = await engine.plan();
    expect(plan).toMatchObject({ deletes: 1, needsConfirm: true });
    const s = await engine.run({ confirm: async () => false });
    expect(s.aborted).toBe('cancelled');
    expect(await repo.pendingCount()).toBe(1);
    expect(server.records.get(a.remote_id)!.deleted).toBe(false);
    const auto = await engine.run(); // no confirm callback = background run
    expect(auto.aborted).toBe('cancelled');
  });

  it('>20 changes need confirmation; per-op server error stays in outbox with last_error', async () => {
    const { repo, engine, sync } = await setup();
    for (let i = 0; i < 21; i++) await repo.createRecord(pkg, { name: 'n' + i });
    expect((await engine.plan()).needsConfirm).toBe(true);
    // required field blank -> server error for that op only
    await repo.db.batch([
      { sql: `INSERT INTO records (uuid, entity, data_json, status, updated_at) VALUES ('bad','pkg','{}','new','x')` },
      { sql: `INSERT INTO outbox (op_id, uuid, entity, op, payload_json, created_at) VALUES ('op-bad','bad','pkg','create','{}','x')` },
    ]);
    const s = await sync();
    expect(s.pushed).toBe(21);
    expect(s.errors).toBe(1);
    const ob = await repo.listOutbox();
    expect(ob).toHaveLength(1);
    expect(ob[0].last_error).toContain('pdock_required');
    expect((await repo.outboxErrors('pkg')).bad).toContain('pdock_required');
  });

  it('mutex: second concurrent run is refused', async () => {
    const { engine, repo } = await setup();
    await repo.createRecord(pkg, { name: 'x' });
    const [a, b] = await Promise.all([engine.run({ confirm: async () => true }), engine.run({ confirm: async () => true })]);
    expect([a.aborted, b.aborted].filter((x) => x === 'busy')).toHaveLength(1);
  });

  it('undo batch restores server state', async () => {
    const { repo, server, sync } = await setup();
    await repo.createRecord(pkg, { name: 'Undo me' });
    await sync();
    const row = (await repo.listSyncLog())[0];
    expect(server.live('pkg')).toHaveLength(1);
    await undoSyncBatch(repo, server, row);
    expect(server.live('pkg')).toHaveLength(0);
    await sync(); // pull brings the tombstone
    expect(await repo.listRecords('pkg')).toHaveLength(0);
    expect((await repo.listSyncLog()).find((r) => r.id === row.id)?.undone_at).not.toBeNull();
  });
});
