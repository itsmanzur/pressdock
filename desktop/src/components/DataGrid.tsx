import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  flexRender,
  getCoreRowModel,
  getFilteredRowModel,
  getSortedRowModel,
  useReactTable,
  type ColumnDef,
  type ColumnFiltersState,
  type SortingState,
  type VisibilityState,
} from '@tanstack/react-table';
import { useVirtualizer } from '@tanstack/react-virtual';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronRight, Lock } from 'lucide-react';
import {
  enumOptions,
  fieldLabel,
  INLINE_TYPES,
  type Entity,
  type FieldDef,
  type LocalRecord,
} from '../schema/types';
import { coerceInput, validateValue } from '../schema/validate';
import { htmlToText } from '../lib/html';
import { STATUS_STYLE } from './ui';

const ROW_H = 36;
const W_SELECT = 44;
const W_STATUS = 92;
const W_OPEN = 40;

export function cellText(def: FieldDef, v: unknown): string {
  if (v === null || v === undefined || v === '') return '';
  switch (def.type) {
    case 'bool':
      return v === true || v === 1 || v === '1' ? '✓' : '–';
    case 'html':
      return htmlToText(String(v)).slice(0, 160);
    case 'list':
      return Array.isArray(v) ? v.join(', ') : String(v);
    case 'object':
      return '{…}';
    case 'relation':
    case 'media':
      return typeof v === 'string' && v.startsWith('pending:') ? '📎' : `#${String(v)}`;
    default:
      return String(v);
  }
}

const sortValue = (def: FieldDef, v: unknown): number | string => {
  if (def.type === 'int' || def.type === 'float' || def.type === 'relation' || def.type === 'media') {
    const n = Number(v);
    return Number.isFinite(n) ? n : Number.NEGATIVE_INFINITY;
  }
  if (def.type === 'bool') return v === true || v === 1 || v === '1' ? 1 : 0;
  return cellText(def, v).toLowerCase();
};

interface Props {
  entity: Entity;
  rows: LocalRecord[];
  selected: Set<string>;
  onSelect: (next: Set<string>) => void;
  onOpen: (uuid: string) => void;
  onInlineEdit: (uuid: string, key: string, value: unknown) => Promise<void>;
  visibility: VisibilityState;
  onVisibility: (v: VisibilityState) => void;
  globalFilter: string;
  onCount?: (n: number) => void;
}

interface EditingCell {
  uuid: string;
  key: string;
}

