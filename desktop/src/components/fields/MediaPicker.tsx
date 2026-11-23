import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Paperclip, X } from 'lucide-react';
import { useApp } from '../../state/app';
import { fileToBase64, formatBytes } from '../../lib/html';

const MAX_BYTES = 25 * 1024 * 1024;

/**
 * `media` fields hold a WordPress attachment id. Offline you can pick a local file: it is stored in the
 * site database (`media_pending`) and the value becomes `pending:<id>`; the sync engine uploads it later.
 */
export default function MediaPicker({
  value,
  onChange,
  disabled,
  invalid,
}: {
  value: unknown;
  onChange: (v: string | number | null) => void;
  disabled?: boolean;
  invalid?: boolean;
}) {
  const { t } = useTranslation();
  const { repo, toast } = useApp();
  const input = useRef<HTMLInputElement>(null);
  const [meta, setMeta] = useState<{ name: string; size: number } | null>(null);
  const v = value === null || value === undefined ? '' : String(value);
  const pendingId = v.startsWith('pending:') ? v.slice(8) : null;

  useEffect(() => {
    if (!repo || !pendingId) {
      setMeta(null);
      return;
    }
    let alive = true;
    void repo.getPendingMediaMeta(pendingId).then((m) => alive && setMeta(m));
    return () => {
      alive = false;
    };
  }, [repo, pendingId]);

  async function pick(file: File | undefined) {
    if (!file || !repo) return;
    if (file.size > MAX_BYTES) {
      toast(t('form.fileTooLarge', { max: '25 MB' }), 'error');
      return;
    }
    const id = await repo.savePendingMedia({
      name: file.name,
      mime: file.type || 'application/octet-stream',
      size: file.size,
      dataB64: await fileToBase64(file),
    });
    onChange(`pending:${id}`);
  }

  return (
    <div className="flex items-center gap-2">
      {pendingId ? (
        <div className={`input flex items-center justify-between ${invalid ? 'input-error' : ''}`}>
          <span className="truncate">
            <Paperclip size={12} className="mr-1 inline" />
            {meta ? `${meta.name} (${formatBytes(meta.size)})` : pendingId}
            <span className="ml-2 text-xs text-amber-600">{t('form.uploadPending')}</span>
          </span>
        </div>
      ) : (
        <input
          className={`input ${invalid ? 'input-error' : ''}`}
          type="number"
          min={1}
          disabled={disabled}
          placeholder={t('form.attachmentId')}
          value={v}
          onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
        />
      )}
      <input ref={input} type="file" hidden onChange={(e) => void pick(e.target.files?.[0])} />
      {!disabled && (
        <button type="button" className="btn" onClick={() => input.current?.click()}>
          {t('form.chooseFile')}
        </button>
      )}
      {v && !disabled && (
        <button type="button" className="btn btn-icon" onClick={() => onChange(null)} aria-label={t('form.clear')}>
          <X size={14} />
        </button>
      )}
    </div>
  );
}
