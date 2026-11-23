import type { EntitySchema, LocalRecord, RecordData } from '../schema/types';

const PREFERRED = ['title', 'name', 'label', 'package_name', 'subject', 'customer_name'];

/** Human label for a record in pickers: a title-like string field, else first string, else id. */
export function recordLabel(schema: EntitySchema, data: RecordData, fallback: string): string {
  for (const k of PREFERRED) {
    const v = data[k];
    if (typeof v === 'string' && v.trim()) return v;
  }
  for (const [k, def] of Object.entries(schema)) {
    const v = data[k];
    if ((def.type === 'string' || def.type === 'text') && typeof v === 'string' && v.trim()) return v;
  }
  return fallback;
}

export const recordRef = (r: LocalRecord): string | number => r.remote_id ?? r.uuid;
