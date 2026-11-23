import { describe, expect, it } from 'vitest';
import * as XLSX from 'xlsx';
import { openMemoryDb } from '../platform/web';
import { SiteRepo } from '../db/repo';
import type { Entity, LocalRecord } from '../schema/types';
import { parseFile, type ParsedSheet } from './parse';
import { applyTemplate, autoMap, REMOTE_ID, toTemplate, UUID } from './mapping';
import { coerceCell, parseDate, parseDatetime } from './coerce';
import { buildPlan, type ImportConfig, type PlanContext } from './plan';
import { buildExportTable, templateTables, toCsv, toXlsx } from './export';
import { planBulk, replaceText } from './bulk';

const actions = { read: true, create: true, update: true, delete: true };
const pkg: Entity = {
  key: 'pkg', label: 'Packages', group: 'g', cursor: null, actions,
  schema: {
    title: { type: 'string', label: 'Package name', required: true, max_length: 20 },
    price: { type: 'int', label: 'Price' },
    kind: { type: 'enum', label: 'Kind', options: ['umrah', 'hajj'] },
    active: { type: 'bool', label: 'Active' },
    start: { type: 'date', label: 'Start date' },
    tags: { type: 'list', label: 'Tags' },
    slug: { type: 'string', label: 'Slug', create_only: true },
    computed: { type: 'string', readonly: true },
  },
};
const inq: Entity = {
  key: 'inq', label: 'Inquiries', group: 'g', cursor: null, actions,
  schema: { who: { type: 'string', required: true }, package: { type: 'relation', target: 'pkg', label: 'Package' } },
};

const rec = (uuid: string, remote: number | null, data: Record<string, unknown>, status: LocalRecord['status'] = 'synced'): LocalRecord => ({
  uuid, entity: 'pkg', remote_id: remote, data, base_revision: 1, status, updated_at: '',
});

const sheet = (headers: string[], rows: ParsedSheet['rows']): ParsedSheet => ({ sheet: 'S', sheetNames: ['S'], headers, rows });
const ctx = (existing: LocalRecord[] = [], related: PlanContext['related'] = {}): PlanContext => ({ entity: pkg, existing, related });
const cfg = (over: Partial<ImportConfig> = {}): ImportConfig => ({ mode: 'create', matchBy: REMOTE_ID, mapping: [], relationMatch: {}, ...over });

describe('column mapping', () => {
  it('auto-matches by key, label and Bengali digits-insensitive punctuation', () => {
    const m = autoMap(['Package name', 'PRICE', 'start_date', 'ID', 'unknown'], pkg);
    expect(m).toEqual(['title', 'price', 'start', REMOTE_ID, '']);
  });
  it('maps Bengali headers when the schema label is Bengali', () => {
    const bn: Entity = { ...pkg, schema: { title: { type: 'string', label: 'প্যাকেজের নাম' }, price: { type: 'int', label: 'মূল্য' } } };
    expect(autoMap(['প্যাকেজের নাম', 'মূল্য (৳)'.replace(' (৳)', '')], bn)).toEqual(['title', 'price']);
  });
  it('a field is mapped once', () => {
    expect(autoMap(['title', 'Package name'], pkg)).toEqual(['title', '']);
  });
  it('template round-trips onto a file with reordered columns', () => {
    const tpl = toTemplate('t', ['Nam', 'Cost', 'x'], ['title', 'price', '']);
    expect(applyTemplate(['Cost', 'Nam', 'Other'], tpl, pkg)).toEqual(['price', 'title', '']);
  });
});

