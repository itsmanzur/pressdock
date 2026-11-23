import { enumOptions, type FieldDef } from '../schema/types';
import type { Cell } from './parse';

export interface PlanError {
  field: string;
  /** i18n key, e.g. `validation.required` or `import.err.noMatch` */
  key: string;
  params?: Record<string, string | number>;
}

const BN_DIGITS = '০১২৩৪৫৬৭৮৯';
/** Bengali digits -> ASCII so "১২৩" imports as 123. */
export const normDigits = (s: string): string => s.replace(/[০-৯]/g, (d) => String(BN_DIGITS.indexOf(d)));

const TRUE = new Set(['true', 'yes', 'y', '1', 'on', 'হ্যাঁ', 'হ্যা', 'হাঁ']);
const FALSE = new Set(['false', 'no', 'n', '0', 'off', 'না']);
const pad = (n: number) => String(n).padStart(2, '0');

function validYmd(y: number, m: number, d: number): string | null {
  const dt = new Date(Date.UTC(y, m - 1, d));
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null;
  return `${y}-${pad(m)}-${pad(d)}`;
}

/** Accepts YYYY-MM-DD, YYYY/MM/DD, DD/MM/YYYY and DD-MM-YYYY (day first) and Excel serial numbers. */
export function parseDate(raw: Cell): string | null {
  if (typeof raw === 'number') {
    if (raw > 20000 && raw < 80000) {
      const d = new Date(Date.UTC(1899, 11, 30) + Math.floor(raw) * 86400000);
      return validYmd(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
    }
    return null;
  }
  const s = normDigits(String(raw).trim());
  let m = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?:[ T].*)?$/.exec(s);
  if (m) return validYmd(+m[1], +m[2], +m[3]);
  m = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/.exec(s);
  if (m) return validYmd(+m[3], +m[2], +m[1]);
  return null;
}

export function parseDatetime(raw: Cell): string | null {
  const s = normDigits(String(raw ?? '').trim());
  const m = /^(.+?)[ T](\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(s);
  if (m) {
    const d = parseDate(m[1]);
    return d ? `${d} ${pad(+m[2])}:${m[3]}:${m[4] ?? '00'}` : null;
  }
  const d = parseDate(raw);
  return d ? `${d} 00:00:00` : null;
}

/** Convert one spreadsheet cell to the field's native value. Blank cells are handled by the caller. */
export function coerceCell(def: FieldDef, raw: Cell): { value?: unknown; error?: Omit<PlanError, 'field'> } {
  const s = typeof raw === 'string' ? raw.trim() : raw;
  const bad = (key: string, params?: PlanError['params']) => ({ error: { key: `validation.${key}`, params } });
  switch (def.type) {
    case 'int': {
      const n = typeof s === 'number' ? s : Number(normDigits(String(s)).replace(/,/g, ''));
      return Number.isInteger(n) ? { value: n } : bad('int');
    }
    case 'float': {
      const n = typeof s === 'number' ? s : Number(normDigits(String(s)).replace(/,/g, ''));
      return Number.isFinite(n) ? { value: n } : bad('float');
    }
    case 'bool': {
      if (typeof s === 'boolean') return { value: s };
      const k = String(s).toLowerCase();
      return TRUE.has(k) ? { value: true } : FALSE.has(k) ? { value: false } : bad('bool');
    }
    case 'date': {
      const d = parseDate(s as Cell);
      return d ? { value: d } : bad('date');
    }
    case 'datetime': {
      const d = parseDatetime(s as Cell);
      return d ? { value: d } : bad('datetime');
    }
    case 'enum': {
      const opts = enumOptions(def);
      const k = String(s).trim().toLowerCase();
      const hit = opts.find((o) => o.value.toLowerCase() === k) ?? opts.find((o) => o.label.toLowerCase() === k);
      return hit ? { value: hit.value } : opts.length ? bad('enum') : { value: String(s) };
    }
    case 'list':
      return { value: String(s).split(/\s*[|;\n]\s*/).map((x) => x.trim()).filter(Boolean) };
    case 'object':
      try {
        const o = JSON.parse(String(s));
        return typeof o === 'object' && o && !Array.isArray(o) ? { value: o } : bad('object');
      } catch {
        return bad('object');
      }
    case 'media': {
      const n = Number(normDigits(String(s)));
      return Number.isInteger(n) && n > 0 ? { value: n } : bad('media');
    }
    default:
      return { value: typeof s === 'string' ? s : String(s) };
  }
}
