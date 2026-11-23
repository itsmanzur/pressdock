import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, X } from 'lucide-react';
import { useApp } from '../../state/app';
import { recordLabel, recordRef } from '../../lib/recordLabel';
import type { LocalRecord } from '../../schema/types';

/** Searchable picker over LOCAL records of the target entity (works offline). */
export default function RelationPicker({
  target,
  value,
  onChange,
  disabled,
  invalid,
}: {
  target: string;
  value: unknown;
  onChange: (v: string | number | null) => void;
  disabled?: boolean;
  invalid?: boolean;
}) {
  const { t } = useTranslation();
  const { repo, entities } = useApp();
  const schema = entities.find((e) => e.key === target)?.schema ?? {};
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [items, setItems] = useState<LocalRecord[]>([]);
  const [current, setCurrent] = useState<LocalRecord | null>(null);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let alive = true;
    if (!repo || value === null || value === undefined || value === '') {
      setCurrent(null);
      return;
    }
    void repo.findByRef(target, value).then((r) => alive && setCurrent(r));
    return () => {
      alive = false;
    };
  }, [repo, target, value]);

  useEffect(() => {
    if (!open || !repo) return;
    let alive = true;
    void repo.searchRecords(target, q.trim(), 50).then((r) => alive && setItems(r));
    return () => {
      alive = false;
    };
  }, [open, q, repo, target]);

  useEffect(() => {
    if (!open) return;
    const h = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);

  const has = value !== null && value !== undefined && value !== '';
  const shown = current ? recordLabel(schema, current.data, `#${current.remote_id ?? current.uuid.slice(0, 8)}`) : has ? `#${String(value)}` : '';

  return (
    <div className="relative" ref={box}>
      <div className="flex gap-1">
        <button
          type="button"
          disabled={disabled}
          className={`input flex items-center justify-between text-left ${invalid ? 'input-error' : ''}`}
          onClick={() => setOpen((o) => !o)}
        >
          <span className={shown ? '' : 'text-slate-400'}>{shown || t('form.selectRelation')}</span>
          <ChevronDown size={14} />
        </button>
        {has && !disabled && (
          <button type="button" className="btn btn-icon" onClick={() => onChange(null)} aria-label={t('form.clear')}>
            <X size={14} />
          </button>
        )}
      </div>
      {open && (
        <div className="card absolute z-30 mt-1 w-full p-2 shadow-lg">
          <input autoFocus className="input mb-2" placeholder={t('form.searchRecords')} value={q} onChange={(e) => setQ(e.target.value)} />
          <ul className="max-h-56 overflow-y-auto">
            {items.length === 0 && <li className="px-2 py-2 text-xs text-slate-500">{t('form.noRecords')}</li>}
            {items.map((r) => (
              <li key={r.uuid}>
                <button
                  type="button"
                  className="w-full rounded px-2 py-1.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800"
                  onClick={() => {
                    onChange(recordRef(r));
                    setOpen(false);
                  }}
                >
                  {recordLabel(schema, r.data, `#${r.remote_id ?? r.uuid.slice(0, 8)}`)}
                  <span className="ml-2 text-xs text-slate-400">{r.remote_id ? `#${r.remote_id}` : t('status.new')}</span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