describe('cell coercion', () => {
  it('numbers, Bengali digits, bools, enums, lists', () => {
    expect(coerceCell(pkg.schema.price, '১২৩')).toEqual({ value: 123 });
    expect(coerceCell(pkg.schema.price, '1,500')).toEqual({ value: 1500 });
    expect(coerceCell(pkg.schema.price, 'abc').error?.key).toBe('validation.int');
    expect(coerceCell(pkg.schema.active, 'হ্যাঁ')).toEqual({ value: true });
    expect(coerceCell(pkg.schema.active, 'no')).toEqual({ value: false });
    expect(coerceCell(pkg.schema.kind, 'HAJJ')).toEqual({ value: 'hajj' });
    expect(coerceCell(pkg.schema.kind, 'other').error?.key).toBe('validation.enum');
    expect(coerceCell(pkg.schema.tags, 'a | b;c')).toEqual({ value: ['a', 'b', 'c'] });
  });
  it('dates incl. DD/MM/YYYY, Excel serials, invalid days', () => {
    expect(parseDate('2026-02-03')).toBe('2026-02-03');
    expect(parseDate('03/02/2026')).toBe('2026-02-03');
    expect(parseDate('২০২৬-০২-০৩')).toBe('2026-02-03');
    expect(parseDate(46056)).toBe('2026-02-03');
    expect(parseDate('2026-02-30')).toBeNull();
    expect(parseDatetime('2026-02-03 4:05')).toBe('2026-02-03 04:05:00');
  });
});

describe('plan: validation', () => {
  it('flags bad rows per field, keeps good ones', async () => {
    const p = sheet(['Package name', 'Price', 'Kind'], [['A', 10, 'hajj'], ['', 5, 'hajj'], ['B', 'x', 'umrah'], ['C', 1, 'zzz'], ['D'.repeat(30), 1, null]]);
    const { rows, summary } = await buildPlan(p, cfg({ mapping: ['title', 'price', 'kind'] }), ctx());
    expect(rows[0].errors).toEqual([]);
    expect(rows[1].errors.map((e) => e.field)).toEqual(['title']);
    expect(rows[2].errors.map((e) => e.field)).toEqual(['price']);
    expect(rows[3].errors.map((e) => e.field)).toEqual(['kind']);
    expect(rows[4].errors.map((e) => e.key)).toEqual(['validation.maxLength']);
    expect(summary).toMatchObject({ total: 5, create: 1, invalid: 4 });
    expect(rows[1].rowNo).toBe(3);
  });
});

describe('plan: modes + matching', () => {
  const existing = [rec('u1', 10, { title: 'Alpha', price: 1, slug: 's1' }), rec('u2', 11, { title: 'Beta', price: 2 }), rec('u3', null, { title: 'Beta', price: 3 }, 'new')];

  it('create only never matches', async () => {
    const p = sheet(['id', 'title'], [[10, 'Alpha']]);
    const { rows } = await buildPlan(p, cfg({ mapping: [REMOTE_ID, 'title'] }), ctx(existing));
    expect(rows[0].action).toBe('create');
  });
  it('update by remote id sends only changed fields; unchanged -> skip', async () => {
    const p = sheet(['id', 'title', 'price'], [[10, 'Alpha', 99], [11, 'Beta', 2]]);
    const { rows, summary } = await buildPlan(p, cfg({ mode: 'update', mapping: [REMOTE_ID, 'title', 'price'] }), ctx(existing));
    expect(rows[0]).toMatchObject({ action: 'update', uuid: 'u1', data: { price: 99 } });
    expect(rows[1].action).toBe('skip');
    expect(summary).toMatchObject({ update: 1, skip: 1, invalid: 0 });
  });
  it('update with no match is an error; upsert creates', async () => {
    const p = sheet(['id', 'title'], [[999, 'Nope']]);
    const u = await buildPlan(p, cfg({ mode: 'update', mapping: [REMOTE_ID, 'title'] }), ctx(existing));
    expect(u.rows[0].errors[0].key).toBe('import.err.noMatch');
    const s = await buildPlan(p, cfg({ mode: 'upsert', mapping: [REMOTE_ID, 'title'] }), ctx(existing));
    expect(s.rows[0].action).toBe('create');
  });
  it('match by uuid', async () => {
    const id = '11111111-1111-4111-8111-111111111111';
    const ex = [rec(id, 5, { title: 'X' })];
    const { rows } = await buildPlan(sheet(['uuid', 'title'], [[id, 'Y']]), cfg({ mode: 'update', matchBy: UUID, mapping: [UUID, 'title'] }), ctx(ex));
    expect(rows[0]).toMatchObject({ action: 'update', uuid: id, data: { title: 'Y' } });
  });
  it('match by unique field: ambiguous match is an error', async () => {
    const p = sheet(['title', 'price'], [['alpha', 7], ['Beta', 7]]);
    const { rows } = await buildPlan(p, cfg({ mode: 'upsert', matchBy: 'title', mapping: ['title', 'price'] }), ctx(existing));
    expect(rows[0]).toMatchObject({ action: 'update', uuid: 'u1' });
    expect(rows[1].errors[0].key).toBe('import.err.ambiguousMatch');
  });
  it('create_only fields are not patched on update; deleted/conflict targets rejected', async () => {
    const ex = [rec('a', 1, { title: 'A', slug: 'old' }), rec('b', 2, { title: 'B' }, 'deleted'), rec('c', 3, { title: 'C' }, 'conflict')];
    const p = sheet(['id', 'slug', 'price'], [[1, 'new', 5], [2, null, 5], [3, null, 5]]);
    const { rows } = await buildPlan(p, cfg({ mode: 'update', mapping: [REMOTE_ID, 'slug', 'price'] }), ctx(ex));
    expect(rows[0].data).toEqual({ price: 5 });
    expect(rows[0].warnings.map((w) => w.key)).toContain('import.warn.createOnly');
    expect(rows[1].errors[0].key).toBe('import.err.matchedDeleted');
    expect(rows[2].errors[0].key).toBe('import.err.matchedConflict');
  });
  it('same key twice in one file is flagged', async () => {
    const p = sheet(['id', 'price'], [[10, 5], [10, 6]]);
    const { rows } = await buildPlan(p, cfg({ mode: 'update', mapping: [REMOTE_ID, 'price'] }), ctx(existing));
    expect(rows[0].errors).toEqual([]);
    expect(rows[1].errors[0].key).toBe('import.err.duplicateInFile');
  });
});

