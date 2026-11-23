import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PressdockApi } from '../api/client';
import { getPassword } from '../sites/siteStore';
import { platform } from '../platform';
import { SyncEngine, isSyncing } from '../sync/engine';
import type { SyncPlan, SyncSettings, SyncSummary } from '../sync/types';
import { useApp } from './app';
import SyncPreview from '../components/SyncPreview';

interface SyncCtx {
  syncing: boolean;
  /** navigator.onLine AND the site answered a lightweight /handshake */
  connected: boolean;
  lastSyncAt: string | null;
  settings: SyncSettings;
  setSettings(s: SyncSettings): void;
  syncNow(): Promise<void>;
  /** the API client for the current site (null while unavailable) */
  api(): Promise<PressdockApi | null>;
}

const Ctx = createContext<SyncCtx | null>(null);
export const useSync = (): SyncCtx => {
  const v = useContext(Ctx);
  if (!v) throw new Error('useSync outside provider');
  return v;
};

const DEFAULTS: SyncSettings = { autoSync: true, intervalMinutes: 5 };
const PING_MS = 30_000;

export function SyncProvider({ children }: { children: ReactNode }) {
  const { t } = useTranslation();
  const { site, repo, online, toast, reloadMeta } = useApp();
  const [syncing, setSyncing] = useState(false);
  const [reachable, setReachable] = useState<boolean | null>(null);
  const [lastSyncAt, setLastSyncAt] = useState<string | null>(null);
  const [settings, setSettingsState] = useState<SyncSettings>(DEFAULTS);
  const [confirmReq, setConfirmReq] = useState<{ plan: SyncPlan; resolve: (ok: boolean) => void } | null>(null);
  const apiRef = useRef<{ siteId: string; api: PressdockApi } | null>(null);
  const warned = useRef(false);

  useEffect(() => {
    void platform.kv.get<SyncSettings>('syncSettings').then((s) => s && setSettingsState({ ...DEFAULTS, ...s }));
  }, []);
  const setSettings = useCallback((s: SyncSettings) => {
    setSettingsState(s);
    void platform.kv.set('syncSettings', s);
  }, []);

  const getApi = useCallback(async (): Promise<PressdockApi | null> => {
    if (!site) return null;
    if (apiRef.current?.siteId === site.id) return apiRef.current.api;
    const pw = await getPassword(site.id);
    if (!pw) return null;
    const api = new PressdockApi({ url: site.url, username: site.username, password: pw });
    apiRef.current = { siteId: site.id, api };
    return api;
  }, [site]);

  useEffect(() => {
    apiRef.current = null;
    setReachable(null);
    setLastSyncAt(null);
  }, [site?.id, site?.url, site?.username]);

  const runSync = useCallback(
    async (manual: boolean) => {
      if (!site || !repo || syncing || isSyncing(site.id)) return;
      const api = await getApi();
      if (!api) {
        if (manual) toast(t('sites.noPassword'), 'error');
        return;
      }
      setSyncing(true);
      try {
        const engine = new SyncEngine({ siteId: site.id, repo, api });
        // refresh schemas first (cheap) so forms/validation follow server changes
        try {
          await repo.saveEntities(await api.entities());
        } catch {
          /* offline: the run below reports it */
        }
        const s: SyncSummary = await engine.run({
          confirm: manual ? (plan) => new Promise<boolean>((resolve) => setConfirmReq({ plan, resolve })) : undefined,
        });
        setConfirmReq(null);
        if (s.aborted === 'cancelled') {
          if (manual) toast(t('sync.cancelled'), 'info');
          else if (!warned.current) {
            warned.current = true;
            toast(t('sync.needsConfirm'), 'info');
          }
        } else if (s.aborted === 'offline' || s.aborted === 'failed') {
          setReachable(s.aborted === 'offline' ? false : reachable);
          toast(t('sync.failed', { detail: s.message ?? '' }), 'error');
        } else if (s.aborted !== 'busy') {
          setReachable(true);
          setLastSyncAt(new Date().toISOString());
          warned.current = false;
          if (manual || s.pushed || s.pulled || s.conflicts || s.errors) {
            toast(t('sync.summary', { pushed: s.pushed, pulled: s.pulled, conflicts: s.conflicts, errors: s.errors }), s.conflicts || s.errors ? 'error' : 'success');
          }
        }
      } finally {
        setSyncing(false);
        await reloadMeta();
      }
    },
    [site, repo, syncing, getApi, toast, t, reloadMeta, reachable],
  );

  const runRef = useRef(runSync);
  runRef.current = runSync;

  // lightweight connectivity probe
  useEffect(() => {
    if (!site) return;
    let alive = true;
    const ping = async () => {
      if (!navigator.onLine) return setReachable(false);
      if (isSyncing(site.id)) return;
      const api = await getApi();
      if (!api) return;
      try {
        await api.handshake();
        if (alive) setReachable(true);
      } catch {
        if (alive) setReachable(false);
      }
    };
    void ping();
    const id = setInterval(() => void ping(), PING_MS);
    return () => {
      alive = false;
      clearInterval(id);
    };
  }, [site, getApi, online]);

  // auto-sync when coming online / becoming reachable
  const prev = useRef<boolean | null>(null);
  useEffect(() => {
    const connected = online && reachable === true;
    if (settings.autoSync && connected && prev.current !== true) void runRef.current(false);
    prev.current = connected;
  }, [online, reachable, settings.autoSync]);

  // auto-sync every N minutes
  useEffect(() => {
    if (!settings.autoSync || settings.intervalMinutes <= 0) return;
    const id = setInterval(() => {
      if (navigator.onLine) void runRef.current(false);
    }, settings.intervalMinutes * 60_000);
    return () => clearInterval(id);
  }, [settings.autoSync, settings.intervalMinutes]);

  const value = useMemo<SyncCtx>(
    () => ({
      syncing,
      connected: online && reachable !== false,
      lastSyncAt,
      settings,
      setSettings,
      syncNow: () => runSync(true),
      api: getApi,
    }),
    [syncing, online, reachable, lastSyncAt, settings, setSettings, runSync, getApi],
  );

  return (
    <Ctx.Provider value={value}>
      {children}
      {confirmReq && <SyncPreview plan={confirmReq.plan} onAnswer={(ok) => { confirmReq.resolve(ok); setConfirmReq(null); }} />}
    </Ctx.Provider>
  );
}
