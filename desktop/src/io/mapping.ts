import { fieldLabel, type Entity } from '../schema/types';

export const REMOTE_ID = '__remote_id';
export const UUID = '__uuid';

export interface TargetField {
  key: string;
  label: string;
  special?: boolean;
}

/** Columns a file can be mapped onto: the record ids plus every writable schema field. */
export function targetsFor(entity: Entity): TargetField[] {
  const out: TargetField[] = [
    { key: REMOTE_ID, label: 'ID (remote id)', special: true },
    { key: UUID, label: 'UUID', special: true },
  ];
  for (const [key, def] of Object.entries(entity.schema)) if (!def.readonly) out.push({ key, label: fieldLabel(key, def) });
  return out;
}

/** Case/space/punctuation-insensitive; keeps letters and combining marks of any script (Bengali). */
export function norm(s: string): string {
  return s
    .normalize('NFKC')
    .replace(/[​-‍﻿]/g, '')
    .toLowerCase()
    .replace(/[^\p{L}\p{M}\p{N}]+/gu, '');
}

const ID_ALIASES: Record<string, string> = {
  id: REMOTE_ID, remoteid: REMOTE_ID, wpid: REMOTE_ID, postid: REMOTE_ID, idremoteid: REMOTE_ID,
  uuid: UUID, guid: UUID,
};

/** One target key per column ('' = ignore). A field is mapped at most once (first column wins). */
export function autoMap(headers: string[], entity: Entity): string[] {
  const byKey = new Map<string, string>();
  const byLabel = new Map<string, string>();
  for (const t of targetsFor(entity)) {
    if (t.special) continue;
    byKey.set(norm(t.key), t.key);
    byLabel.set(norm(t.label), t.key);
  }
  const used = new Set<string>();
  return headers.map((h) => {
    const n = norm(h);
    const key = byKey.get(n) ?? byLabel.get(n) ?? ID_ALIASES[n] ?? '';
    if (!key || used.has(key)) return '';
    used.add(key);
    return key;
  });
}

export interface MappingTemplate {
  name: string;
  /** file header text -> target key */
  map: Record<string, string>;
}

export function toTemplate(name: string, headers: string[], mapping: string[]): MappingTemplate {
  const map: Record<string, string> = {};
  headers.forEach((h, i) => {
    if (mapping[i]) map[h] = mapping[i];
  });
  return { name, map };
}

/** Apply a saved template to new headers (by normalized header text); unknown columns fall back to auto-match. */
export function applyTemplate(headers: string[], tpl: MappingTemplate, entity: Entity): string[] {
  const byNorm = new Map(Object.entries(tpl.map).map(([h, k]) => [norm(h), k]));
  const valid = new Set(targetsFor(entity).map((t) => t.key));
  const auto = autoMap(headers, entity);
  const used = new Set<string>();
  return headers.map((h, i) => {
    let key = byNorm.get(norm(h)) ?? auto[i];
    if (!key || !valid.has(key) || used.has(key)) key = '';
    if (key) used.add(key);
    return key;
  });
}
