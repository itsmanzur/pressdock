import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import i18n, { detectLang, type Lang } from '../i18n';
import { platform } from '../platform';
import { listSites, openRepo, type Site } from '../sites/siteStore';
import type { SiteRepo } from '../db/repo';
import type { Entity } from '../schema/types';

export type View =
  | { name: 'sites' }
  | { name: 'entity'; key: string }
  | { name: 'form'; key: string; uuid: string | null }
  | { name: 'conflicts' }
  | { name: 'history' }
  | { name: 'settings' }
  | { name: 'about' };

export type Theme = 'light' | 'dark' | 'system';

interface Toast {
  id: number;
  kind: 'info' | 'error' | 'success';
  text: string;
}

interface AppState {
  ready: boolean;
  sites: Site[];
  site: Site | null;
  repo: SiteRepo | null;
  entities: Entity[];
  counts: Record<string, number>;
  pending: number;
  conflicts: number;
  online: boolean;
  view: View;
  theme: Theme;
  lang: Lang;
  toasts: Toast[];
  navigate(v: View): void;
  selectSite(id: string): Promise<void>;
  reloadSites(): Promise<void>;
  reloadMeta(): Promise<void>;
  setTheme(t: Theme): void;
  setLang(l: Lang): void;
  toast(text: string, kind?: Toast['kind']): void;
  dismissToast(id: number): void;
}

const Ctx = createContext<AppState | null>(null);

export const useApp = (): AppState => {
  const v = useContext(Ctx);
  if (!v) throw new Error('useApp outside provider');
  return v;
};

function applyTheme(theme: Theme) {
  const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.classList.toggle('dark', dark);
}

export function AppProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [sites, setSites] = useState<Site[]>([]);
  const [site, setSite] = useState<Site | null>(null);
  const [repo, setRepo] = useState<SiteRepo | null>(null);
  const [entities, setEntities] = useState<Entity[]>([]);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [pending, setPending] = useState(0);
  const [conflicts, setConflicts] = useState(0);
  const [online, setOnline] = useState(typeof navigator === 'undefined' ? true : navigator.onLine);
  const [view, setView] = useState<View>({ name: 'sites' });
  const [theme, setThemeState] = useState<Theme>('system');
  const [lang, setLangState] = useState<Lang>(detectLang());
  const [toasts, setToasts] = useState<Toast[]>([]);
  const toastId = useRef(0);
  const repoRef = useRef<SiteRepo | null>(null);

  // online / offline indicator
  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return () => {
      window.removeEventListener('online', on);
      window.removeEventListener('offline', off);
    };
  }, []);

  // theme
  useEffect(() => {
    applyTheme(theme);
    if (theme !== 'system') return;
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const h = () => applyTheme('system');
    mq.addEventListener('change', h);
    return () => mq.removeEventListener('change', h);
  }, [theme]);

  const setTheme = useCallback((t: Theme) => {
    setThemeState(t);
    void platform.kv.set('theme', t);
  }, []);

  const setLang = useCallback((l: Lang) => {
    setLangState(l);
    void i18n.changeLanguage(l);
    document.documentElement.lang = l;
    void platform.kv.set('lang', l);
  }, []);

  const toast = useCallback((text: string, kind: Toast['kind'] = 'info') => {
    const id = ++toastId.current;
    setToasts((t) => [...t, { id, kind, text }]);
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 5000);
  }, []);
  const dismissToast = useCallback((id: number) => setToasts((t) => t.filter((x) => x.id !== id)), []);

  const reloadMeta = useCallback(async () => {
    const r = repoRef.current;
    if (!r) return;
    const [e, c, p, cf] = await Promise.all([r.listEntities(), r.countByEntity(), r.pendingCount(), r.conflictCount()]);
    setEntities(e);
    setCounts(c);
    setPending(p);
    setConflicts(cf);
  }, []);

  const selectSite = useCallback(
    async (id: string) => {
      const all = await listSites();
      const s = all.find((x) => x.id === id) ?? null;
      setSites(all);
      setSite(s);
      void platform.kv.set('lastSite', id);
      if (!s) {
        repoRef.current = null;
        setRepo(null);
        setEntities([]);
        return;
      }
      const r = await openRepo(s.id);
      repoRef.current = r;
      setRepo(r);
      await reloadMeta();
      const es = await r.listEntities();
      setView(es.length ? { name: 'entity', key: es[0].key } : { name: 'sites' });
    },
    [reloadMeta],
  );

  // keep badges live: any local write refreshes counts
  useEffect(() => {
    if (!repo) return;
    return repo.subscribe(() => void reloadMeta());
  }, [repo, reloadMeta]);

  const reloadSites = useCallback(async () => {
    const all = await listSites();
    setSites(all);
    if (site && !all.find((s) => s.id === site.id)) {
      setSite(null);
      setRepo(null);
      repoRef.current = null;
      setEntities([]);
      setCounts({});
      setPending(0);
      setConflicts(0);
      setView({ name: 'sites' });
    } else if (site) {
      setSite(all.find((s) => s.id === site.id) ?? site);
    }
  }, [site]);

  // boot
  useEffect(() => {
    void (async () => {
      const [th, lg, last] = await Promise.all([
        platform.kv.get<Theme>('theme'),
        platform.kv.get<Lang>('lang'),
        platform.kv.get<string>('lastSite'),
      ]);
      if (th) setThemeState(th);
      const l = lg ?? detectLang();
      setLangState(l);
      await i18n.changeLanguage(l);
      document.documentElement.lang = l;
      const all = await listSites();
      setSites(all);
      const first = all.find((s) => s.id === last) ?? all[0];
      if (first) await selectSite(first.id);
      setReady(true);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // window title: brand + current site
  useEffect(() => {
    void platform.setWindowTitle(site ? `Pressdock — ${site.name}` : 'Pressdock');
  }, [site]);

  const value = useMemo<AppState>(
    () => ({
      ready,
      sites,
      site,
      repo,
      entities,
      counts,
      pending,
      conflicts,
      online,
      view,
      theme,
      lang,
      toasts,
      navigate: setView,
      selectSite,
      reloadSites,
      reloadMeta,
      setTheme,
      setLang,
      toast,
      dismissToast,
    }),
    [ready, sites, site, repo, entities, counts, pending, conflicts, online, view, theme, lang, toasts, selectSite, reloadSites, reloadMeta, setTheme, setLang, toast, dismissToast],
  );

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}
