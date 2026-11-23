import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useApp } from '../state/app';
import { useSync } from '../state/sync';
import type { SyncLogRow } from '../db/repo';
import type { Choice, Resolution } from '../sync/resolve';
import { defaultPick, diffFields, resolveConflict, undoSyncBatch } from '../sync/resolve';
import { coerceInput } from '../schema/validate';
import { fieldLabel, INLINE_TYPES, type LocalRecord } from '../schema/types';
import { recordLabel } from '../lib/recordLabel';
import { cellText } from './DataGrid';

const show = (def: Parameters<typeof cellText>[0], v: unknown) => cellText(def, v) || '—';

export function ConflictsView() {
  const { t } = useTranslation();
  const { repo, entities, toast } = useApp();
  const { syncNow } = useSync();
  const [list, setList] = useState<LocalRecord[]>([]);
  const [open, setOpen] = useState<string | null>(null);
  const [choices, setChoices] = useState<Record<string, Choice>>({});

  const load = useCallback(async () => {
    if (repo) setList(await repo.listConflicts());
  }, [repo]);
  useEffect(() => {
    void load();
    return repo?.subscribe(() => void load());
  }, [repo, load]);

  const rec = list.find((r) => r.uuid === open) ?? null;
  const entity = rec ? entities.find((e) => e.key === rec.entity) : undefined;

  async function resolve(r: Resolution) {
    if (!repo || !rec || !entity) return;
    await resolveConflict(repo, entity, rec.uuid, r);
    toast(t('conflicts.resolved'), 'success');
    setOpen(null);
    setChoices({});
  }

  return (
    <div className="flex h-full">
      <div className="w-72 shrink-0 overflow-y-auto border-r border-slate-200 p-3 dark:border-slate-800">
        <h1 className="mb-3 text-lg font-semibold">{t('conflicts.title')}</h1>
        {list.length === 0 && <p className="text-sm text-slate-500">{t('conflicts.none')}</p>}
        {list.map((r) => {
          const e = entities.find((x) => x.key === r.entity);
          return (
            <button
              key={r.uuid}
              onClick={() => {
                setOpen(r.uuid);
                setChoices({});
              }}
              className={`mb-1 w-full rounded-md px-2.5 py-2 text-left text-sm ${open === r.uuid ? 'bg-brand-100 dark:bg-brand-600/25' : 'hover:bg-slate-100 dark:hover:bg-slate-800'}`}
            >
              <div className="truncate font-medium">{recordLabel(e?.schema ?? {}, r.data, r.uuid.slice(0, 8))}</div>
              <div className="text-xs text-slate-500">
                {e?.label ?? r.entity}
                {r.server?.deleted ? ` · ${t('conflicts.deletedOnServer')}` : ''}
                {r.local_deleted ? ` · ${t('conflicts.deletedLocally')}` : ''}
              </div>
            </button>
          );
        })}
        {list.length === 0 && (
          <button className="btn mt-2" onClick={() => void syncNow()}>
            {t('top.sync')}
          </button>
        )}
      </div>
      <div className="min-w-0 flex-1 overflow-y-auto p-5">
        {!rec || !entity ? (
          <p className="text-slate-500">{t('conflicts.pick')}</p>
        ) : rec.server?.deleted || rec.local_deleted ? (
          <div className="max-w-xl space-y-3">
            <h2 className="text-base font-semibold">{recordLabel(entity.schema, rec.data, rec.uuid.slice(0, 8))}</h2>
            <p className="text-sm">{rec.server?.deleted ? t('conflicts.msgServerDeleted') : t('conflicts.msgLocalDeleted')}</p>
            <div className="flex gap-2">
              <button className="btn btn-primary" onClick={() => void resolve('mine')}>
                {rec.server?.deleted ? t('conflicts.recreate') : t('conflicts.deleteAnyway')}
              </button>
              <button className="btn" onClick={() => void resolve('server')}>
                {rec.server?.deleted ? t('conflicts.acceptDelete') : t('conflicts.keepServerRecord')}
              </button>
            </div>
          </div>
        ) : (
          <div>
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <h2 className="mr-auto text-base font-semibold">{recordLabel(entity.schema, rec.data, rec.uuid.slice(0, 8))}</h2>
              {rec.server?._reason === 'unique' && <span className="badge bg-amber-100 text-amber-800">{t('conflicts.unique', { field: rec.server._field ?? '' })}</span>}
              <button className="btn" onClick={() => void resolve('mine')}>
                {t('conflicts.allMine')}
              </button>
              <button className="btn" onClick={() => void resolve('server')}>
                {t('conflicts.allServer')}
              </button>
              <button className="btn btn-primary" onClick={() => void resolve(choices)}>
                {t('conflicts.applyChoices')}
              </button>
            </div>
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-500">
                  <th className="p-2">{t('grid.field')}</th>
                  <th className="p-2">{t('conflicts.mine')}</th>
                  <th className="p-2">{t('conflicts.server')}</th>
                  <th className="p-2">{t('conflicts.keep')}</th>
                </tr>
              </thead>
              <tbody>
                {diffFields(entity, rec).map((d) => {
                  const choice = choices[d.key] ?? { pick: defaultPick(d.local, d.server) };
                  const hl = d.differs ? 'bg-amber-50 dark:bg-amber-900/20' : '';
                  return (
                    <tr key={d.key} className={`border-t border-slate-100 dark:border-slate-800 ${hl}`} data-differs={d.differs}>
                      <td className="p-2 font-medium">{fieldLabel(d.key, d.def)}</td>
                      <td className="max-w-56 break-words p-2">{show(d.def, d.local)}</td>
                      <td className="max-w-56 break-words p-2">{show(d.def, d.server)}</td>
                      <td className="p-2">
                        {d.differs && !d.def.readonly ? (
                          <div className="flex flex-wrap items-center gap-2 text-xs">
                            {(['mine', 'server', 'manual'] as const).map((p) => (
                              <label key={p} className={`flex items-center gap-1 ${p === 'manual' && !INLINE_TYPES.includes(d.def.type) ? 'hidden' : ''}`}>
                                <input
                                  type="radio"
                                  name={`c-${d.key}`}
                                  checked={choice.pick === p}
                                  onChange={() => setChoices({ ...choices, [d.key]: p === 'manual' ? { pick: 'manual', value: d.local ?? '' } : { pick: p } })}
                                />
                                {t(`conflicts.${p}`)}
                              </label>
                            ))}
                            {choice.pick === 'manual' && (
                              <input
                                className="input h-7 w-40"
                                defaultValue={String(choice.value ?? '')}
                                onChange={(e) => setChoices({ ...choices, [d.key]: { pick: 'manual', value: coerceInput(d.def, e.target.value) } })}
                              />
                            )}
                          </div>
                        ) : d.def.readonly ? (
                          <span className="text-xs text-slate-400">{t('conflicts.serverOwned')}</span>
                        ) : null}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

export function HistoryView() {
  const { t } = useTranslation();
  const { repo, toast } = useApp();
  const { api, syncNow, connected } = useSync();
  const [rows, setRows] = useState<SyncLogRow[]>([]);

  const load = useCallback(async () => {
    if (repo) setRows(await repo.listSyncLog());
  }, [repo]);
  useEffect(() => {
    void load();
    return repo?.subscribe(() => void load());
  }, [repo, load]);

  async function undo(row: SyncLogRow) {
    if (!repo || !window.confirm(t('history.confirmUndo'))) return;
    const a = await api();
    if (!a) return;
    try {
      const r = await undoSyncBatch(repo, a, row);
      toast(t('history.undone', { count: r.restored }), r.errors ? 'error' : 'success');
      await syncNow();
    } catch (e) {
      const x = e as { status?: number; message?: string };
      toast(x.status === 403 ? t('history.adminOnly') : x.message ?? String(e), 'error');
    }
  }

  return (
    <div className="h-full overflow-y-auto p-5">
      <h1 className="mb-3 text-xl font-semibold">{t('history.title')}</h1>
      {rows.length === 0 && <p className="text-slate-500">{t('history.none')}</p>}
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="text-left text-xs text-slate-500">
            <th className="p-2">{t('history.when')}</th>
            <th className="p-2">{t('history.pushed')}</th>
            <th className="p-2">{t('history.pulled')}</th>
            <th className="p-2">{t('history.conflicts')}</th>
            <th className="p-2">{t('history.errors')}</th>
            <th className="p-2" />
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id} className="border-t border-slate-100 dark:border-slate-800">
              <td className="p-2">
                {new Date(r.started_at).toLocaleString()}
                {!r.finished_at && <span className="ml-2 text-xs text-amber-600">{t('history.interrupted')}</span>}
                {r.error && <div className="text-xs text-red-600">{r.error}</div>}
              </td>
              <td className="p-2">{r.pushed}</td>
              <td className="p-2">{r.pulled}</td>
              <td className="p-2">{r.conflicts}</td>
              <td className="p-2">{r.errors}</td>
              <td className="p-2 text-right">
                {r.undone_at ? (
                  <span className="text-xs text-slate-500">{t('history.wasUndone')}</span>
                ) : (
                  r.batch_id &&
                  r.pushed > 0 && (
                    <button className="btn" disabled={!connected} onClick={() => void undo(r)}>
                      {t('history.undo')}
                    </button>
                  )
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
