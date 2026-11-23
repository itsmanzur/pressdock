import * as XLSX from 'xlsx';
import { enumOptions, fieldLabel, type Entity, type FieldDef, type LocalRecord } from '../schema/types';
import type { Cell } from './parse';

export interface Table {
  headers: string[];
  rows: Cell[][];
}

/** A record value as a spreadsheet cell. */
export function toCell(def: FieldDef | undefined, v: unknown): Cell {
  if (v === null || v === undefined) return null;
  if (!def) return String(v);
  switch (def.type) {
    case 'bool':
      return v === true || v === 1 || v === '1';
    case 'int':
    case 'float':
    case 'relation':
    case 'media': {
      const n = Number(v);
      return typeof v === 'number' || (v !== '' && Number.isFinite(n) && typeof v !== 'object') ? (Number.isFinite(n) && String(v).trim() !== '' && !/[^\d.\-eE]/.test(String(v)) ? n : String(v)) : String(v);
    }
    case 'list':
      return Array.isArray(v) ? v.join(' | ') : String(v);
    case 'object':
      return JSON.stringify(v);
    default:
      return String(v);
  }
}

/**
 * Rows -> table. `columnKeys` are schema keys in display order (the grid's visible columns for a "view" export).
 * ID/UUID columns make a file re-importable as an update.
 */
export function buildExportTable(entity: Entity, records: LocalRecord[], columnKeys: string[], opts: { ids?: boolean } = {}): Table {
  const keys = columnKeys.filter((k) => entity.schema[k]);
  const ids = opts.ids ?? true;
  const headers = [...(ids ? ['ID', 'UUID'] : []), ...keys.map((k) => fieldLabel(k, entity.schema[k]))];
  const rows = records.map((r) => [...(ids ? [r.remote_id, r.uuid] : []), ...keys.map((k) => toCell(entity.schema[k], r.data[k]))] as Cell[]);
  return { headers, rows };
}

function example(def: FieldDef): Cell {
  switch (def.type) {
    case 'int':
      return 1;
    case 'float':
      return 1.5;
    case 'bool':
      return true;
    case 'date':
      return '2026-01-31';
    case 'datetime':
      return '2026-01-31 10:00:00';
    case 'enum':
      return enumOptions(def)[0]?.value ?? '';
    case 'list':
      return 'item one | item two';
    case 'relation':
      return 'Name or ID of the related record';
    case 'media':
      return null;
    case 'object':
      return '{}';
    case 'html':
      return '<p>Text</p>';
    default:
      return 'Example';
  }
}

const TYPE_HELP: Record<string, string> = {
  string: 'Text',
  text: 'Long text',
  html: 'HTML',
  int: 'Whole number',
  float: 'Number',
  bool: 'true / false (yes/no, 1/0, হ্যাঁ/না)',
  date: 'YYYY-MM-DD (also DD/MM/YYYY)',
  datetime: 'YYYY-MM-DD HH:mm:ss (GMT)',
  enum: 'One of the allowed values',
  list: 'Items separated by |',
  relation: 'ID, UUID or name of a record of the related type',
  media: 'WordPress attachment ID',
  object: 'JSON object',
};

/** Template: headers + one example row + an instructions sheet (types, required fields, allowed values). */
export function templateTables(entity: Entity): { data: Table; instructions: Table } {
  const fields = Object.entries(entity.schema).filter(([, d]) => !d.readonly);
  const data: Table = {
    headers: fields.map(([k, d]) => fieldLabel(k, d)),
    rows: [fields.map(([, d]) => example(d))],
  };
  const instructions: Table = {
    headers: ['Column', 'Field key', 'Type', 'Required', 'Allowed values / format', 'Notes'],
    rows: fields.map(([k, d]) => [
      fieldLabel(k, d),
      k,
      d.type,
      d.required ? 'yes' : 'no',
      d.type === 'enum' ? enumOptions(d).map((o) => o.value).join(', ') : (TYPE_HELP[d.type] ?? ''),
      [d.create_only ? 'Only used when creating records' : '', d.type === 'relation' && d.target ? `Related type: ${d.target}` : '', d.max_length ? `Max ${d.max_length} characters` : ''].filter(Boolean).join('. ') || null,
    ]),
  };
  return { data, instructions };
}

const csvEscape = (c: Cell): string => {
  if (c === null || c === undefined) return '';
  const s = String(c);
  return /[",\r\n]/.test(s) || /^\s|\s$/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};

/** UTF-8 with BOM so Excel opens Bengali text correctly; CRLF line ends. */
export function toCsv(t: Table): string {
  return '﻿' + [t.headers, ...t.rows].map((r) => r.map(csvEscape).join(',')).join('\r\n') + '\r\n';
}

export function toXlsx(sheets: { name: string; table: Table }[]): Uint8Array {
  const wb = XLSX.utils.book_new();
  for (const s of sheets) {
    const ws = XLSX.utils.aoa_to_sheet([s.table.headers, ...s.table.rows]);
    ws['!cols'] = s.table.headers.map((h) => ({ wch: Math.min(40, Math.max(10, h.length + 2)) }));
    XLSX.utils.book_append_sheet(wb, ws, s.name.replace(/[\\/?*[\]:]/g, ' ').slice(0, 31) || 'Sheet1');
  }
  return new Uint8Array(XLSX.write(wb, { type: 'array', bookType: 'xlsx' }) as ArrayBuffer);
}

export const encodeText = (s: string): Uint8Array => new TextEncoder().encode(s);
