import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus, X } from 'lucide-react';
import { enumOptions, type FieldDef, type RecordData } from '../../schema/types';
import { fromLocalInput, toLocalInput } from '../../lib/html';
import RichEditor from './RichEditor';
import RelationPicker from './RelationPicker';
import MediaPicker from './MediaPicker';

export interface FieldInputProps {
  def: FieldDef;
  value: unknown;
  onChange: (v: unknown) => void;
  disabled?: boolean;
  invalid?: boolean;
  errors?: Record<string, string>;
  path?: string;
}

function ListInput({ value, onChange, disabled, def, invalid }: FieldInputProps) {
  const { t } = useTranslation();
  const items = Array.isArray(value) ? (value as string[]) : [];
  const [draft, setDraft] = useState('');
  const add = () => {
    const v = draft.trim();
    if (!v) return;
    onChange([...items, v]);
    setDraft('');
  };
  return (
    <div>
      <div className="mb-1 flex flex-wrap gap-1">
        {items.map((it, i) => (
          <span key={i} className="badge bg-slate-200 dark:bg-slate-700">
            {it}
            {!disabled && (
              <button type="button" onClick={() => onChange(items.filter((_, j) => j !== i))} aria-label={t('form.remove')}>
                <X size={12} />
              </button>
            )}
          </span>
        ))}
      </div>
      {!disabled && (
        <div className="flex gap-1">
          <input
            className={`input ${invalid ? 'input-error' : ''}`}
            value={draft}
            maxLength={def.item_max_length}
            placeholder={t('form.addItem')}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                add();
              }
            }}
          />
          <button type="button" className="btn btn-icon" onClick={add} aria-label={t('form.addItem')}>
            <Plus size={14} />
          </button>
        </div>
      )}
    </div>
  );
}

/** One input per schema field type (spec §3). */
export default function FieldInput(props: FieldInputProps) {
  const { def, value, onChange, disabled, invalid } = props;
  const { t } = useTranslation();
  const cls = `input ${invalid ? 'input-error' : ''}`;
  const str = value === null || value === undefined ? '' : String(value);

  switch (def.type) {
    case 'bool':
      return (
        <label className="inline-flex h-8 items-center gap-2">
          <input
            type="checkbox"
            className="h-4 w-4 accent-brand-600"
            checked={value === true || value === 1 || value === '1'}
            disabled={disabled}
            onChange={(e) => onChange(e.target.checked)}
          />
        </label>
      );
    case 'int':
    case 'float':
      return (
        <input
          className={cls}
          type="number"
          step={def.type === 'int' ? 1 : 'any'}
          value={str}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
        />
      );
    case 'date':
      return <input className={cls} type="date" value={str} disabled={disabled} onChange={(e) => onChange(e.target.value)} />;
    case 'datetime':
      return (
        <input
          className={cls}
          type="datetime-local"
          value={toLocalInput(value)}
          disabled={disabled}
          onChange={(e) => onChange(fromLocalInput(e.target.value))}
        />
      );
    case 'enum':
      return (
        <select className={cls} value={str} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
          <option value="">{t('form.choose')}</option>
          {enumOptions(def).map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      );
    case 'text':
      return (
        <textarea
          className={cls}
          rows={4}
          value={str}
          maxLength={def.max_length}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value)}
        />
      );
    case 'html':
      return <RichEditor value={str} onChange={onChange} disabled={disabled} />;
    case 'relation':
      return <RelationPicker target={def.target ?? ''} value={value} onChange={onChange} disabled={disabled} invalid={invalid} />;
    case 'media':
      return <MediaPicker value={value} onChange={onChange} disabled={disabled} invalid={invalid} />;
    case 'list':
      return <ListInput {...props} />;
    case 'object': {
      const obj = (value && typeof value === 'object' && !Array.isArray(value) ? value : {}) as RecordData;
      return (
        <div className="space-y-3 rounded-md border border-slate-200 p-3 dark:border-slate-800">
          {Object.entries(def.properties ?? {}).map(([k, sub]) => {
            const path = `${props.path ?? ''}.${k}`.replace(/^\./, '');
            const err = props.errors?.[path];
            return (
              <div key={k}>
                <label className="label">
                  {sub.label || k}
                  {sub.required && <span className="ml-0.5 text-red-500">*</span>}
                </label>
                <FieldInput
                  def={sub}
                  value={obj[k]}
                  disabled={disabled || sub.readonly}
                  invalid={!!err}
                  errors={props.errors}
                  path={path}
                  onChange={(v) => onChange({ ...obj, [k]: v })}
                />
                {err && <p className="mt-1 text-xs text-red-600">{err}</p>}
              </div>
            );
          })}
        </div>
      );
    }
    default:
      return (
        <input
          className={cls}
          type="text"
          value={str}
          maxLength={def.max_length}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value)}
        />
      );
  }
}
