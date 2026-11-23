import * as XLSX from 'xlsx';

export type Cell = string | number | boolean | null;

export interface ParsedSheet {
  sheet: string;
  sheetNames: string[];
  headers: string[];
  /** data rows, each exactly `headers.length` long */
  rows: Cell[][];
}

const pad = (n: number) => String(n).padStart(2, '0');

function dateCell(v: number, fmt?: string): string | null {
  const d = XLSX.SSF.parse_date_code(v, { date1904: false });
  if (!d) return null;
  const date = `${d.y}-${pad(d.m)}-${pad(d.d)}`;
  const hasTime = d.H || d.M || Math.floor(d.S);
  void fmt;
  return hasTime ? `${date} ${pad(d.H)}:${pad(d.M)}:${pad(Math.floor(d.S))}` : date;
}

/**
 * Read CSV or XLSX into headers + rows. Dates in date-formatted cells become `YYYY-MM-DD[ HH:mm:ss]`.
 * Cells are read directly (no JSON round trip) so 10k+ row files stay fast; `onProgress` gets 0..1.
 */
export function parseFile(
  buf: ArrayBuffer,
  fileName: string,
  opts: { sheet?: string } = {},
  onProgress?: (p: number) => void,
): ParsedSheet {
  const isCsv = /\.(csv|txt|tsv)$/i.test(fileName);
  let wb: XLSX.WorkBook;
  if (isCsv) {
    // decode ourselves so UTF-8 (Bengali) survives, drop the BOM Excel adds
    const text = new TextDecoder('utf-8').decode(buf).replace(/^﻿/, '');
    wb = XLSX.read(text, { type: 'string', raw: true, FS: /\.tsv$/i.test(fileName) ? '\t' : undefined });
  } else {
    wb = XLSX.read(buf, { type: 'array', cellDates: false, cellNF: true });
  }
  const sheetNames = wb.SheetNames;
  const name = opts.sheet && sheetNames.includes(opts.sheet) ? opts.sheet : sheetNames[0];
  const ws = wb.Sheets[name];
  if (!ws || !ws['!ref']) return { sheet: name ?? '', sheetNames, headers: [], rows: [] };

  const range = XLSX.utils.decode_range(ws['!ref']);
  const cols = range.e.c - range.s.c + 1;
  const read = (r: number, c: number): Cell => {
    const cell = ws[XLSX.utils.encode_cell({ r, c })];
    if (!cell) return null;
    switch (cell.t) {
      case 'n':
        return cell.z && XLSX.SSF.is_date(cell.z) ? dateCell(cell.v as number) : (cell.v as number);
      case 'b':
        return Boolean(cell.v);
      case 's':
        return String(cell.v);
      case 'd':
        return cell.v instanceof Date ? cell.v.toISOString().slice(0, 10) : String(cell.v);
      default:
        return null;
    }
  };

  const seen = new Map<string, number>();
  const headers: string[] = [];
  for (let c = 0; c < cols; c++) {
    let h = String(read(range.s.r, range.s.c + c) ?? '').trim() || `Column ${c + 1}`;
    const n = (seen.get(h) ?? 0) + 1;
    seen.set(h, n);
    if (n > 1) h = `${h} (${n})`;
    headers.push(h);
  }

  const rows: Cell[][] = [];
  const total = range.e.r - range.s.r;
  for (let r = range.s.r + 1; r <= range.e.r; r++) {
    const row: Cell[] = [];
    let any = false;
    for (let c = 0; c < cols; c++) {
      let v = read(r, range.s.c + c);
      if (typeof v === 'string' && v.trim() === '') v = null;
      if (v !== null) any = true;
      row.push(v);
    }
    if (any) rows.push(row);
    if (onProgress && (r - range.s.r) % 2000 === 0) onProgress((r - range.s.r) / Math.max(1, total));
  }
  onProgress?.(1);
  return { sheet: name, sheetNames, headers, rows };
}
