import type { Entity, LocalRecord, RecordData } from '../schema/types';
import { validateRecord } from '../schema/validate';
import { recordLabel } from '../lib/recordLabel';
import { coerceCell, normDigits, type PlanError } from './coerce';
import type { Cell, ParsedSheet } from './parse';
import { REMOTE_ID, UUID } from './mapping';

export type ImportMode = 'create' | 'update' | 'upsert';

export interface ImportConfig {
  mode: ImportMode;
  /** REMOTE_ID, UUID or a schema field key (should be unique) — used by update/upsert */
  matchBy: string;
  /** target key per column ('' = ignore) */
  mapping: string[];
  /** relation field -> 'auto' (id / uuid / label) or a field key of the related entity */
  relationMatch: Record<string, string>;
}

export interface PlannedRow {
  /** spreadsheet row number (header = 1) */
  rowNo: number;
  action: 'create' | 'update' | 'skip';
  uuid?: string;
  /** create: full data; update: only the changed fields */
  data: RecordData;
  errors: PlanError[];
  warnings: PlanError[];
}

export interface PlanSummary {
  total: number;
  create: number;
  update: number;
  skip: number;
  invalid: number;
}

export interface RelatedSet {
  entity: Entity;
  records: LocalRecord[];
}

export interface PlanContext {
  entity: Entity;
  existing: LocalRecord[];
  related: Record<string, RelatedSet>;
}

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const lower = (v: unknown) => String(v ?? '').trim().toLowerCase();
const blank = (c: Cell) => c === null || c === undefined || (typeof c === 'string' && c.trim() === '');
const yieldNow = () => new Promise<void>((r) => setTimeout(r, 0));

interface RelIndex {
  byId: Map<string, LocalRecord>;
  byUuid: Map<string, LocalRecord>;
  byLabel: Map<string, LocalRecord[]>;
  byField: Map<string, Map<string, LocalRecord[]>>;
  entity: Entity;
}

function buildRelIndex(set: RelatedSet): RelIndex {
  const idx: RelIndex = { byId: new Map(), byUuid: new Map(), byLabel: new Map(), byField: new Map(), entity: set.entity };
  for (const r of set.records) {
    if (r.status === 'deleted') continue;
    idx.byUuid.set(r.uuid.toLowerCase(), r);
    if (r.remote_id != null) idx.byId.set(String(r.remote_id), r);
    const label = lower(recordLabel(set.entity.schema, r.data, ''));
    if (label) idx.byLabel.set(label, [...(idx.byLabel.get(label) ?? []), r]);
  }
  return idx;
}

function fieldIndex(idx: RelIndex, set: RelatedSet, field: string): Map<string, LocalRecord[]> {
  let m = idx.byField.get(field);
  if (!m) {
    m = new Map();
    for (const r of set.records) {
      if (r.status === 'deleted') continue;
      const v = lower(r.data[field]);
      if (v) m.set(v, [...(m.get(v) ?? []), r]);
    }
    idx.byField.set(field, m);
  }
  return m;
}

const refOf = (r: LocalRecord): string | number => r.remote_id ?? r.uuid;

/** Resolve a relation cell to a record reference (remote id or uuid) — by id, uuid or a display field. */
function resolveRelation(raw: Cell, mode: string, idx: RelIndex, set: RelatedSet, field: string): { value?: string | number; error?: PlanError; warning?: PlanError } {
  const s = normDigits(String(raw).trim());
  if (mode !== 'auto') {
    const hits = fieldIndex(idx, set, mode).get(s.toLowerCase()) ?? [];
    if (hits.length === 1) return { value: refOf(hits[0]) };
    if (hits.length > 1) return { error: { field, key: 'import.err.ambiguous', params: { value: s, count: hits.length } } };
    return { error: { field, key: 'import.err.notFound', params: { value: s } } };
  }
  if (UUID_RE.test(s) && idx.byUuid.has(s.toLowerCase())) return { value: refOf(idx.byUuid.get(s.toLowerCase())!) };
  if (/^\d+$/.test(s) && idx.byId.has(s)) return { value: Number(s) };
  const hits = idx.byLabel.get(s.toLowerCase()) ?? [];
  if (hits.length === 1) return { value: refOf(hits[0]) };
  if (hits.length > 1) return { error: { field, key: 'import.err.ambiguous', params: { value: s, count: hits.length } } };
  if (/^\d+$/.test(s)) return { value: Number(s), warning: { field, key: 'import.warn.unknownId', params: { value: s } } };
  return { error: { field, key: 'import.err.notFound', params: { value: s } } };
}

