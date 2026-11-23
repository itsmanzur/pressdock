import type { Entity, LocalRecord, RecordData } from '../schema/types';
import { validateValue } from '../schema/validate';

export type BulkAction =
  | { kind: 'set'; field: string; value: unknown }
  | { kind: 'replace'; field: string; find: string; replace: string; matchCase: boolean; wholeValue: boolean }
  | { kind: 'clear'; field: string };

export interface BulkChange {
  uuid: string;
  /** shown in the preview */
  label: string;
  before: unknown;
  after: unknown;
  patch: RecordData;
}

export interface BulkPlan {
  changes: BulkChange[];
  unchanged: number;
  /** records whose new value fails validation */
  invalid: { uuid: string; label: string; key: string; params?: Record<string, string | number> }[];
}

/** Fields a bulk action may touch. */
export function bulkFields(entity: Entity): string[] {
  return Object.entries(entity.schema)
    .filter(([, d]) => !d.readonly && !d.create_only && d.type !== 'object')
    .map(([k]) => k);
}

export function replaceableFields(entity: Entity): string[] {
  return bulkFields(entity).filter((k) => ['string', 'text', 'html'].includes(entity.schema[k].type));
}

const esc = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

export function replaceText(s: string, a: Extract<BulkAction, { kind: 'replace' }>): string {
  if (!a.find) return s;
  if (a.wholeValue) {
    const eq = a.matchCase ? s === a.find : s.toLowerCase() === a.find.toLowerCase();
    return eq ? a.replace : s;
  }
  return s.replace(new RegExp(esc(a.find), a.matchCase ? 'g' : 'gi'), () => a.replace);
}

/** Cleared value: required fields can't be cleared (reported as invalid). */
export function clearedValue(type: string): unknown {
  if (type === 'list') return [];
  if (type === 'bool') return false;
  return null;
}

/**
 * Compute what a bulk action would do. Pure; nothing is written. `labelOf` gives the record's display name.
 */
export function planBulk(entity: Entity, records: LocalRecord[], action: BulkAction, labelOf: (r: LocalRecord) => string): BulkPlan {
  const def = entity.schema[action.field];
  const plan: BulkPlan = { changes: [], unchanged: 0, invalid: [] };
  if (!def) return plan;
  for (const r of records) {
    if (r.status === 'deleted') continue;
    const before = r.data[action.field];
    let after: unknown;
    if (action.kind === 'set') after = action.value;
    else if (action.kind === 'clear') after = clearedValue(def.type);
    else {
      if (typeof before !== 'string') {
        plan.unchanged++;
        continue;
      }
      after = replaceText(before, action);
    }
    if (JSON.stringify(before ?? null) === JSON.stringify(after ?? null)) {
      plan.unchanged++;
      continue;
    }
    const blankAfter = after === null || after === undefined || (typeof after === 'string' && after.trim() === '') || (Array.isArray(after) && after.length === 0);
    const err = def.required && blankAfter ? { key: 'required' } : validateValue(def, after);
    if (err) {
      plan.invalid.push({ uuid: r.uuid, label: labelOf(r), key: `validation.${err.key}`, params: (err as { params?: Record<string, string | number> }).params });
      continue;
    }
    plan.changes.push({ uuid: r.uuid, label: labelOf(r), before, after, patch: { [action.field]: after } });
  }
  return plan;
}