function DataGridInner({ entity, rows, selected, onSelect, onOpen, onInlineEdit, visibility, onVisibility, globalFilter, onCount }: Props) {
  const { t } = useTranslation();
  const [sorting, setSorting] = useState<SortingState>([]);
  const [colFilters, setColFilters] = useState<ColumnFiltersState>([]);
  const [editing, setEditing] = useState<EditingCell | null>(null);
  const [editError, setEditError] = useState<string | null>(null);
  const lastClicked = useRef<number | null>(null);
  const scroller = useRef<HTMLDivElement>(null);

  const canUpdate = entity.actions.update;

  const columns = useMemo<ColumnDef<LocalRecord>[]>(
    () =>
      Object.entries(entity.schema).map(([key, def]) => ({
        id: key,
        header: fieldLabel(key, def),
        accessorFn: (r: LocalRecord) => r.data[key],
        size: def.type === 'html' || def.type === 'text' ? 260 : def.type === 'bool' ? 90 : def.type === 'int' ? 110 : 180,
        sortingFn: (a, b, id) => {
          const x = sortValue(def, a.getValue(id));
          const y = sortValue(def, b.getValue(id));
          return x < y ? -1 : x > y ? 1 : 0;
        },
        filterFn: (row, id, filter: string) => {
          const v = row.getValue(id);
          if (def.type === 'enum' || def.type === 'bool') return filter === '' || String(v ?? '') === filter || (def.type === 'bool' && cellText(def, v) === (filter === 'true' ? '✓' : '–'));
          return cellText(def, v).toLowerCase().includes(filter.toLowerCase());
        },
        meta: { def },
      })),
    [entity.schema],
  );

  const table = useReactTable({
    data: rows,
    columns,
    state: { sorting, columnFilters: colFilters, columnVisibility: visibility, globalFilter },
    onSortingChange: setSorting,
    onColumnFiltersChange: setColFilters,
    onColumnVisibilityChange: (u) => onVisibility(typeof u === 'function' ? u(visibility) : u),
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getRowId: (r) => r.uuid,
    globalFilterFn: (row, _id, q: string) => {
      const needle = q.toLowerCase();
      if (!needle) return true;
      for (const [key, def] of Object.entries(entity.schema)) {
        if (cellText(def, row.original.data[key]).toLowerCase().includes(needle)) return true;
      }
      return String(row.original.remote_id ?? '').includes(needle);
    },
  });

  const modelRows = table.getRowModel().rows;
  const visibleCols = table.getVisibleLeafColumns();
  const totalWidth = W_SELECT + W_STATUS + W_OPEN + visibleCols.reduce((s, c) => s + c.getSize(), 0);

  const shownCount = modelRows.length;
  useEffect(() => {
    onCount?.(shownCount);
  }, [shownCount, onCount]);

  const virt = useVirtualizer({
    count: modelRows.length,
    getScrollElement: () => scroller.current,
    estimateSize: () => ROW_H,
    overscan: 14,
  });

  const toggleRow = useCallback(
    (index: number, uuid: string, shift: boolean) => {
      const next = new Set(selected);
      if (shift && lastClicked.current !== null) {
        const [a, b] = [Math.min(lastClicked.current, index), Math.max(lastClicked.current, index)];
        for (let i = a; i <= b; i++) next.add(modelRows[i].original.uuid);
      } else if (next.has(uuid)) next.delete(uuid);
      else next.add(uuid);
      lastClicked.current = index;
      onSelect(next);
    },
    [selected, modelRows, onSelect],
  );

  const allSelected = modelRows.length > 0 && modelRows.every((r) => selected.has(r.original.uuid));

  async function commit(r: LocalRecord, key: string, def: FieldDef, raw: string | boolean) {
    const value = typeof raw === 'boolean' ? raw : coerceInput(def, raw);
    const blankRequired = def.required && (value === '' || value === null);
    const err = blankRequired ? { key: 'required' } : validateValue(def, value);
    if (err) {
      setEditError(t(`validation.${err.key}`, { ...(err.params ?? {}) }));
      return;
    }
    setEditError(null);
    setEditing(null);
    await onInlineEdit(r.uuid, key, value);
  }

  const gridTemplate = `${W_SELECT}px ${W_OPEN}px ${W_STATUS}px ${visibleCols.map((c) => c.getSize() + 'px').join(' ')}`;

  return (
    <div ref={scroller} className="relative h-full overflow-auto bg-white dark:bg-slate-900" data-testid="grid-scroller">
      <div style={{ width: totalWidth, minWidth: '100%' }}>
        {/* header */}
        <div className="sticky top-0 z-20 border-b border-slate-200 bg-slate-100 dark:border-slate-800 dark:bg-slate-800" style={{ display: 'grid', gridTemplateColumns: gridTemplate }}>
          <div className="flex h-9 items-center justify-center">
            <input
              type="checkbox"
              className="h-4 w-4 accent-brand-600"
              checked={allSelected}
              onChange={() => onSelect(allSelected ? new Set() : new Set(modelRows.map((r) => r.original.uuid)))}
              aria-label={t('grid.selectAll')}
            />
          </div>
          <div />
          <div className="flex h-9 items-center px-2 text-xs font-semibold text-slate-600 dark:text-slate-300">{t('grid.status')}</div>
          {table.getHeaderGroups()[0].headers.filter((h) => h.column.getIsVisible()).map((h) => {
            const sorted = h.column.getIsSorted();
            const def = h.column.columnDef.meta as { def: FieldDef };
            return (
              <button
                key={h.id}
                className="flex h-9 items-center gap-1 px-2 text-left text-xs font-semibold text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-700"
                onClick={h.column.getToggleSortingHandler()}
                title={t('grid.sort')}
              >
                <span className="truncate">{flexRender(h.column.columnDef.header, h.getContext())}</span>
                {def.def.readonly && <Lock size={10} className="shrink-0 opacity-50" />}
                {sorted === 'asc' ? <ArrowUp size={12} /> : sorted === 'desc' ? <ArrowDown size={12} /> : <ArrowUpDown size={12} className="opacity-30" />}
              </button>
            );
          })}
        </div>
        {/* per-column filters */}
        <div className="sticky top-9 z-10 border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900" style={{ display: 'grid', gridTemplateColumns: gridTemplate }}>
          <div />
          <div />
          <div />
          {visibleCols.map((c) => {
            const def = (c.columnDef.meta as { def: FieldDef }).def;
            const val = (c.getFilterValue() as string) ?? '';
            return (
              <div key={c.id} className="p-1">
                {def.type === 'enum' || def.type === 'bool' ? (
                  <select className="input h-7 text-xs" value={val} onChange={(e) => c.setFilterValue(e.target.value || undefined)} aria-label={t('grid.filter')}>
                    <option value="">{t('grid.all')}</option>
                    {def.type === 'bool' ? (
                      <>
                        <option value="true">✓</option>
                        <option value="false">–</option>
                      </>
                    ) : (
                      enumOptions(def).map((o) => (
                        <option key={o.value} value={o.value}>
                          {o.label}
                        </option>
                      ))
                    )}
                  </select>
                ) : (
                  <input className="input h-7 text-xs" placeholder={t('grid.filter')} value={val} onChange={(e) => c.setFilterValue(e.target.value || undefined)} />
                )}
              </div>
            );
          })}
        </div>

        {/* virtualized body */}
        <div style={{ height: virt.getTotalSize(), position: 'relative' }}>
          {modelRows.length === 0 && <div className="absolute inset-x-0 top-8 text-center text-sm text-slate-500">{t('grid.empty')}</div>}
          {virt.getVirtualItems().map((vi) => {
            const row = modelRows[vi.index];
            const r = row.original;
            const isSel = selected.has(r.uuid);
            const deleted = r.status === 'deleted';
            return (
              <div
                key={row.id}
                data-index={vi.index}
                className={`absolute left-0 top-0 w-full border-b border-slate-100 text-sm dark:border-slate-800 ${isSel ? 'bg-brand-50 dark:bg-brand-600/20' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50'} ${deleted ? 'text-slate-400 line-through' : ''}`}
                style={{ height: ROW_H, transform: `translateY(${vi.start}px)`, display: 'grid', gridTemplateColumns: gridTemplate }}
              >
                <div className="flex items-center justify-center">
                  <input
                    type="checkbox"
                    className="h-4 w-4 accent-brand-600"
                    checked={isSel}
                    onChange={() => undefined}
                    onClick={(e) => toggleRow(vi.index, r.uuid, e.shiftKey)}
                    aria-label={t('grid.selectRow')}
                  />
                </div>
                <button className="flex items-center justify-center text-slate-400 hover:text-brand-600" onClick={() => onOpen(r.uuid)} title={t('grid.open')} aria-label={t('grid.open')}>
                  <ChevronRight size={16} />
                </button>
                <div className="flex items-center px-2">
                  <span className={`badge no-underline ${STATUS_STYLE[r.status]}`}>{t(`status.${r.status}`)}</span>
                </div>
                {visibleCols.map((c) => {
                  const def = (c.columnDef.meta as { def: FieldDef }).def;
                  const v = r.data[c.id];
                  const editable = canUpdate && !deleted && !def.readonly && !(def.create_only && r.status !== 'new') && INLINE_TYPES.includes(def.type);
                  const isEditing = editing?.uuid === r.uuid && editing.key === c.id;
                  if (isEditing) {
                    return (
                      <div key={c.id} className="relative flex items-center px-1">
                        {def.type === 'enum' ? (
                          <select
                            autoFocus
                            className="input h-7"
                            defaultValue={v === null || v === undefined ? '' : String(v)}
                            onChange={(e) => void commit(r, c.id, def, e.target.value)}
                            onBlur={() => setEditing(null)}
                            onKeyDown={(e) => e.key === 'Escape' && setEditing(null)}
                          >
                            <option value="">{t('form.choose')}</option>
                            {enumOptions(def).map((o) => (
                              <option key={o.value} value={o.value}>
                                {o.label}
                              </option>
                            ))}
                          </select>
                        ) : (
                          <input
                            autoFocus
                            className={`input h-7 ${editError ? 'input-error' : ''}`}
                            type={def.type === 'date' ? 'date' : def.type === 'int' || def.type === 'float' ? 'number' : 'text'}
                            step={def.type === 'float' ? 'any' : undefined}
                            defaultValue={v === null || v === undefined ? '' : String(v)}
                            title={editError ?? undefined}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') void commit(r, c.id, def, (e.target as HTMLInputElement).value);
                              if (e.key === 'Escape') {
                                setEditing(null);
                                setEditError(null);
                              }
                            }}
                            onBlur={(e) => {
                              if (!editError) void commit(r, c.id, def, e.target.value);
                              else {
                                setEditing(null);
                                setEditError(null);
                              }
                            }}
                          />
                        )}
                        {editError && <span className="absolute left-1 top-full z-30 rounded bg-red-600 px-2 py-0.5 text-xs text-white">{editError}</span>}
                      </div>
                    );
                  }
                  if (def.type === 'bool' && editable) {
                    return (
                      <div key={c.id} className="flex items-center px-2">
                        <input type="checkbox" className="h-4 w-4 accent-brand-600" checked={v === true || v === 1 || v === '1'} onChange={(e) => void commit(r, c.id, def, e.target.checked)} />
                      </div>
                    );
                  }
                  return (
                    <div
                      key={c.id}
                      className={`flex items-center truncate px-2 ${editable ? 'cursor-text' : ''} ${def.readonly ? 'text-slate-500' : ''}`}
                      title={cellText(def, v)}
                      onDoubleClick={() => {
                        if (editable) {
                          setEditError(null);
                          setEditing({ uuid: r.uuid, key: c.id });
                        } else onOpen(r.uuid);
                      }}
                    >
                      <span className="truncate">{cellText(def, v)}</span>
                    </div>
                  );
                })}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

export default memo(DataGridInner);
