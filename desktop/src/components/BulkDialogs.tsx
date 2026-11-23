import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useApp } from '../state/app';
import { fieldLabel, type Entity, type LocalRecord } from '../schema/types';
import { recordLabel } from '../lib/recordLabel';
import { bulkFields, planBulk, replaceableFields, type BulkAction } from '../io/bulk';
import FieldInput from './fields/FieldInput';
import { FieldWrap, Modal } from './ui';

type Tab = 'set' | 'replace' | 'clear';

const show = (v: unknown): string => (v === null || v === undefined || v === '' ? '∅' : Array.isArray(v) ? v.join(' | ') : typeof v === 'object' ? JSON.stringify(v) : String(v));

const labelFor = (entity: Entity) => (r: LocalRecord) => recordLabel(entity.schema, r.data, `#${r.remote_id ?? r.uuid.slice(0, 8)}`);

/** Bulk edit: set a field / find & replace in a text field / clear a field — always with a preview of affected rows. */
export default function BulkDialogs({ entity, records, onClose, onDone }: { entity: Entity; records: LocalRecord[]; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation();
  const { repo, toast } = useApp();
  const fields = useMemo(() => bulkFields(entity), [entity]);
  const textFields = useMemo(() => replaceableFields(entity), [entity]);
  const [tab, setTab] = useState<Tab>('set');
  const [field, setField] = useState(fields[0] ?? '');
  const [value, setValue] = useState<unknown>(entity.schema[fields[0]]?.type === 'bool' ? false : '');
  const [find, setFind] = useState('');
  const [replace, setReplace] = useState('');
  const [matchCase, setMatchCase] = useState(false);
  const [whole, setWhole] = useState(false);
  const [busy, setBusy] = useState(false);

  const activeField = tab === 'replace' ? (textFields.includes(field) ? field : textFields[0] ?? '') : field;
  const def = entity.schema[activeField];

  const plan = useMemo(() => {
    if (!def) return null;
    const action: BulkAction | null =
      tab === 'set' ? { kind: 'set', field: activeField, value: value === '' && def.type !== 'string' && def.type !== 'text' && def.type !== 'html' ? null : value }
      : tab === 'clear' ? { kind: 'clear', field: activeField }
      : find ? { kind: 'replace', field: activeField, find, replace, matchCase, wholeValue: whole }
      : null;
    return action ? planBulk(entity, records, action, labelFor(entity)) : null;
  }, [tab, activeField, def, value, find, replace, matchCase, whole, entity, records]);

  const fieldPicker = (list: string[]) => (
    <FieldWrap label={t('grid.field')}>
      <select
        className="input"
        value={activeField}
        onChange={(e) => {
          setField(e.target.value);
          setValue(entity.schema[e.target.value].type === 'bool' ? false : '');
        }}
      >
        {list.map((k) => (
          <option key={k} value={k}>
            {fieldLabel(k, entity.schema[k])}
          </option>
        ))}
      </select>
    </FieldWrap>
  );

  async function apply() {
    if (!plan || !repo) return;
    setBusy(true);
    try {
      const n = await repo.updateEach(entity, plan.changes.map((c) => ({ uuid: c.uuid, patch: c.patch })));
      toast(t('grid.bulkDone', { count: n }), 'success');
      onDone();
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), 'error');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      wide
      title={t('grid.bulkEditTitle', { count: records.length })}
      onClose={onClose}
      footer={
        <>
          <button className="btn" onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button className="btn btn-primary" disabled={busy || !plan || plan.changes.length === 0} onClick={() => void apply()}>
            {t('bulk.apply', { count: plan?.changes.length ?? 0 })}
          </button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="flex gap-1 border-b border-slate-200 dark:border-slate-800">
          {(['set', 'replace', 'clear'] as Tab[]).map((k) => (
            <button
              key={k}
              disabled={k === 'replace' && textFields.length === 0}
              className={`px-3 py-1.5 text-sm ${tab === k ? 'border-b-2 border-brand-600 font-medium text-brand-700 dark:text-brand-100' : 'text-slate-500'} disabled:opacity-40`}
              onClick={() => setTab(k)}
            >
              {t(`bulk.tab.${k}`)}
            </button>
          ))}
        </div>

        {tab === 'set' && (
          <>
            {fieldPicker(fields)}
            {def && (
              <FieldWrap label={t('grid.newValue')}>
                <FieldInput def={def} value={value} onChange={setValue} />
              </FieldWrap>
            )}
          </>
        )}
        {tab === 'replace' && (
          <>
            {fieldPicker(textFields)}
            <div className="grid grid-cols-2 gap-3">
              <FieldWrap label={t('bulk.find')}>
                <input className="input" value={find} onChange={(e) => setFind(e.target.value)} />
              </FieldWrap>
              <FieldWrap label={t('bulk.replaceWith')}>
                <input className="input" value={replace} onChange={(e) => setReplace(e.target.value)} />
              </FieldWrap>
            </div>
            <div className="flex gap-4 text-sm">
              <label className="flex items-center gap-1.5">
                <input type="checkbox" className="accent-brand-600" checked={matchCase} onChange={(e) => setMatchCase(e.target.checked)} />
                {t('bulk.matchCase')}
              </label>
              <label className="flex items-center gap-1.5">
                <input type="checkbox" className="accent-brand-600" checked={whole} onChange={(e) => setWhole(e.target.checked)} />
                {t('bulk.wholeValue')}
              </label>
            </div>
          </>
        )}
        {tab === 'clear' && fieldPicker(fields)}

        {plan && (
          <div className="rounded-md border border-slate-200 dark:border-slate-700">
            <div className="flex flex-wrap gap-x-4 border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-800/50">
              <b>{t('bulk.willChange', { count: plan.changes.length })}</b>
              <span className="text-slate-500">{t('bulk.unchanged', { count: plan.unchanged })}</span>
              {plan.invalid.length > 0 && <span className="text-red-600">{t('bulk.invalid', { count: plan.invalid.length })}</span>}
            </div>
            <div className="max-h-56 overflow-auto">
              <table className="w-full text-xs">
                <tbody>
                  {plan.changes.slice(0, 50).map((c) => (
                    <tr key={c.uuid} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="max-w-[10rem] truncate p-2 font-medium">{c.label}</td>
                      <td className="max-w-[12rem] truncate p-2 text-red-700 line-through dark:text-red-300">{show(c.before)}</td>
                      <td className="max-w-[12rem] truncate p-2 text-emerald-700 dark:text-emerald-300">{show(c.after)}</td>
                    </tr>
                  ))}
                  {plan.invalid.slice(0, 20).map((c) => (
                    <tr key={c.uuid} className="border-t border-slate-100 bg-red-50 dark:border-slate-800 dark:bg-red-900/10">
                      <td className="p-2 font-medium">{c.label}</td>
                      <td colSpan={2} className="p-2 text-red-600">
                        {t(c.key, { ...(c.params ?? {}) })}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {plan.changes.length > 50 && <p className="p-2 text-xs text-slate-500">{t('bulk.more', { count: plan.changes.length - 50 })}</p>}
          </div>
        )}
        <p className="text-xs text-slate-500">{t('bulk.localOnly')}</p>
      </div>
    </Modal>
  );
}

/** Bulk delete confirmation: shows the exact count and a sample, deletes locally (server on next sync). */
export function BulkDeleteDialog({ entity, records, onClose, onDone }: { entity: Entity; records: LocalRecord[]; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation();
  const { repo, toast } = useApp();
  const [busy, setBusy] = useState(false);
  const label = labelFor(entity);
  const synced = records.filter((r) => r.status !== 'new').length;
  return (
    <Modal
      title={t('bulk.deleteTitle', { count: records.length })}
      onClose={onClose}
      footer={
        <>
          <button className="btn" onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button
            className="btn btn-danger"
            disabled={busy}
            onClick={async () => {
              setBusy(true);
              try {
                const n = await repo!.deleteRecords(records.map((r) => r.uuid));
                toast(t('grid.deleted', { count: n }), 'success');
                onDone();
              } catch (e) {
                toast(e instanceof Error ? e.message : String(e), 'error');
                setBusy(false);
              }
            }}
          >
            {t('bulk.deleteConfirm', { count: records.length })}
          </button>
        </>
      }
    >
      <div className="space-y-2 text-sm">
        <p>{t('grid.confirmDelete', { count: records.length })}</p>
        {synced > 0 && <p className="text-amber-700 dark:text-amber-400">{t('bulk.deleteSynced', { count: synced })}</p>}
        <ul className="max-h-40 overflow-auto rounded border border-slate-200 p-2 text-xs dark:border-slate-700">
          {records.slice(0, 15).map((r) => (
            <li key={r.uuid} className="truncate">
              {label(r)}
            </li>
          ))}
          {records.length > 15 && <li className="text-slate-500">{t('bulk.more', { count: records.length - 15 })}</li>}
        </ul>
      </div>
    </Modal>
  );
}
