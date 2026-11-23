import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, ChevronDown, History, ChevronRight, CloudOff, Globe, Info, Moon, RefreshCw, Settings as Cog, Sun, Wifi } from 'lucide-react';
import { useApp } from '../state/app';
import { useSync } from '../state/sync';

export function Brand() {
  return (
    <div className="flex items-center gap-2 px-4 py-3">
      <img src={`${import.meta.env.BASE_URL}icon.svg`} alt="" className="h-7 w-7" />
      <span className="text-base font-bold tracking-tight">Pressdock</span>
    </div>
  );
}

export function Sidebar() {
  const { t } = useTranslation();
  const { sites, site, entities, counts, view, navigate, selectSite, conflicts } = useApp();
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});

  const groups = useMemo(() => {
    const m = new Map<string, typeof entities>();
    for (const e of entities) {
      const g = e.group || t('nav.other');
      m.set(g, [...(m.get(g) ?? []), e]);
    }
    return [...m.entries()];
  }, [entities, t]);

  const navBtn = (active: boolean) =>
    `flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-sm ${active ? 'bg-brand-100 font-medium text-brand-700 dark:bg-brand-600/25 dark:text-brand-100' : 'hover:bg-slate-200 dark:hover:bg-slate-800'}`;

  return (
    <aside className="flex w-64 shrink-0 flex-col border-r border-slate-200 bg-slate-100 dark:border-slate-800 dark:bg-slate-900">
      <Brand />
      <div className="px-3 pb-2">
        <label className="label">{t('nav.site')}</label>
        <select
          className="input"
          value={site?.id ?? ''}
          onChange={(e) => (e.target.value === '__manage' ? navigate({ name: 'sites' }) : void selectSite(e.target.value))}
        >
          {!site && <option value="">{t('nav.noSite')}</option>}
          {sites.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
          <option value="__manage">{t('nav.manageSites')}…</option>
        </select>
      </div>
      <nav className="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
        {site && groups.length === 0 && <p className="px-2 py-3 text-xs text-slate-500">{t('nav.noEntities')}</p>}
        {groups.map(([g, list]) => (
          <div key={g} className="mb-2">
            <button className="flex w-full items-center gap-1 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-slate-500" onClick={() => setCollapsed((c) => ({ ...c, [g]: !c[g] }))}>
              {collapsed[g] ? <ChevronRight size={12} /> : <ChevronDown size={12} />}
              {g}
            </button>
            {!collapsed[g] &&
              list.map((e) => {
                const active = (view.name === 'entity' || view.name === 'form') && view.key === e.key;
                return (
                  <button key={e.key} className={navBtn(active)} onClick={() => navigate({ name: 'entity', key: e.key })}>
                    <span className="flex-1 truncate">{e.label}</span>
                    <span className="text-xs text-slate-500">{counts[e.key] ?? 0}</span>
                  </button>
                );
              })}
          </div>
        ))}
      </nav>
      <div className="space-y-0.5 border-t border-slate-200 p-2 dark:border-slate-800">
        <button className={navBtn(view.name === 'conflicts')} onClick={() => navigate({ name: 'conflicts' })}>
          <AlertTriangle size={15} /> {t('nav.conflicts')}
          {conflicts > 0 && <span className="badge ml-auto bg-red-100 text-red-800">{conflicts}</span>}
        </button>
        <button className={navBtn(view.name === 'history')} onClick={() => navigate({ name: 'history' })}>
          <History size={15} /> {t('nav.history')}
        </button>
        <button className={navBtn(view.name === 'sites')} onClick={() => navigate({ name: 'sites' })}>
          <Globe size={15} /> {t('nav.sites')}
        </button>
        <button className={navBtn(view.name === 'settings')} onClick={() => navigate({ name: 'settings' })}>
          <Cog size={15} /> {t('nav.settings')}
        </button>
        <button className={navBtn(view.name === 'about')} onClick={() => navigate({ name: 'about' })}>
          <Info size={15} /> {t('nav.about')}
        </button>
      </div>
    </aside>
  );
}

export function TopBar() {
  const { t } = useTranslation();
  const { pending, conflicts, theme, setTheme, lang, setLang, site } = useApp();
  const { syncing, connected: online, syncNow, lastSyncAt } = useSync();
  const dark = document.documentElement.classList.contains('dark');
  return (
    <header className="flex h-12 shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900">
      <span className="text-sm font-semibold">{site?.name ?? 'Pressdock'}</span>
      <span className="flex-1" />
      <span
        className={`badge ${online ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'}`}
        title={online ? t('top.onlineHint') : t('top.offlineHint')}
      >
        {online ? <Wifi size={12} /> : <CloudOff size={12} />} {online ? t('top.online') : t('top.offline')}
      </span>
      <span className={`badge ${pending > 0 ? 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400'}`} data-testid="pending-badge">
        {t('top.pending', { count: pending })}
      </span>
      {conflicts > 0 && (
        <span className="badge bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">
          <AlertTriangle size={12} /> {t('top.conflicts', { count: conflicts })}
        </span>
      )}
      <button className="btn" disabled={!site || !online || syncing} onClick={() => void syncNow()} title={lastSyncAt ? t('top.lastSync', { date: new Date(lastSyncAt).toLocaleTimeString() }) : t('top.sync')}>
        <RefreshCw size={14} className={syncing ? 'animate-spin' : ''} /> {syncing ? t('top.syncing') : t('top.sync')}
      </button>
      <button className="btn btn-ghost btn-icon" onClick={() => setLang(lang === 'en' ? 'bn' : 'en')} title={t('settings.language')}>
        <span className="text-xs font-bold">{lang === 'en' ? 'বাং' : 'EN'}</span>
      </button>
      <button className="btn btn-ghost btn-icon" onClick={() => setTheme(dark ? 'light' : 'dark')} title={t('settings.theme')} aria-label={t('settings.theme')} data-theme-now={theme}>
        {dark ? <Sun size={16} /> : <Moon size={16} />}
      </button>
    </header>
  );
}
