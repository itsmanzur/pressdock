import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, CheckCircle2, Globe, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useApp } from '../state/app';
import { ApiError, PressdockApi, normalizeSiteUrl, type Handshake } from '../api/client';
import { getPassword, openRepo, removeSite, saveSite, type Site } from '../sites/siteStore';
import { platform } from '../platform';
import { takeConnectLink, type ConnectLink } from '../sites/connectLink';
import { FieldWrap, Modal } from './ui';
import type { Entity } from '../schema/types';

export const CONNECTOR_URL = 'https://wordpress.org/plugins/pressdock/';

function errorMessage(e: unknown, t: (k: string, o?: Record<string, unknown>) => string): { text: string; install?: boolean } {
  if (e instanceof ApiError) {
    switch (e.kind) {
      case 'not_installed':
        return { text: t('sites.err.notInstalled'), install: true };
      case 'auth':
        return { text: t('sites.err.auth') };
      case 'forbidden':
        return { text: t('sites.err.forbidden') };
      case 'insecure':
        return { text: t('sites.err.insecure') };
      case 'timeout':
        return { text: t('sites.err.timeout') };
      case 'network':
        return { text: t('sites.err.network', { detail: e.message }) };
      default:
        return { text: t('sites.err.http', { detail: e.message }) };
    }
  }
  return { text: e instanceof Error ? e.message : String(e) };
}

interface TestResult {
  handshake: Handshake;
  entities: Entity[];
}

export default function SitesView() {
  const { t } = useTranslation();
  const { sites, site: current, selectSite, reloadSites, reloadMeta, online, toast } = useApp();
  const [editing, setEditing] = useState<Site | 'new' | null>(null);
  const [prefill, setPrefill] = useState<ConnectLink | null>(null);

  // "Open in Pressdock" link from the WordPress dashboard: reuse a matching site, else prefill a new one
  useEffect(() => {
    const link = takeConnectLink();
    if (!link) return;
    const url = normalizeSiteUrl(link.url);
    const existing = sites.find((s) => normalizeSiteUrl(s.url) === url && s.username.toLowerCase() === link.username.toLowerCase());
    if (existing) setEditing(existing);
    else {
      setPrefill(link);
      setEditing('new');
    }
    // run once on mount
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (sites.length === 0) setEditing((e) => e ?? 'new');
  }, [sites.length]);

  async function refreshSchema(s: Site) {
    try {
      const pw = await getPassword(s.id);
      if (!pw) throw new Error(t('sites.noPassword'));
      const api = new PressdockApi({ url: s.url, username: s.username, password: pw });
      const [hs, ents] = await Promise.all([api.handshake(), api.entities()]);
      const repo = await openRepo(s.id);
      await repo.saveEntities(ents);
      await saveSite({ ...s, wpSiteName: hs.site.name, lastConnectedAt: new Date().toISOString() });
      await reloadSites();
      if (current?.id === s.id) await reloadMeta();
      toast(t('sites.schemaUpdated', { count: ents.length }), 'success');
    } catch (e) {
      toast(errorMessage(e, t as never).text, 'error');
    }
  }

  async function remove(s: Site) {
    const repo = await openRepo(s.id);
    const pending = await repo.pendingCount();
    const msg = pending > 0 ? t('sites.confirmRemovePending', { name: s.name, count: pending }) : t('sites.confirmRemove', { name: s.name });
    if (!window.confirm(msg)) return;
    await removeSite(s.id);
    await reloadSites();
    toast(t('sites.removed'), 'success');
  }

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-3xl">
        <div className="mb-4 flex items-center justify-between">
          <h1 className="text-xl font-semibold">{t('sites.title')}</h1>
          <button className="btn btn-primary" onClick={() => setEditing('new')}>
            <Plus size={14} /> {t('sites.add')}
          </button>
        </div>
        {sites.length === 0 && <p className="text-slate-500">{t('sites.none')}</p>}
        <div className="grid gap-3">
          {sites.map((s) => (
            <div key={s.id} className={`card flex items-center gap-3 p-4 ${current?.id === s.id ? 'ring-2 ring-brand-500/40' : ''}`}>
              <Globe className="text-brand-600" size={22} />
              <div className="min-w-0 flex-1">
                <div className="font-semibold">
                  {s.name}
                  {current?.id === s.id && <span className="badge ml-2 bg-brand-100 text-brand-700">{t('sites.active')}</span>}
                </div>
                <div className="truncate text-xs text-slate-500">
                  {s.url} · {s.username}
                </div>
                {s.lastConnectedAt && <div className="text-xs text-slate-400">{t('sites.lastConnected', { date: new Date(s.lastConnectedAt).toLocaleString() })}</div>}
              </div>
              {current?.id !== s.id && (
                <button className="btn" onClick={() => void selectSite(s.id)}>
                  {t('sites.open')}
                </button>
              )}
              <button className="btn btn-icon" disabled={!online} title={t('sites.refreshSchema')} aria-label={t('sites.refreshSchema')} onClick={() => void refreshSchema(s)}>
                <RefreshCw size={14} />
              </button>
              <button className="btn btn-icon" title={t('common.edit')} aria-label={t('common.edit')} onClick={() => setEditing(s)}>
                <Pencil size={14} />
              </button>
              <button className="btn btn-icon" title={t('common.delete')} aria-label={t('common.delete')} onClick={() => void remove(s)}>
                <Trash2 size={14} />
              </button>
            </div>
          ))}
        </div>
      </div>
      {editing && (
        <SiteDialog
          site={editing === 'new' ? null : editing}
          prefill={editing === 'new' ? prefill : null}
          onClose={() => {
            setEditing(null);
            setPrefill(null);
          }}
        />
      )}
    </div>
  );
}

