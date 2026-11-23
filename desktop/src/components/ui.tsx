import { useEffect, type ReactNode } from 'react';
import { X } from 'lucide-react';

export function Modal({
  title,
  onClose,
  children,
  footer,
  wide,
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
  footer?: ReactNode;
  wide?: boolean;
}) {
  useEffect(() => {
    const h = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [onClose]);
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
      <div className={`card flex max-h-[90vh] w-full flex-col shadow-xl ${wide ? 'max-w-2xl' : 'max-w-md'}`}>
        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
          <h2 className="text-base font-semibold">{title}</h2>
          <button className="btn btn-ghost btn-icon" onClick={onClose} aria-label="Close">
            <X size={16} />
          </button>
        </div>
        <div className="overflow-y-auto p-4">{children}</div>
        {footer && (
          <div className="flex justify-end gap-2 border-t border-slate-200 px-4 py-3 dark:border-slate-800">{footer}</div>
        )}
      </div>
    </div>
  );
}

export function FieldWrap({
  label,
  required,
  error,
  hint,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div>
      <label className="label">
        {label}
        {required && <span className="ml-0.5 text-red-500">*</span>}
        {hint && <span className="ml-1 font-normal text-slate-400">{hint}</span>}
      </label>
      {children}
      {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
    </div>
  );
}

export const STATUS_STYLE: Record<string, string> = {
  synced: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
  dirty: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  new: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
  deleted: 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  conflict: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
};
