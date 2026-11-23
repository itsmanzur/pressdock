import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useApp } from '../state/app';
import type { SyncPlan } from '../sync/types';
import { recordLabel } from '../lib/recordLabel';
import { Modal } from './ui';

/** Safety preview shown before deletes or more than 20 changes are pushed. */
export default function SyncPreview({ plan, onAnswer }: { plan: SyncPlan; onAnswer: (ok: boolean) => void }) {
  const { t } = useTranslation();
  const { repo, entities } = useApp();
  const [labels, setLabels] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!repo) return;
    void (async () => {
      const uuids = plan.groups.flatMap((g) => g.samples.map((s) => s.uuid));
      const recs = await repo.getRecords(uuids);
      const out: Record<string, string> = {};
      for (const r of recs) {
        const schema = entities.find((e) => e.key === r.entity)?.schema ?? {};
        out[r.uuid] = recordLabel(schema, r.data, r.uuid.slice(0, 8));
      }
      setLabels(out);
    })();
  }, [repo, plan, entities]);

  return (
    <Modal
      wide
      title={t('sync.previewTitle')}
      onClose={() => onAnswer(false)}
      footer={
        <>
          <button className="btn" onClick={() => onAnswer(false)}>
            {t('common.cancel')}
          </button>
          <button className={`btn ${plan.deletes ? 'btn-danger' : 'btn-primary'}`} onClick={() => onAnswer(true)}>
            {t('sync.confirmPush', { count: plan.total })}
          </button>
        </>
      }
    >
      <p className="mb-3 text-sm">{t('sync.previewIntro', { creates: plan.creates, updates: plan.updates, deletes: plan.deletes })}</p>
      <div className="space-y-3">
        {plan.groups.map((g) => {
          const label = entities.find((e) => e.key === g.entity)?.label ?? g.entity;
          return (
            <div key={g.entity} className="card p-3">
              <div className="mb-1 flex items-center gap-2 font-medium">
                {label}
                <span className="badge bg-sky-100 text-sky-800">+{g.creates}</span>
                <span className="badge bg-amber-100 text-amber-800">~{g.updates}</span>
                <span className="badge bg-red-100 text-red-800">−{g.deletes}</span>
              </div>
              <ul className="text-xs text-slate-600 dark:text-slate-400">
                {g.samples.map((s) => (
                  <li key={s.uuid}>
                    <span className={s.op === 'delete' ? 'text-red-600' : s.op === 'create' ? 'text-sky-600' : 'text-amber-600'}>{t(`sync.op.${s.op}`)}</span> {labels[s.uuid] ?? '…'}
                  </li>
                ))}
                {g.creates + g.updates + g.deletes > g.samples.length && <li>… {t('sync.andMore', { count: g.creates + g.updates + g.deletes - g.samples.length })}</li>}
              </ul>
            </div>
          );
        })}
      </div>
      {plan.deletes > 0 && <p className="mt-3 text-xs text-slate-500">{t('sync.deleteNote')}</p>}
    </Modal>
  );
}
