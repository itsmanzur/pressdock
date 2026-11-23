import { describe, expect, it } from 'vitest';
import initSqlJs from 'sql.js';
import { openMemoryDb } from '../platform/web';
import { SiteRepo } from './repo';
import { currentVersion, LATEST_VERSION } from './migrations';
import { inlineParams, transactionScript } from './inline';
import { validateRecord } from '../schema/validate';
import type { Entity } from '../schema/types';

const entity: Entity = {
  key: 'kafela_package',
  label: 'Packages',
  group: 'Hejazer Kafela',
  cursor: null,
  actions: { read: true, create: true, update: true, delete: true },
  schema: {
    title: { type: 'string', label: 'Title', required: true },
    price: { type: 'int', label: 'Price' },
    status: { type: 'enum', options: ['draft', 'publish'] },
    computed: { type: 'string', readonly: true },
    code: { type: 'string', create_only: true },
  },
};

async function fresh() {
  const db = await openMemoryDb('t' + Math.random(), false);
  const repo = await SiteRepo.open(db);
  return { db, repo };
}

describe('migrations', () => {
  it('create all tables and set user_version', async () => {
    const { db } = await fresh();
    expect(await currentVersion(db)).toBe(LATEST_VERSION);
    const t = await db.select<{ name: string }>(`SELECT name FROM sqlite_master WHERE type='table' ORDER BY name`);
    const names = t.map((r) => r.name);
    for (const n of ['entities', 'records', 'outbox', 'sync_log', 'media_pending']) expect(names).toContain(n);
  });
});

describe('record + outbox atomicity', () => {
  it('create writes record(new) and outbox create together, uuid v4, readonly stripped', async () => {
    const { repo } = await fresh();
    const uuid = await repo.createRecord(entity, { title: 'A', price: 5, computed: 'x' });
    expect(uuid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    const rec = await repo.getRecord(uuid);
    expect(rec?.status).toBe('new');
    const ob = await repo.listOutbox();
    expect(ob).toHaveLength(1);
    expect(ob[0].op).toBe('create');
    expect(JSON.parse(ob[0].payload_json!)).toEqual({ title: 'A', price: 5 });
  });

  it('edit of a new record folds into the pending create', async () => {
    const { repo } = await fresh();
    const uuid = await repo.createRecord(entity, { title: 'A' });
    await repo.updateRecord(entity, uuid, { title: 'B' });
    const ob = await repo.listOutbox();
    expect(ob).toHaveLength(1);
    expect(JSON.parse(ob[0].payload_json!).title).toBe('B');
  });

  it('edit of a synced record: dirty + update op with base_revision, only changed writable fields', async () => {
    const { repo, db } = await fresh();
    await db.execute(
      `INSERT INTO records (uuid, entity, remote_id, data_json, base_revision, status, updated_at)
       VALUES ('u1', 'kafela_package', 7, $1, 3, 'synced', 'x')`,
      [JSON.stringify({ title: 'T', price: 1, code: 'c1' })],
    );
    await repo.updateRecord(entity, 'u1', { title: 'T', price: 2, code: 'zzz' });
    const rec = await repo.getRecord('u1');
    expect(rec?.status).toBe('dirty');
    const ob = await repo.listOutbox();
    expect(ob).toHaveLength(1);
    expect(ob[0]).toMatchObject({ op: 'update', base_revision: 3 });
    expect(JSON.parse(ob[0].payload_json!)).toEqual({ price: 2 }); // title unchanged, code create_only
  });

  it('no-op edit writes nothing', async () => {
    const { repo } = await fresh();
    const uuid = await repo.createRecord(entity, { title: 'A' });
    await repo.updateRecord(entity, uuid, { title: 'A' });
    expect(await repo.pendingCount()).toBe(1);
  });

  it('delete: synced -> soft delete + delete op; new -> removed with its ops', async () => {
    const { repo, db } = await fresh();
    await db.execute(
      `INSERT INTO records (uuid, entity, remote_id, data_json, base_revision, status, updated_at)
       VALUES ('u1', 'kafela_package', 7, '{"title":"T"}', 2, 'synced', 'x')`,
    );
    await repo.updateRecord(entity, 'u1', { title: 'T2' });
    await repo.deleteRecords(['u1']);
    expect((await repo.getRecord('u1'))?.status).toBe('deleted');
    expect((await repo.listRecords('kafela_package')).length).toBe(0);
    const ob = await repo.listOutbox();
    expect(ob.map((o) => o.op)).toEqual(['delete']); // pending update dropped
    expect(ob[0].base_revision).toBe(2);

    const n = await repo.createRecord(entity, { title: 'tmp' });
    await repo.deleteRecords([n]);
    expect(await repo.getRecord(n)).toBeNull();
    expect(await repo.pendingCount()).toBe(1);
  });

  it('batch rolls back fully on failure', async () => {
    const { repo, db } = await fresh();
    await expect(
      db.batch([
        { sql: `INSERT INTO records (uuid, entity, data_json, status, updated_at) VALUES ('a','e','{}','new','x')` },
        { sql: `INSERT INTO records (uuid, entity, data_json, status, updated_at) VALUES ('b','e','{}','BOGUS','x')` },
      ]),
    ).rejects.toThrow();
    expect(await repo.getRecord('a')).toBeNull();
  });

  it('bulk update touches all selected in one go', async () => {
    const { repo } = await fresh();
    const a = await repo.createRecord(entity, { title: 'A' });
    const b = await repo.createRecord(entity, { title: 'B' });
    expect(await repo.updateMany(entity, [a, b], { status: 'publish' })).toBe(2);
    expect((await repo.getRecord(b))?.data.status).toBe('publish');
  });
});

describe('inline SQL script (Tauri transaction path)', () => {
  it('escapes quotes/unicode and gives identical results in real SQLite', async () => {
    const SQL = await initSqlJs();
    const db = new SQL.Database();
    db.run('CREATE TABLE t (a TEXT, b INTEGER, c TEXT)');
    const nasty = `it's "q" ; DROP TABLE t; -- $1 বাংলা \u0000end`;
    const script = transactionScript([
      { sql: 'INSERT INTO t VALUES ($1, $2, $3)', params: [nasty, 42, null] },
      { sql: 'UPDATE t SET b = $1;', params: [43] },
    ]);
    db.exec(script);
    const r = db.exec('SELECT a, b, c FROM t')[0].values[0];
    expect(r[0]).toBe(nasty.replace('\u0000', ''));
    expect(r[1]).toBe(43);
    expect(r[2]).toBeNull();
    expect(() => inlineParams('SELECT $2', [1])).toThrow();
  });
});

describe('validation', () => {
  it('required, types, enums', () => {
    const e = validateRecord(entity.schema, { title: '', price: 'x', status: 'zzz' });
    expect(e.title.key).toBe('required');
    expect(e.price.key).toBe('int');
    expect(e.status.key).toBe('enum');
    expect(validateRecord(entity.schema, { title: 'ok', price: 3, status: 'draft' })).toEqual({});
  });
});