describe('plan: relations', () => {
  const related = { pkg: { entity: pkg, records: [rec('p1', 1, { title: 'Hajj 2026' }), rec('p2', 2, { title: 'Umrah' }), rec('p3', 3, { title: 'Umrah' }), rec('p4', null, { title: 'Local only' }, 'new')] } };
  const run = (cell: string | number, rm: Record<string, string> = {}) =>
    buildPlan(sheet(['who', 'pkg'], [['x', cell]]), { ...cfg({ mapping: ['who', 'package'], relationMatch: rm }), mode: 'create' }, { ...ctx([], related), entity: inq });

  it('resolves by display name, id and uuid-of-unsynced', async () => {
    expect((await run('hajj 2026')).rows[0].data.package).toBe(1);
    expect((await run(2)).rows[0].data.package).toBe(2);
    expect((await run('Local only')).rows[0].data.package).toBe('p4');
  });
  it('warns on ambiguity (error) and unknown names', async () => {
    const a = await run('Umrah');
    expect(a.rows[0].errors[0]).toMatchObject({ key: 'import.err.ambiguous', params: { count: 2 } });
    expect((await run('Nothing')).rows[0].errors[0].key).toBe('import.err.notFound');
    const unk = await run(77);
    expect(unk.rows[0].errors).toEqual([]);
    expect(unk.rows[0].warnings[0].key).toBe('import.warn.unknownId');
  });
  it('explicit match field overrides auto', async () => {
    const r = await run('Umrah', { package: 'title' });
    expect(r.rows[0].errors[0].key).toBe('import.err.ambiguous');
  });
});

