import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, ArrowRight, Download, FileUp, Save, Upload } from 'lucide-react';
import { useApp } from '../state/app';
import { platform } from '../platform';
import { fieldLabel, type Entity } from '../schema/types';
import { applyTemplate, autoMap, REMOTE_ID, targetsFor, toTemplate, UUID, type MappingTemplate } from '../io/mapping';
import type { ParsedSheet } from '../io/parse';
import type { ImportConfig, ImportMode, PlanContext, PlannedRow, PlanSummary } from '../io/plan';
import { parseInWorker, planInWorker } from '../io/run';
import { exportEntity } from '../io/save';
import { FieldWrap } from './ui';

type Step = 'file' | 'map' | 'preview' | 'done';
const STEPS: Step[] = ['file', 'map', 'preview', 'done'];

function Progress({ value, label }: { value: number; label?: string }) {
  return (
    <div className="space-y-1" role="progressbar" aria-valuenow={Math.round(value * 100)} aria-valuemin={0} aria-valuemax={100}>
      <div className="h-2 overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
        <div className="h-full bg-brand-600 transition-all" style={{ width: `${Math.round(value * 100)}%` }} />
      </div>
      {label && <p className="text-xs text-slate-500">{label}</p>}
    </div>
  );
}

export default function ImportWizard({ entityKey }: { entityKey: string | null }) {
  const { t } = useTranslation();
  const { repo, entities, site, navigate, toast } = useApp();
  const importable = useMemo(() => entities.filter((e) => e.actions.create || e.actions.update), [entities]);
  const [key, setKey] = useState(entityKey ?? importable[0]?.key ?? '');
  const entity = entities.find((e) => e.key === key);

  const [step, setStep] = useState<Step>('file');
  const [file, setFile] = useState<{ name: string; buf: ArrayBuffer } | null>(null);
  const [parsed, setParsed] = useState<ParsedSheet | null>(null);
  const [busy, setBusy] = useState<{ label: string; p: number } | null>(null);
  const [mapping, setMapping] = useState<string[]>([]);
  const [mode, setMode] = useState<ImportMode>('create');
  const [matchBy, setMatchBy] = useState<string>(REMOTE_ID);
  const [relMatch, setRelMatch] = useState<Record<string, string>>({});
  const [plan, setPlan] = useState<{ rows: PlannedRow[]; summary: PlanSummary } | null>(null);
  const [onlyBad, setOnlyBad] = useState(false);
  const [skipInvalid, setSkipInvalid] = useState(true);
  const [templates, setTemplates] = useState<MappingTemplate[]>([]);
  const [result, setResult] = useState<{ created: number; updated: number; skipped: number } | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);

  const tplKey = `imptpl:${site?.id}:${key}`;
  useEffect(() => {
    void platform.kv.get<MappingTemplate[]>(tplKey).then((v) => setTemplates(v ?? []));
  }, [tplKey]);

  if (!repo || !entity) return <div className="p-6 text-slate-500">{t('import.noEntity')}</div>;

  const targets = targetsFor(entity);
  const relFields = Object.entries(entity.schema).filter(([k, d]) => d.type === 'relation' && mapping.includes(k));
  const needsMatch = mode !== 'create';
  const matchChoices = [
    { key: REMOTE_ID, label: t('import.matchRemote') },
    { key: UUID, label: 'UUID' },
    ...Object.entries(entity.schema)
      .filter(([, d]) => !d.readonly && ['string', 'int', 'enum'].includes(d.type))
      .map(([k, d]) => ({ key: k, label: fieldLabel(k, d) })),
  ];
  const matchColumnMapped = mapping.includes(matchBy);

  async function chooseFile(f: File) {
    setBusy({ label: t('import.reading'), p: 0 });
    try {
      const buf = await f.arrayBuffer();
      setFile({ name: f.name, buf });
      const p = await parseInWorker(buf, f.name, undefined, (v) => setBusy({ label: t('import.reading'), p: v }));
      setParsed(p);
      setMapping(autoMap(p.headers, entity!));
      setPlan(null);
      setResult(null);
      if (!p.rows.length) toast(t('import.emptyFile'), 'error');
    } catch (e) {
      toast(`${t('import.readFailed')}: ${e instanceof Error ? e.message : String(e)}`, 'error');
      setParsed(null);
    } finally {
      setBusy(null);
    }
  }

  async function chooseSheet(sheet: string) {
    if (!file) return;
    setBusy({ label: t('import.reading'), p: 0 });
    try {
      const p = await parseInWorker(file.buf, file.name, sheet, (v) => setBusy({ label: t('import.reading'), p: v }));
      setParsed(p);
      setMapping(autoMap(p.headers, entity!));
    } finally {
      setBusy(null);
    }
  }

  async function saveTemplate() {
    if (!parsed) return;
    const name = window.prompt(t('import.templateName'), entity!.label);
    if (!name) return;
    const next = [...templates.filter((x) => x.name !== name), toTemplate(name, parsed.headers, mapping)];
    setTemplates(next);
    await platform.kv.set(tplKey, next);
    toast(t('import.templateSaved'), 'success');
  }

  async function buildPreview() {
    if (!parsed) return;
    setBusy({ label: t('import.validating'), p: 0 });
    try {
      const existing = await repo!.listRecords(entity!.key, true);
      const related: PlanContext['related'] = {};
      for (const [, d] of Object.entries(entity!.schema)) {
        const tk = d.type === 'relation' ? d.target : undefined;
        const ent = tk ? entities.find((e: Entity) => e.key === tk) : undefined;
        if (ent && !related[ent.key]) related[ent.key] = { entity: ent, records: await repo!.listRecords(ent.key, false) };
      }
      const cfg: ImportConfig = { mode, matchBy, mapping, relationMatch: relMatch };
      const res = await planInWorker(parsed, cfg, { entity: entity!, existing, related }, (p) => setBusy({ label: t('import.validating'), p }));
      setPlan(res);
      setSkipInvalid(true);
      setOnlyBad(res.summary.invalid > 0);
      setStep('preview');
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), 'error');
    } finally {
      setBusy(null);
    }
  }

  async function doImport() {
    if (!plan) return;
    const good = plan.rows.filter((r) => !r.errors.length && r.action !== 'skip');
    setBusy({ label: t('import.importing'), p: 0 });
    try {
      const r = await repo!.importItems(
        entity!,
        good.map((g) => ({ action: g.action as 'create' | 'update', uuid: g.uuid, data: g.data })),
        (d, tot) => setBusy({ label: t('import.importing'), p: tot ? d / tot : 1 }),
      );
      setResult({ ...r, skipped: plan.rows.length - good.length });
      setStep('done');
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), 'error');
    } finally {
      setBusy(null);
    }
  }

  const unmappedRequired = Object.entries(entity.schema).filter(([k, d]) => d.required && !d.readonly && !mapping.includes(k));
  const noKeyColumn = needsMatch && !matchColumnMapped;
  const canNext = !!parsed && parsed.rows.length > 0 && mapping.some(Boolean) && !noKeyColumn;
  const idx = STEPS.indexOf(step);
  const canWrite = mode === 'update' ? entity.actions.update : mode === 'create' ? entity.actions.create : entity.actions.create && entity.actions.update;

  const visibleRows = plan ? (onlyBad ? plan.rows.filter((r) => r.errors.length || r.warnings.length) : plan.rows).slice(0, 200) : [];
  const shownCols = mapping.filter((m) => m && m !== REMOTE_ID && m !== UUID);
  const errText = (e: { key: string; params?: Record<string, string | number> }) => t(e.key, { ...(e.params ?? {}) });
  const importableCount = plan ? plan.rows.filter((r) => !r.errors.length && r.action !== 'skip').length : 0;
  const blocked = !!plan && plan.summary.invalid > 0 && !skipInvalid;

  return (
    <div className="mx-auto flex h-full max-w-5xl flex-col gap-4 overflow-y-auto p-6">
      <div className="flex items-center gap-3">
        <h1 className="text-lg font-semibold">{t('import.title')}</h1>
        <ol className="ml-4 flex gap-2 text-xs">
          {STEPS.map((s, i) => (
            <li key={s} className={`rounded-full px-2.5 py-1 ${i === idx ? 'bg-brand-600 text-white' : i < idx ? 'bg-brand-50 text-brand-700 dark:bg-brand-600/20' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'}`}>
              {i + 1}. {t(`import.step.${s}`)}
            </li>
          ))}
        </ol>
      </div>

      {busy && <Progress value={busy.p} label={busy.label} />}

      {step === 'file' && (
        <div className="card space-y-4 p-4">
          <FieldWrap label={t('import.entity')}>
            <select
              className="input max-w-xs"
              value={key}
              onChange={(e) => {
                setKey(e.target.value);
                setParsed(null);
                setMapping([]);
              }}
            >
              {importable.map((e) => (
                <option key={e.key} value={e.key}>
                  {e.label}
                </option>
              ))}
            </select>
          </FieldWrap>
          <div
            className="flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 hover:border-brand-600 dark:border-slate-700"
            onClick={() => fileInput.current?.click()}
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => {
              e.preventDefault();
              const f = e.dataTransfer.files[0];
              if (f) void chooseFile(f);
            }}
          >
            <FileUp size={28} />
            <span>{file ? file.name : t('import.drop')}</span>
            <span className="text-xs">CSV · XLSX</span>
            <input
              ref={fileInput}
              type="file"
              hidden
              accept=".csv,.tsv,.txt,.xlsx,.xls"
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) void chooseFile(f);
                e.target.value = '';
              }}
            />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs text-slate-500">{t('import.needTemplate')}</span>
            <button className="btn" onClick={() => void exportEntity({ entity, kind: 'template', format: 'xlsx', records: [] })}>
              <Download size={14} /> {t('export.templateXlsx')}
            </button>
            <button className="btn" onClick={() => void exportEntity({ entity, kind: 'template', format: 'csv', records: [] })}>
              <Download size={14} /> {t('export.templateCsv')}
            </button>
          </div>
          {parsed && (
            <div className="rounded-md bg-slate-50 p-3 text-sm dark:bg-slate-800/50">
              {t('import.parsed', { rows: parsed.rows.length, cols: parsed.headers.length })}
              {parsed.sheetNames.length > 1 && (
                <label className="ml-3 inline-flex items-center gap-2">
                  {t('import.sheet')}
                  <select className="input w-auto" value={parsed.sheet} onChange={(e) => void chooseSheet(e.target.value)}>
                    {parsed.sheetNames.map((s) => (
                      <option key={s}>{s}</option>
                    ))}
                  </select>
                </label>
              )}
            </div>
          )}
        </div>
      )}

      {step === 'map' && parsed && (
        <div className="space-y-4">
          <div className="card space-y-3 p-4">
            <h2 className="font-medium">{t('import.mode')}</h2>
            <div className="flex flex-wrap gap-4 text-sm">
              {(['create', 'update', 'upsert'] as ImportMode[]).map((m) => (
                <label key={m} className="flex items-start gap-2">
                  <input type="radio" className="mt-1 accent-brand-600" checked={mode === m} onChange={() => setMode(m)} />
                  <span>
                    <b>{t(`import.modes.${m}`)}</b>
                    <br />
                    <span className="text-xs text-slate-500">{t(`import.modes.${m}Hint`)}</span>
                  </span>
                </label>
              ))}
            </div>
            {needsMatch && (
              <FieldWrap label={t('import.matchBy')} hint={t('import.matchHint')}>
                <select className="input max-w-xs" value={matchBy} onChange={(e) => setMatchBy(e.target.value)}>
                  {matchChoices.map((c) => (
                    <option key={c.key} value={c.key}>
                      {c.label}
                    </option>
                  ))}
                </select>
                {noKeyColumn && <p className="mt-1 text-xs text-red-600">{t('import.matchUnmapped')}</p>}
              </FieldWrap>
            )}
          </div>

          <div className="card p-4">
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <h2 className="mr-auto font-medium">{t('import.mapping')}</h2>
              {templates.length > 0 && (
                <select
                  className="input w-auto"
                  value=""
                  onChange={(e) => {
                    const tpl = templates.find((x) => x.name === e.target.value);
                    if (tpl) setMapping(applyTemplate(parsed.headers, tpl, entity));
                  }}
                >
                  <option value="">{t('import.loadTemplate')}</option>
                  {templates.map((x) => (
                    <option key={x.name}>{x.name}</option>
                  ))}
                </select>
              )}
              <button className="btn" onClick={() => setMapping(autoMap(parsed.headers, entity))}>
                {t('import.autoMatch')}
              </button>
              <button className="btn" onClick={() => void saveTemplate()}>
                <Save size={14} /> {t('import.saveTemplate')}
              </button>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-slate-500">
                    <th className="p-2">{t('import.fileColumn')}</th>
                    <th className="p-2">{t('import.sample')}</th>
                    <th className="p-2">{t('import.mapTo')}</th>
                  </tr>
                </thead>
                <tbody>
                  {parsed.headers.map((h, i) => (
                    <tr key={i} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="p-2 font-medium">{h}</td>
                      <td className="max-w-xs truncate p-2 text-slate-500">
                        {parsed.rows
                          .slice(0, 3)
                          .map((r) => String(r[i] ?? ''))
                          .filter(Boolean)
                          .join(' · ')}
                      </td>
                      <td className="p-2">
                        <select
                          className="input"
                          value={mapping[i] ?? ''}
                          onChange={(e) => {
                            const v = e.target.value;
                            setMapping(mapping.map((m, j) => (j === i ? v : v && m === v ? '' : m)));
                          }}
                        >
                          <option value="">{t('import.ignore')}</option>
                          {targets.map((tg) => (
                            <option key={tg.key} value={tg.key}>
                              {tg.label}
                            </option>
                          ))}
                        </select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {mode !== 'update' && unmappedRequired.length > 0 && (
              <p className="mt-3 rounded bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                {t('import.unmappedRequired', { fields: unmappedRequired.map(([k, d]) => fieldLabel(k, d)).join(', ') })}
              </p>
            )}
          </div>

          {relFields.length > 0 && (
            <div className="card space-y-3 p-4">
              <h2 className="font-medium">{t('import.relations')}</h2>
              <p className="text-xs text-slate-500">{t('import.relationsHint')}</p>
              {relFields.map(([k, d]) => {
                const target = entities.find((e) => e.key === d.target);
                return (
                  <FieldWrap key={k} label={`${fieldLabel(k, d)} → ${target?.label ?? d.target}`}>
                    <select className="input max-w-xs" value={relMatch[k] ?? 'auto'} onChange={(e) => setRelMatch({ ...relMatch, [k]: e.target.value })}>
                      <option value="auto">{t('import.relAuto')}</option>
                      {target &&
                        Object.entries(target.schema)
                          .filter(([, td]) => ['string', 'int', 'enum'].includes(td.type))
                          .map(([tk, td]) => (
                            <option key={tk} value={tk}>
                              {fieldLabel(tk, td)}
                            </option>
                          ))}
                    </select>
                  </FieldWrap>
                );
              })}
            </div>
          )}
        </div>
      )}

      {step === 'preview' && plan && (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
            {(['total', 'create', 'update', 'skip', 'invalid'] as const).map((k) => (
              <div key={k} className={`card p-3 ${k === 'invalid' && plan.summary.invalid ? 'border-red-300 bg-red-50 dark:bg-red-900/10' : ''}`}>
                <div className="text-xs text-slate-500">{t(`import.sum.${k}`)}</div>
                <div className="text-xl font-semibold">{plan.summary[k].toLocaleString()}</div>
              </div>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-4 text-sm">
            <label className="flex items-center gap-1.5">
              <input type="checkbox" className="accent-brand-600" checked={onlyBad} onChange={(e) => setOnlyBad(e.target.checked)} />
              {t('import.onlyIssues')}
            </label>
            {plan.summary.invalid > 0 && (
              <label className="flex items-center gap-1.5">
                <input type="checkbox" className="accent-brand-600" checked={skipInvalid} onChange={(e) => setSkipInvalid(e.target.checked)} />
                {t('import.skipInvalid', { count: plan.summary.invalid })}
              </label>
            )}
            <span className="text-xs text-slate-500">{t('import.previewLimit')}</span>
          </div>
          <div className="card max-h-[48vh] overflow-auto">
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-slate-50 text-left dark:bg-slate-800">
                <tr>
                  <th className="p-2">#</th>
                  <th className="p-2">{t('import.action')}</th>
                  {shownCols.map((k) => (
                    <th key={k} className="whitespace-nowrap p-2">
                      {fieldLabel(k, entity.schema[k])}
                    </th>
                  ))}
                  <th className="p-2">{t('import.issues')}</th>
                </tr>
              </thead>
              <tbody>
                {visibleRows.map((r) => {
                  const bad = new Set(r.errors.map((e) => e.field));
                  return (
                    <tr key={r.rowNo} className={`border-t border-slate-100 dark:border-slate-800 ${r.errors.length ? 'bg-red-50/60 dark:bg-red-900/10' : ''}`}>
                      <td className="p-2 text-slate-500">{r.rowNo}</td>
                      <td className="p-2">{r.errors.length ? <span className="text-red-600">{t('import.sum.invalid')}</span> : t(`import.act.${r.action}`)}</td>
                      {shownCols.map((k) => (
                        <td key={k} className={`max-w-[14rem] truncate p-2 ${bad.has(k) ? 'bg-red-100 font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300' : ''}`}>
                          {r.data[k] === undefined ? '' : typeof r.data[k] === 'object' ? JSON.stringify(r.data[k]) : String(r.data[k])}
                        </td>
                      ))}
                      <td className="p-2">
                        {r.errors.map((e, i) => (
                          <div key={i} className="text-red-600 dark:text-red-400">
                            {e.field ? `${e.field}: ` : ''}
                            {errText(e)}
                          </div>
                        ))}
                        {r.warnings.map((e, i) => (
                          <div key={i} className="text-amber-700 dark:text-amber-400">
                            {e.field ? `${e.field}: ` : ''}
                            {errText(e)}
                          </div>
                        ))}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <p className="text-xs text-slate-500">{t('import.localOnly')}</p>
        </div>
      )}

      {step === 'done' && result && (
        <div className="card space-y-3 p-6 text-center">
          <Upload size={28} className="mx-auto text-brand-600" />
          <p className="text-base font-medium">{t('import.done', { created: result.created, updated: result.updated, skipped: result.skipped })}</p>
          <p className="text-sm text-slate-500">{t('import.localOnly')}</p>
          <div className="flex justify-center gap-2">
            <button className="btn btn-primary" onClick={() => navigate({ name: 'entity', key: entity.key })}>
              {t('import.openGrid')}
            </button>
            <button
              className="btn"
              onClick={() => {
                setStep('file');
                setParsed(null);
                setFile(null);
                setPlan(null);
              }}
            >
              {t('import.another')}
            </button>
          </div>
        </div>
      )}

      {step !== 'done' && (
        <div className="mt-auto flex justify-between border-t border-slate-200 pt-3 dark:border-slate-800">
          <button className="btn" disabled={!!busy} onClick={() => (idx === 0 ? navigate({ name: 'entity', key: entity.key }) : setStep(STEPS[idx - 1]))}>
            <ArrowLeft size={14} /> {idx === 0 ? t('common.cancel') : t('import.back')}
          </button>
          {step === 'file' && (
            <button className="btn btn-primary" disabled={!parsed || !parsed.rows.length || !!busy} onClick={() => setStep('map')}>
              {t('import.next')} <ArrowRight size={14} />
            </button>
          )}
          {step === 'map' && (
            <button className="btn btn-primary" disabled={!canNext || !!busy} onClick={() => void buildPreview()}>
              {t('import.preview')} <ArrowRight size={14} />
            </button>
          )}
          {step === 'preview' && (
            <button className="btn btn-primary" disabled={!!busy || blocked || importableCount === 0 || !canWrite} onClick={() => void doImport()}>
              {t('import.run', { count: importableCount })}
            </button>
          )}
        </div>
      )}
    </div>
  );
}