const same = (a: unknown, b: unknown) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);

/**
 * Turn parsed rows into planned local operations. Pure + chunked (yields every 500 rows) so it can run in a worker
 * or on the main thread. Nothing is written here.
 */
export async function buildPlan(
  parsed: ParsedSheet,
  cfg: ImportConfig,
  ctx: PlanContext,
  onProgress?: (done: number, total: number) => void,
): Promise<{ rows: PlannedRow[]; summary: PlanSummary }> {
  const { entity } = ctx;
  const schema = entity.schema;

  // indexes of existing records for matching
  const byRemote = new Map<string, LocalRecord[]>();
  const byUuid = new Map<string, LocalRecord[]>();
  const byField = new Map<string, LocalRecord[]>();
  for (const r of ctx.existing) {
    if (r.remote_id != null) byRemote.set(String(r.remote_id), [...(byRemote.get(String(r.remote_id)) ?? []), r]);
    byUuid.set(r.uuid.toLowerCase(), [r]);
    if (cfg.matchBy !== REMOTE_ID && cfg.matchBy !== UUID) {
      const v = lower(r.data[cfg.matchBy]);
      if (v) byField.set(v, [...(byField.get(v) ?? []), r]);
    }
  }
  const relIdx = new Map<string, RelIndex>();
  const col = (key: string) => cfg.mapping.indexOf(key);

  const rows: PlannedRow[] = [];
  const seenKeys = new Set<string>();
  const total = parsed.rows.length;

  for (let i = 0; i < total; i++) {
    const row = parsed.rows[i];
    const out: PlannedRow = { rowNo: i + 2, action: 'skip', data: {}, errors: [], warnings: [] };
    let remoteId: number | null = null;
    let givenUuid: string | null = null;

    cfg.mapping.forEach((key, c) => {
      if (!key) return;
      const raw = row[c];
      if (blank(raw)) return;
      if (key === REMOTE_ID) {
        const n = Number(normDigits(String(raw).trim()));
        if (Number.isInteger(n) && n > 0) remoteId = n;
        else out.errors.push({ field: 'ID', key: 'validation.int' });
        return;
      }
      if (key === UUID) {
        const s = String(raw).trim().toLowerCase();
        if (UUID_RE.test(s)) givenUuid = s;
        else out.errors.push({ field: 'UUID', key: 'import.err.badUuid' });
        return;
      }
      const def = schema[key];
      if (!def) return;
      if (def.type === 'relation') {
        const set = ctx.related[def.target ?? ''];
        if (!set) {
          out.data[key] = /^\d+$/.test(String(raw).trim()) ? Number(raw) : String(raw).trim();
          return;
        }
        let idx = relIdx.get(set.entity.key);
        if (!idx) relIdx.set(set.entity.key, (idx = buildRelIndex(set)));
        const r = resolveRelation(raw, cfg.relationMatch[key] ?? 'auto', idx, set, key);
        if (r.error) out.errors.push(r.error);
        else out.data[key] = r.value;
        if (r.warning) out.warnings.push(r.warning);
        return;
      }
      const r = coerceCell(def, raw);
      if (r.error) out.errors.push({ field: key, ...r.error });
      else out.data[key] = r.value;
    });

    // --- find the existing record this row refers to
    let matches: LocalRecord[] = [];
    if (cfg.mode !== 'create') {
      if (cfg.matchBy === REMOTE_ID) matches = remoteId != null ? (byRemote.get(String(remoteId)) ?? []) : [];
      else if (cfg.matchBy === UUID) matches = givenUuid ? (byUuid.get(givenUuid) ?? []) : [];
      else {
        const v = lower(out.data[cfg.matchBy]);
        matches = v ? (byField.get(v) ?? []) : [];
        if (!v && col(cfg.matchBy) >= 0) out.errors.push({ field: cfg.matchBy, key: 'import.err.noKey' });
      }
      if (cfg.matchBy === REMOTE_ID && remoteId == null && col(REMOTE_ID) < 0) out.errors.push({ field: 'ID', key: 'import.err.noKeyColumn' });
      if (cfg.matchBy === UUID && !givenUuid && col(UUID) < 0) out.errors.push({ field: 'UUID', key: 'import.err.noKeyColumn' });
    }

    let target: LocalRecord | null = null;
    if (matches.length > 1) out.errors.push({ field: cfg.matchBy, key: 'import.err.ambiguousMatch', params: { count: matches.length } });
    else if (matches.length === 1) target = matches[0];

    if (cfg.mode === 'create') out.action = 'create';
    else if (target) out.action = 'update';
    else if (cfg.mode === 'upsert') out.action = 'create';
    else if (!out.errors.length) out.errors.push({ field: cfg.matchBy, key: 'import.err.noMatch' });

    // --- action-specific rules
    if (out.action === 'create') {
      if (givenUuid) {
        if (byUuid.has(givenUuid)) out.errors.push({ field: 'UUID', key: 'import.err.uuidExists' });
        else out.uuid = givenUuid;
      }
      const errs = validateRecord(schema, out.data);
      for (const [f, e] of Object.entries(errs)) if (!out.errors.some((x) => x.field === f)) out.errors.push({ field: f, key: `validation.${e.key}`, params: e.params });
      const dupKey = out.uuid ?? (cfg.mode === 'upsert' && cfg.matchBy !== REMOTE_ID && cfg.matchBy !== UUID ? 'k:' + lower(out.data[cfg.matchBy]) : null);
      if (dupKey && dupKey !== 'k:') {
        if (seenKeys.has(dupKey)) out.errors.push({ field: cfg.matchBy, key: 'import.err.duplicateInFile' });
        seenKeys.add(dupKey);
      }
    } else if (out.action === 'update' && target) {
      if (target.status === 'deleted') out.errors.push({ field: cfg.matchBy, key: 'import.err.matchedDeleted' });
      else if (target.status === 'conflict') out.errors.push({ field: cfg.matchBy, key: 'import.err.matchedConflict' });
      out.uuid = target.uuid;
      if (seenKeys.has(target.uuid)) out.errors.push({ field: cfg.matchBy, key: 'import.err.duplicateInFile' });
      seenKeys.add(target.uuid);
      // patch = mapped, writable, actually different
      const patch: RecordData = {};
      for (const [k, v] of Object.entries(out.data)) {
        const def = schema[k];
        if (!def || def.readonly) continue;
        if (def.create_only) {
          if (!same(target.data[k], v)) out.warnings.push({ field: k, key: 'import.warn.createOnly' });
          continue;
        }
        if (!same(target.data[k], v)) patch[k] = v;
      }
      out.data = patch;
      const errs = validateRecord(schema, patch, { partial: true });
      for (const [f, e] of Object.entries(errs)) if (!out.errors.some((x) => x.field === f)) out.errors.push({ field: f, key: `validation.${e.key}`, params: e.params });
      if (Object.keys(patch).length === 0 && out.errors.length === 0) {
        out.action = 'skip';
        out.warnings.push({ field: '', key: 'import.warn.unchanged' });
      }
    }
    rows.push(out);
    if (i % 500 === 499) {
      onProgress?.(i + 1, total);
      await yieldNow();
    }
  }
  onProgress?.(total, total);

  const summary: PlanSummary = { total, create: 0, update: 0, skip: 0, invalid: 0 };
  for (const r of rows) {
    if (r.errors.length) summary.invalid++;
    else summary[r.action]++;
  }
  return { rows, summary };
}