describe('parse + export round trip', () => {
  it('csv with BOM, quotes, commas and Bengali', () => {
    const csv = toCsv({ headers: ['Package name', 'Note'], rows: [['হজ্জ, ২০২৬', 'say "hi"\nline2'], ['B', null]] });
    expect(csv.charCodeAt(0)).toBe(0xfeff);
    const out = parseFile(new TextEncoder().encode(csv).buffer as ArrayBuffer, 'x.csv');
    expect(out.headers).toEqual(['Package name', 'Note']);
    expect(out.rows).toEqual([['হজ্জ, ২০২৬', 'say "hi"\nline2'], ['B', null]]);
  });
  it('xlsx round trip keeps numbers, bools and sheets; skips blank rows', () => {
    const bytes = toXlsx([{ name: 'Data', table: { headers: ['a', 'b', 'c'], rows: [[1, true, 'x'], [null, null, null], [2.5, false, 'y']] } }, { name: 'Help', table: { headers: ['h'], rows: [] } }]);
    const out = parseFile(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer, 'x.xlsx');
    expect(out.sheetNames).toEqual(['Data', 'Help']);
    expect(out.rows).toEqual([[1, true, 'x'], [2.5, false, 'y']]);
  });
  it('xlsx date cells become ISO strings', () => {
    const ws = XLSX.utils.aoa_to_sheet([['d'], [46056]]);
    ws.A2.z = 'yyyy-mm-dd';
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'S');
    const buf = XLSX.write(wb, { type: 'array', bookType: 'xlsx' }) as ArrayBuffer;
    expect(parseFile(buf, 'd.xlsx').rows[0][0]).toBe('2026-02-03');
  });
  it('exported table re-imports as updates (ID + UUID columns)', async () => {
    const recs = [rec('11111111-1111-4111-8111-111111111111', 10, { title: 'Alpha', price: 5, tags: ['a', 'b'], active: true })];
    const t = buildExportTable(pkg, recs, ['title', 'price', 'tags', 'active']);
    const parsed = parseFile(new TextEncoder().encode(toCsv(t)).buffer as ArrayBuffer, 'e.csv');
    const mapping = autoMap(parsed.headers, pkg);
    expect(mapping.slice(0, 2)).toEqual([REMOTE_ID, UUID]);
    const { rows } = await buildPlan(parsed, cfg({ mode: 'update', mapping }), ctx(recs));
    expect(rows[0].action).toBe('skip'); // identical -> nothing to do
  });
  it('template: only writable fields, example row, instructions list enums/required', () => {
    const { data, instructions } = templateTables(pkg);
    expect(data.headers).not.toContain('computed');
    expect(data.rows).toHaveLength(1);
    const kind = instructions.rows.find((r) => r[1] === 'kind')!;
    expect(kind[4]).toBe('umrah, hajj');
    expect(instructions.rows.find((r) => r[1] === 'title')![3]).toBe('yes');
  });
});

describe('bulk planning', () => {
  const recs = [rec('a', 1, { title: 'Hajj Gold', price: 1 }), rec('b', 2, { title: 'Umrah', price: 2 }), rec('c', 3, { title: 'hajj basic', price: 2 }), rec('d', 4, { title: 'x' }, 'deleted')];
  const label = (r: LocalRecord) => String(r.data.title);
  it('set: only differing, valid rows; deleted skipped', () => {
    const p = planBulk(pkg, recs, { kind: 'set', field: 'price', value: 2 }, label);
    expect(p.changes.map((c) => c.uuid)).toEqual(['a']);
    expect(p.unchanged).toBe(2);
  });
  it('set invalid value is reported, not applied', () => {
    const p = planBulk(pkg, recs, { kind: 'set', field: 'kind', value: 'nope' }, label);
    expect(p.changes).toHaveLength(0);
    expect(p.invalid).toHaveLength(3);
  });
  it('find & replace: case options and whole value', () => {
    expect(replaceText('Hajj hajj', { kind: 'replace', field: 'title', find: 'hajj', replace: 'H', matchCase: false, wholeValue: false })).toBe('H H');
    expect(replaceText('Hajj hajj', { kind: 'replace', field: 'title', find: 'hajj', replace: 'H', matchCase: true, wholeValue: false })).toBe('Hajj H');
    expect(replaceText('a.b', { kind: 'replace', field: 'title', find: '.', replace: '-', matchCase: true, wholeValue: false })).toBe('a-b');
    const p = planBulk(pkg, recs, { kind: 'replace', field: 'title', find: 'hajj', replace: 'Haj', matchCase: false, wholeValue: false }, label);
    expect(p.changes.map((c) => c.after)).toEqual(['Haj Gold', 'Haj basic']);
  });
  it('clear: required field cannot be cleared', () => {
    expect(planBulk(pkg, recs, { kind: 'clear', field: 'price' }, label).changes).toHaveLength(3);
    const p = planBulk(pkg, recs, { kind: 'clear', field: 'title' }, label);
    expect(p.changes).toHaveLength(0);
    expect(p.invalid[0].key).toBe('validation.required');
  });
});

