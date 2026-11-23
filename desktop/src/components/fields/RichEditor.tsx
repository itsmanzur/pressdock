import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bold, Code, Heading2, Italic, Link2, List, ListOrdered } from 'lucide-react';
import { sanitizeHtml } from '../../lib/html';

/** Small WYSIWYG for `html` fields (contentEditable + execCommand) with an HTML-source toggle. */
export default function RichEditor({
  value,
  onChange,
  disabled,
}: {
  value: string;
  onChange: (html: string) => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  const ref = useRef<HTMLDivElement>(null);
  const [source, setSource] = useState(false);

  // push external value into the DOM only when it differs (keeps the caret stable while typing)
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const clean = sanitizeHtml(value || '');
    if (el.innerHTML !== clean) el.innerHTML = clean;
  }, [value, source]);

  const cmd = (name: string, arg?: string) => {
    ref.current?.focus();
    document.execCommand(name, false, arg);
    if (ref.current) onChange(ref.current.innerHTML);
  };

  const btn = 'btn btn-ghost btn-icon h-7 w-7';
  return (
    <div>
      <div className="flex flex-wrap items-center gap-0.5 rounded-t-md border border-b-0 border-slate-300 bg-slate-50 px-1 py-1 dark:border-slate-700 dark:bg-slate-800">
        <button type="button" className={btn} disabled={disabled || source} onClick={() => cmd('bold')} title={t('editor.bold')}>
          <Bold size={14} />
        </button>
        <button type="button" className={btn} disabled={disabled || source} onClick={() => cmd('italic')} title={t('editor.italic')}>
          <Italic size={14} />
        </button>
        <button type="button" className={btn} disabled={disabled || source} onClick={() => cmd('formatBlock', 'h2')} title={t('editor.heading')}>
          <Heading2 size={14} />
        </button>
        <button type="button" className={btn} disabled={disabled || source} onClick={() => cmd('insertUnorderedList')} title={t('editor.bullets')}>
          <List size={14} />
        </button>
        <button type="button" className={btn} disabled={disabled || source} onClick={() => cmd('insertOrderedList')} title={t('editor.numbered')}>
          <ListOrdered size={14} />
        </button>
        <button
          type="button"
          className={btn}
          disabled={disabled || source}
          title={t('editor.link')}
          onClick={() => {
            const url = window.prompt(t('editor.linkPrompt'), 'https://');
            if (url && /^(https?:|mailto:)/i.test(url)) cmd('createLink', url);
          }}
        >
          <Link2 size={14} />
        </button>
        <span className="flex-1" />
        <button type="button" className={`${btn} ${source ? 'bg-slate-200 dark:bg-slate-700' : ''}`} onClick={() => setSource((s) => !s)} title={t('editor.source')}>
          <Code size={14} />
        </button>
      </div>
      {source ? (
        <textarea
          className="input rounded-t-none font-mono"
          rows={8}
          value={value || ''}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value)}
        />
      ) : (
        <div
          ref={ref}
          className="rte"
          contentEditable={!disabled}
          suppressContentEditableWarning
          onInput={(e) => onChange((e.target as HTMLDivElement).innerHTML)}
        />
      )}
    </div>
  );
}