function SiteDialog({ site, prefill, onClose }: { site: Site | null; prefill?: ConnectLink | null; onClose: () => void }) {
  const { t } = useTranslation();
  const { online, reloadSites, selectSite, site: currentSite, reloadMeta, toast } = useApp();
  const [name, setName] = useState(site?.name ?? '');
  const [url, setUrl] = useState(site?.url ?? prefill?.url ?? '');
  const [username, setUsername] = useState(site?.username ?? prefill?.username ?? '');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  useEffect(() => {
    if (!platform.isTauri) void platform.kv.get<boolean>('remember-passwords').then((v) => setRemember(v === true));
  }, []);
  const insecureFromHttps = !platform.isTauri && location.protocol === 'https:' && /^http:\/\//i.test(url.trim());
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<TestResult | null>(null);
  const [error, setError] = useState<{ text: string; install?: boolean } | null>(null);

  const valid = name.trim() && url.trim() && username.trim() && (site || password.trim());

  async function test(): Promise<TestResult | null> {
    setBusy(true);
    setError(null);
    setResult(null);
    try {
      const pw = password.trim() || (site ? await getPassword(site.id) : null);
      if (!pw) throw new Error(t('sites.noPassword'));
      const api = new PressdockApi({ url, username: username.trim(), password: pw });
      const handshake = await api.handshake();
      const entities = await api.entities();
      const r = { handshake, entities };
      setResult(r);
      if (!name.trim()) setName(handshake.site.name);
      return r;
    } catch (e) {
      setError(errorMessage(e, t as never));
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function save() {
    setBusy(true);
    try {
      const id = site?.id ?? crypto.randomUUID();
      const record: Site = {
        id,
        name: name.trim(),
        url: normalizeSiteUrl(url),
        username: username.trim(),
        createdAt: site?.createdAt ?? new Date().toISOString(),
        wpSiteName: result?.handshake.site.name ?? site?.wpSiteName,
        lastConnectedAt: result ? new Date().toISOString() : site?.lastConnectedAt,
      };
      // desktop: password goes to the OS keychain only; web: this tab, or this browser if the user opted in
      if (!platform.isTauri) await platform.kv.set('remember-passwords', remember);
      const pw = password.trim() || (!platform.isTauri && site ? ((await getPassword(site.id)) ?? undefined) : undefined);
      await saveSite(record, pw);
      if (result) {
        const repo = await openRepo(id);
        await repo.saveEntities(result.entities);
      }
      await reloadSites();
      if (!site || currentSite?.id === id) {
        if (!site) await selectSite(id);
        else await reloadMeta();
      }
      toast(t('sites.saved'), 'success');
      onClose();
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), 'error');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      wide
      title={site ? t('sites.edit') : t('sites.add')}
      onClose={onClose}
      footer={
        <>
          <button className="btn" onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button className="btn" disabled={busy || !url.trim() || !username.trim() || (!site && !password.trim()) || !online} onClick={() => void test()}>
            {busy ? t('sites.testing') : t('sites.test')}
          </button>
          <button className="btn btn-primary" disabled={busy || !valid} onClick={() => void save()}>
            {t('common.save')}
          </button>
        </>
      }
    >
      <div className="grid gap-3">
        <FieldWrap label={t('sites.name')}>
          <input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="My WordPress site" />
        </FieldWrap>
        <FieldWrap label={t('sites.url')}>
          <input className="input" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://example.com" />
        </FieldWrap>
        <FieldWrap label={t('sites.username')}>
          <input className="input" autoComplete="off" value={username} onChange={(e) => setUsername(e.target.value)} />
        </FieldWrap>
        <FieldWrap label={t('sites.appPassword')} hint={site ? t('sites.keepPassword') : undefined}>
          <input className="input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" />
        </FieldWrap>
        {platform.isTauri ? (
          <p className="text-xs text-slate-500">{t('sites.keychainNote')}</p>
        ) : (
          <div className="space-y-1">
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" className="accent-brand-600" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
              {t('sites.rememberPassword')}
            </label>
            <p className="text-xs text-slate-500">{remember ? t('sites.rememberNote') : t('sites.webNote')}</p>
          </div>
        )}
        {insecureFromHttps && <p className="text-xs text-amber-600">{t('sites.mixedContent')}</p>}
        {!online && <p className="text-xs text-amber-600">{t('sites.offlineNote')}</p>}

        {error && (
          <div className="flex gap-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
            <AlertTriangle size={16} className="mt-0.5 shrink-0" />
            <div>
              <p>{error.text}</p>
              {error.install && (
                <button className="mt-2 underline" onClick={() => void platform.openUrl(CONNECTOR_URL)}>
                  {t('sites.installConnector')}
                </button>
              )}
            </div>
          </div>
        )}
        {result && (
          <div className="rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm dark:border-emerald-900 dark:bg-emerald-950/30">
            <p className="flex items-center gap-2 font-medium text-emerald-800 dark:text-emerald-300">
              <CheckCircle2 size={16} /> {t('sites.connected', { site: result.handshake.site.name })}
            </p>
            <p className="mt-1 text-xs text-slate-600 dark:text-slate-400">
              WordPress {result.handshake.site.wp_version} · Connector {result.handshake.site.plugin_version} · API {result.handshake.site.api_version} · {result.handshake.user.display_name}
              {!result.handshake.https && ' · HTTP'}
            </p>
            <p className="mt-2 text-xs font-semibold">{t('sites.accessible', { count: result.entities.length })}</p>
            <ul className="mt-1 grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
              {result.entities.map((e) => (
                <li key={e.key}>
                  <span className="text-slate-500">{e.group}</span> · {e.label}{' '}
                  <span className="text-slate-400">({(['read', 'create', 'update', 'delete'] as const).filter((a) => e.actions[a]).map((a) => a[0].toUpperCase()).join('')})</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Modal>
  );
}