describe('repo apply', () => {
  it('importItems writes records + outbox ops; update folds into pending create', async () => {
    const repo = await SiteRepo.open(await openMemoryDb('io' + Math.random(), false));
    await repo.saveEntities([pkg]);
    const r = await repo.importItems(pkg, [
      { action: 'create', data: { title: 'A', price: 1 } },
      { action: 'create', uuid: 'fixed-uuid', data: { title: 'B' } },
    ]);
    expect(r.created).toBe(2);
    expect(await repo.pendingCount()).toBe(2);
    const r2 = await repo.importItems(pkg, [{ action: 'update', uuid: 'fixed-uuid', data: { price: 9 } }]);
    expect(r2.updated).toBe(1);
    expect(await repo.pendingCount()).toBe(2); // folded
    expect((await repo.getRecord('fixed-uuid'))!.data).toMatchObject({ title: 'B', price: 9 });
  });
  it('updateEach writes one update op per changed record', async () => {
    const repo = await SiteRepo.open(await openMemoryDb('io' + Math.random(), false));
    await repo.saveEntities([pkg]);
    const u = await repo.createRecord(pkg, { title: 'Z' });
    await repo.runTx([{ sql: `UPDATE records SET status='synced', remote_id=1 WHERE uuid=$1`, params: [u] }, { sql: 'DELETE FROM outbox', params: [] }]);
    const n = await repo.updateEach(pkg, [{ uuid: u, patch: { title: 'Zed' } }]);
    expect(n).toBe(1);
    expect((await repo.listOutbox()).map((o) => o.op)).toEqual(['update']);
  });
});

describe('large files', () => {
  const N = 12000;
  const makeRows = () => Array.from({ length: N }, (_, i) => [`Pkg ${i}`, i, i % 2 ? 'hajj' : 'umrah', i % 3 === 0, '2026-03-04']);
  const headers = ['Package name', 'Price', 'Kind', 'Active', 'Start date'];

  it('12k-row xlsx: parse + plan + import stay fast', async () => {
    const bytes = toXlsx([{ name: 'D', table: { headers, rows: makeRows() } }]);
    const buf = bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer;
    let t = performance.now();
    const parsed = parseFile(buf, 'big.xlsx');
    const tParse = performance.now() - t;
    expect(parsed.rows).toHaveLength(N);

    t = performance.now();
    const ticks: number[] = [];
    const { rows, summary } = await buildPlan(parsed, cfg({ mapping: autoMap(parsed.headers, pkg) }), ctx(), (d) => ticks.push(d));
    const tPlan = performance.now() - t;
    expect(summary).toMatchObject({ create: N, invalid: 0 });
    expect(rows).toHaveLength(N);
    expect(ticks.length).toBeGreaterThan(10); // yields to the event loop in chunks

    const repo = await SiteRepo.open(await openMemoryDb('big' + Math.random(), false));
    await repo.saveEntities([pkg]);
    t = performance.now();
    const prog: number[] = [];
    await repo.importItems(pkg, rows.map((r) => ({ action: 'create' as const, data: r.data })), (d) => prog.push(d));
    const tImport = performance.now() - t;
    expect(await repo.pendingCount()).toBe(N);
    expect(prog[prog.length - 1]).toBe(N);
    console.log(`perf ${N} rows: parse ${tParse.toFixed(0)}ms plan ${tPlan.toFixed(0)}ms import ${tImport.toFixed(0)}ms`);
    expect(tParse).toBeLessThan(8000);
    expect(tPlan).toBeLessThan(3000);
  }, 60000);

  it('12k-row csv export + re-parse', () => {
    const csv = toCsv({ headers, rows: makeRows() });
    const parsed = parseFile(new TextEncoder().encode(csv).buffer as ArrayBuffer, 'big.csv');
    expect(parsed.rows).toHaveLength(N);
  });
});
