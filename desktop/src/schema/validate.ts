import { enumOptions, type EntitySchema, type FieldDef, type RecordData } from './types';

/** A translatable validation message: `key` lives under `validation.*` in the i18n files. */
export interface FieldError {
  key: string;
  params?: Record<string, string | number>;
}
export type ValidationErrors = Record<string, FieldError>;

const isBlank = (v: unknown): boolean =>
  v === undefined || v === null || (typeof v === 'string' && v.trim() === '') || (Array.isArray(v) && v.length === 0);

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const DATETIME_RE = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/;

function validDate(s: string): boolean {
  if (!DATE_RE.test(s)) return false;
  const d = new Date(s + 'T00:00:00Z');
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === s;
}

export function validateValue(def: FieldDef, value: unknown): FieldError | null {
  if (isBlank(value)) return null;
  switch (def.type) {
    case 'int': {
      const n = typeof value === 'number' ? value : Number(value);
      return Number.isInteger(n) ? null : { key: 'int' };
    }
    case 'float': {
      const n = typeof value === 'number' ? value : Number(value);
      return Number.isFinite(n) ? null : { key: 'float' };
    }
    case 'bool':
      return typeof value === 'boolean' || value === 0 || value === 1 || value === '0' || value === '1'
        ? null
        : { key: 'bool' };
    case 'date':
      return typeof value === 'string' && validDate(value) ? null : { key: 'date' };
    case 'datetime':
      return typeof value === 'string' && DATETIME_RE.test(value) ? null : { key: 'datetime' };
    case 'enum': {
      const allowed = enumOptions(def).map((o) => o.value);
      return allowed.length === 0 || allowed.includes(String(value)) ? null : { key: 'enum' };
    }
    case 'string':
    case 'text':
      if (typeof value !== 'string') return { key: 'string' };
      if (def.max_length && value.length > def.max_length) return { key: 'maxLength', params: { max: def.max_length } };
      return null;
    case 'html':
      return typeof value === 'string' ? null : { key: 'string' };
    case 'relation':
      return Number.isInteger(Number(value)) || typeof value === 'string' ? null : { key: 'relation' };
    case 'media':
      return Number.isInteger(Number(value)) || (typeof value === 'string' && value.startsWith('pending:'))
        ? null
        : { key: 'media' };
    case 'list': {
      if (!Array.isArray(value) || value.some((x) => typeof x !== 'string')) return { key: 'list' };
      if (def.max_items && value.length > def.max_items) return { key: 'maxItems', params: { max: def.max_items } };
      if (def.item_max_length && value.some((x: string) => x.length > def.item_max_length!))
        return { key: 'maxLength', params: { max: def.item_max_length } };
      return null;
    }
    case 'object':
      return typeof value === 'object' && !Array.isArray(value) ? null : { key: 'object' };
    default:
      return null;
  }
}

/**
 * Validate a (full or partial) record against its schema. Read-only fields are skipped.
 * With `partial`, required checks only apply to fields present in `data`.
 */
export function validateRecord(
  schema: EntitySchema,
  data: RecordData,
  opts: { partial?: boolean; prefix?: string } = {},
): ValidationErrors {
  const errors: ValidationErrors = {};
  for (const [key, def] of Object.entries(schema)) {
    if (def.readonly) continue;
    const path = opts.prefix ? `${opts.prefix}.${key}` : key;
    const present = key in data;
    if (opts.partial && !present) continue;
    const value = data[key];
    if (def.required && isBlank(value) && def.type !== 'bool') {
      errors[path] = { key: 'required' };
      continue;
    }
    if (def.type === 'object' && def.properties && value && typeof value === 'object' && !Array.isArray(value)) {
      Object.assign(errors, validateRecord(def.properties, value as RecordData, { partial: opts.partial, prefix: path }));
      continue;
    }
    const err = validateValue(def, value);
    if (err) errors[path] = err;
  }
  return errors;
}

/** Convert text typed into an inline cell to the field's native JS type. Returns undefined if unparsable. */
export function coerceInput(def: FieldDef, raw: string): unknown {
  const s = raw.trim();
  switch (def.type) {
    case 'int':
      return s === '' ? null : /^-?\d+$/.test(s) ? Number(s) : raw;
    case 'float':
      return s === '' ? null : Number.isFinite(Number(s)) ? Number(s) : raw;
    case 'bool':
      return s === 'true' || s === '1';
    default:
      return raw;
  }
}
