import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { VisibilityState } from '@tanstack/react-table';
import { Columns3, Download, Pencil, Plus, Search, Trash2, Upload, X } from 'lucide-react';
import { useApp } from '../state/app';
import { platform } from '../platform';
import { fieldLabel, type Entity, type LocalRecord } from '../schema/types';
import DataGrid, { type GridView } from './DataGrid';
import BulkDialogs, { BulkDeleteDialog } from './BulkDialogs';
import { exportEntity, type ExportFormat, type ExportKind } from '../io/save';
import { useRef } from 'react';

export default function EntityView({ entityKey }: { entityKey: string }) {
  const { t } = useTranslation();
  const { repo, entities, site, navigate, toast } = useApp();
  const entity = entities.find((e) => e.key === entityKey);
  const [rows, setRows] = useState<LocalRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [showDeleted, setShowDeleted] = useState(false);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [visibility, setVisibility] = useState<VisibilityState>({});
  const [colMenu, setColMenu] = useState(false);
  const [bulk, setBulk] = useState(false);
  const [confirmDel, setConfirmDel] = useState(false);
  const [exportMenu, setExportMenu] = useState(false);
  const viewRef = useRef<GridView | null>(null);
  const [shown, setShown] = useState(0);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const colsKey = `cols:${site?.id}:${entityKey}`;

  // sensible default: first 7 fields visible, rest hidden (user can change; persisted)
  useEffect(() => {
    if (!entity) return;
    let alive = true;
    void platform.kv.get<VisibilityState>(colsKey).then((saved) => {
      if (!alive) return;
      if (saved) setVisibility(saved);
      else {
        const v: VisibilityState = {};
        Object.keys(entity.schema).forEach((k, i) => (v[k] = i < 7));
        setVisibility(v);
      }
    });
    return () => {
      alive = false;
    };
  }, [colsKey, entity]);

  const load = useCallback(async () => {
    if (!repo) return;
    setRows(await repo.listRecords(entityKey, showDeleted));
    setErrors(await repo.outboxErrors(entityKey));
    setLoading(false);
  }, [repo, entityKey, showDeleted]);

  useEffect(() => {
    setLoading(true);
    setSelected(new Set());
    setSearch('');
    void load();
  }, [load]);

  // reload when local data changes elsewhere (e.g. form saved, later: sync)
  useEffect(() => {
    if (!repo) return;
    return repo.subscribe(() => void load());
  }, [repo, load]);

  const onVisibility = useCallback(
    (v: VisibilityState) => {
      setVisibility(v);
      void platform.kv.set(colsKey, v);
    },
    [colsKey],
  );

  const onInlineEdit = useCallback(
    async (uuid: string, key: string, value: unknown) => {
      if (!repo || !entity) return;
      try {
        await repo.updateRecord(entity, uuid, { [key]: value });
      } catch (e) {
        toast(e instanceof Error ? e.message : String(e), 'error');
      }
    },
    [repo, entity, toast],
  );

  const writableFields = useMemo(
    () => Object.entries(entity?.schema ?? {}).filter(([, d]) => !d.readonly && !d.create_only),
    [entity],
  );

  if (!entity || !repo) return null;

  async function doExport(kind: ExportKind, format: ExportFormat) {
    setExportMenu(false);
    try {
      const all = kind === 'template' ? [] : await repo!.listRecords(entityKey, false);
      const v = viewRef.current;
      const ok = await exportEntity({ entity: entity as Entity, kind, format, records: all, view: v ? { uuids: v.uuids(), columns: v.columns() } : undefined });
      if (ok) toast(t('export.done'), 'success');
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), 'error');
    }
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
        <h1 className="mr-2 text-lg font-semibold">{entity.label}</h1>
        <span className="text-xs text-slate-500">{t('grid.rowCount', { count: shown, total: rows.length })}</span>
        <span className="flex-1" />
        <div className="relative w-56">
          <Search size={14} className="absolute left-2.5 top-2.5 text-slate-400" />
          <input className="input pl-8" placeholder={t('grid.search')} value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <label className="flex items-center gap-1.5 text-xs">
          <input type="checkbox" className="accent-brand-600" checked={showDeleted} onChange={(e) => setShowDeleted(e.target.checked)} />
          {t('grid.showDeleted')}
        </label>
        <div className="relative">
          <button className="btn" onClick={() => setColMenu((o) => !o)}>
            <Columns3 size={14} /> {t('grid.columns')}
          </button>
          {colMenu && (
            <div className="card absolute right-0 z-40 mt-1 max-h-80 w-56 overflow-y-auto p-2 shadow-lg">
              {Object.entries(entity.schema).map(([k, d]) => (
                <label key={k} className="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-100 dark:hover:bg-slate-800">
                  <input type="checkbox" className="accent-brand-600" checked={visibility[k] !== false} onChange={(e) => onVisibility({ ...visibility, [k]: e.target.checked })} />
                  {fieldLabel(k, d)}
                </label>
              ))}
              <button className="btn btn-ghost mt-1 w-full" onClick={() => setColMenu(false)}>
                {t('common.close')}
              </button>
            </div>
          )}
        </div>
        <div className="relative">
          <button className="btn" onClick={() => setExportMenu((o) => !o)}>
            <Download size={14} /> {t('export.button')}
          </button>
          {exportMenu && (
            <div className="card absolute right-0 z-40 mt-1 w-64 space-y-0.5 p-1.5 text-sm shadow-lg">
              {(
                [
                  ['view', t('export.view')],
                  ['all', t('export.all')],
                  ['template', t('export.template')],
                ] as [ExportKind, string][]
              ).map(([kind, label]) => (
                <div key={kind} className="flex items-center justify-between rounded px-2 py-1 hover:bg-slate-100 dark:hover:bg-slate-800">
                  <span>{label}</span>
                  <span className="flex gap-1">
                    <button className="btn px-2 py-0.5 text-xs" onClick={() => void doExport(kind, 'csv')}>
                      CSV
                    </button>
                    <button className="btn px-2 py-0.5 text-xs" onClick={() => void doExport(kind, 'xlsx')}>
                      XLSX
                    </button>
                  </span>
                </div>
              ))}
              <button className="btn btn-ghost w-full" onClick={() => setExportMenu(false)}>
                {t('common.close')}
              </button>
            </div>
          )}
        </div>
        {(entity.actions.create || entity.actions.update) && (
          <button className="btn" onClick={() => navigate({ name: 'import', key: entityKey })}>
            <Upload size={14} /> {t('import.button')}
          </button>
        )}
        {import.meta.env.DEV && (
          <button
            className="btn"
            title="DEV only"
            onClick={async () => {
              await repo.seedDemo(entity, 50000);
              toast('50,000 demo rows added', 'success');
            }}
          >
            +50k demo
          </button>
        )}
        {entity.actions.create && (
          <button className="btn btn-primary" onClick={() => navigate({ name: 'form', key: entityKey, uuid: null })}>
            <Plus size={14} /> {t('common.new')}
          </button>
        )}
      </div>

      {selected.size > 0 && (
        <div className="flex items-center gap-2 border-b border-brand-100 bg-brand-50 px-4 py-2 text-sm dark:border-slate-800 dark:bg-brand-600/10">
          <span className="font-medium">{t('grid.selected', { count: selected.size })}</span>
          {entity.actions.update && writableFields.length > 0 && (
            <button className="btn" onClick={() => setBulk(true)}>
              <Pencil size={14} /> {t('grid.bulkEdit')}
            </button>
          )}
          {entity.actions.delete && (
            <button className="btn" onClick={() => setConfirmDel(true)}>
              <Trash2 size={14} /> {t('common.delete')}
            </button>
          )}
          <button className="btn btn-ghost" onClick={() => setSelected(new Set())}>
            <X size={14} /> {t('grid.clearSelection')}
          </button>
        </div>
      )}

      <div className="min-h-0 flex-1">
        {loading ? (
          <div className="p-6 text-slate-500">…</div>
        ) : (
          <DataGrid
            entity={entity}
            rows={rows}
            selected={selected}
            onSelect={setSelected}
            onOpen={(uuid) => navigate({ name: 'form', key: entityKey, uuid })}
            onInlineEdit={onInlineEdit}
            visibility={visibility}
            onVisibility={onVisibility}
            globalFilter={search}
            onCount={setShown}
            errors={errors}
            viewRef={viewRef}
          />
        )}
      </div>

      {bulk && <BulkDialogs entity={entity} records={rows.filter((r) => selected.has(r.uuid))} onClose={() => setBulk(false)} onDone={() => { setBulk(false); setSelected(new Set()); }} />}
      {confirmDel && (
        <BulkDeleteDialog
          entity={entity}
          records={rows.filter((r) => selected.has(r.uuid))}
          onClose={() => setConfirmDel(false)}
          onDone={() => {
            setConfirmDel(false);
            setSelected(new Set());
          }}
        />
      )}
    </div>
  );
}
