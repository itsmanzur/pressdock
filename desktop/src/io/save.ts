import { platform } from '../platform';
import type { Entity, LocalRecord } from '../schema/types';
import { buildExportTable, encodeText, templateTables, toCsv, toXlsx } from './export';

export type ExportFormat = 'csv' | 'xlsx';
export type ExportKind = 'view' | 'all' | 'template';

const CSV = 'text/csv;charset=utf-8';
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

const stamp = () => new Date().toISOString().slice(0, 10);

/** Build the file for an export and hand it to the platform's save dialog. Returns false if the user cancelled. */
export async function exportEntity(opts: {
  entity: Entity;
  kind: ExportKind;
  format: ExportFormat;
  /** all local records of the entity (deleted ones are ignored) */
  records: LocalRecord[];
  /** kind 'view': uuids in grid order and visible column keys */
  view?: { uuids: string[]; columns: string[] };
}): Promise<boolean> {
  const { entity, kind, format } = opts;
  const base = `${entity.key}-${kind === 'template' ? 'template' : stamp()}`;
  const name = `${base}.${format}`;

  if (kind === 'template') {
    const { data, instructions } = templateTables(entity);
    return format === 'csv'
      ? platform.saveFile(name, encodeText(toCsv(data)), CSV)
      : platform.saveFile(name, toXlsx([{ name: 'Data', table: data }, { name: 'Instructions', table: instructions }]), XLSX_MIME);
  }

  const live = opts.records.filter((r) => r.status !== 'deleted');
  let recs = live;
  let cols = Object.keys(entity.schema);
  if (kind === 'view' && opts.view) {
    const by = new Map(live.map((r) => [r.uuid, r]));
    recs = opts.view.uuids.map((u) => by.get(u)).filter((r): r is LocalRecord => !!r);
    cols = opts.view.columns;
  }
  const table = buildExportTable(entity, recs, cols);
  return format === 'csv'
    ? platform.saveFile(name, encodeText(toCsv(table)), CSV)
    : platform.saveFile(name, toXlsx([{ name: entity.label || entity.key, table }]), XLSX_MIME);
}
