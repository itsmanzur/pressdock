import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, Save, Trash2 } from 'lucide-react';
import { useApp } from '../state/app';
import { validateRecord, type ValidationErrors } from '../schema/validate';
import { fieldLabel, type LocalRecord, type RecordData } from '../schema/types';
import FieldInput from './fields/FieldInput';
import { FieldWrap, STATUS_STYLE } from './ui';

const isBlank = (v: unknown) => v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0);

export default function RecordForm({ entityKey, uuid }: { entityKey: string; uuid: string | null }) {
  const { t } = useTranslation();
  const { repo, entities, navigate, toast } = useApp();
  const entity = entities.find((e) => e.key === entityKey);
  const [rec, setRec] = useState<LocalRecord | null>(null);
  const [values, setValues] = useState<RecordData>({});
  const [errors, setErrors] = useState<ValidationErrors>({});
  const [loaded, setLoaded] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!repo || !entity) return;
    let alive = true;
    void (async () => {
      if (uuid) {
        const r = await repo.getRecord(uuid);
        if (!alive) return;
        setRec(r);
        setValues(r?.data ?? {});
      } else {
        const init: RecordData = {};
        for (const [k, d] of Object.entries(entity.schema)) if (d.type === 'bool') init[k] = false;
        setValues(init);
      }
      setLoaded(true);
    })();
    return () => {
      alive = false;
    };
  }, [repo, entity, uuid]);

  const isNew = !uuid;
  const readOnlyAll = !!rec && (rec.status === 'deleted' || (entity ? !entity.actions.update : false));
  const canCreate = entity?.actions.create ?? false;

  const tErr = useMemo(() => {
    const out: Record<string, string> = {};
    for (const [k, e] of Object.entries(errors)) out[k] = t(`validation.${e.key}`, { ...(e.params ?? {}) });
    return out;
  }, [errors, t]);

  if (!entity) return null;
  if (!loaded) return <div className="p-6 text-slate-500">…</div>;
  if (uuid && !rec) return <div className="p-6">{t('form.notFound')}</div>;

  const back = () => navigate({ name: 'entity', key: entityKey });

  async function save() {
    if (!repo || !entity) return;
    // drop untouched blanks on create; keep user-cleared values on update
    const data: RecordData = isNew ? Object.fromEntries(Object.entries(values).filter(([, v]) => !isBlank(v))) : values;
    const errs = validateRecord(entity.schema, data);
    setErrors(errs);
    if (Object.keys(errs).length) {
      toast(t('form.fixErrors', { count: Object.keys(errs).length }), 'error');
      return;
    }
    setSaving(true);
    try {
      if (isNew) await repo.createRecord(entity, data);
      else await repo.updateRecord(entity, uuid!, data);
      toast(t('form.saved'), 'success');
      back();
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), 'error');
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    if (!repo || !uuid) return;
    if (!window.confirm(t('grid.confirmDelete', { count: 1 }))) return;
    await repo.deleteRecords([uuid]);
    toast(t('grid.deleted', { count: 1 }), 'success');
    back();
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-3 border-b border-slate-200 px-5 py-3 dark:border-slate-800">
        <button className="btn btn-ghost btn-icon" onClick={back} aria-label={t('common.back')}>
          <ArrowLeft size={16} />
        </button>
        <h1 className="text-lg font-semibold">{isNew ? t('form.newTitle', { entity: entity.label }) : t('form.editTitle', { entity: entity.label })}</h1>
        {rec && <span className={`badge ${STATUS_STYLE[rec.status]}`}>{t(`status.${rec.status}`)}</span>}
        {rec?.remote_id && <span className="text-xs text-slate-500">#{rec.remote_id}</span>}
        <span className="flex-1" />
        {!isNew && !readOnlyAll && entity.actions.delete && (
          <button className="btn" onClick={() => void remove()}>
            <Trash2 size={14} /> {t('common.delete')}
          </button>
        )}
        <button className="btn" onClick={back}>
          {t('common.cancel')}
        </button>
        <button className="btn btn-primary" disabled={saving || readOnlyAll || (isNew && !canCreate)} onClick={() => void save()}>
          <Save size={14} /> {t('common.save')}
        </button>
      </div>
      <div className="flex-1 overflow-y-auto p-5">
        {rec?.status === 'deleted' && <p className="mb-3 rounded-md bg-slate-200 p-3 text-sm dark:bg-slate-800">{t('form.deletedNotice')}</p>}
        {dirty && <p className="mb-3 text-xs text-amber-600">{t('form.unsaved')}</p>}
        <div className="mx-auto grid max-w-3xl gap-4">
          {Object.entries(entity.schema).map(([key, def]) => {
            const locked = readOnlyAll || !!def.readonly || (!isNew && rec?.status !== 'new' && !!def.create_only);
            const hint = [def.readonly ? t('form.readonly') : '', def.type === 'datetime' ? 'GMT' : ''].filter(Boolean).join(' · ');
            return (
              <FieldWrap key={key} label={fieldLabel(key, def)} required={def.required && !def.readonly} error={tErr[key]} hint={hint}>
                <FieldInput
                  def={def}
                  value={values[key]}
                  disabled={locked}
                  invalid={!!errors[key]}
                  errors={tErr}
                  path={key}
                  onChange={(v) => {
                    setValues((s) => ({ ...s, [key]: v }));
                    setDirty(true);
                    if (errors[key]) setErrors((s) => {
                      const n = { ...s };
                      delete n[key];
                      return n;
                    });
                  }}
                />
              </FieldWrap>
            );
          })}
        </div>
      </div>
    </div>
  );
}
