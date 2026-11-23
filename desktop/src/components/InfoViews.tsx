import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ExternalLink } from 'lucide-react';
import { useApp, type Theme } from '../state/app';
import { platform } from '../platform';
import { useSync } from '../state/sync';

export const SITE_URL = 'https://thereadscope.com/pressdock';
export const APP_VERSION = '0.1.0';

export function AboutView() {
  const { t } = useTranslation();
  return (
    <div className="flex h-full items-center justify-center p-6">
      <div className="card w-full max-w-md p-8 text-center">
        <img src="/icon.svg" alt="" className="mx-auto mb-4 h-16 w-16" />
        <h1 className="text-2xl font-bold">Pressdock</h1>
        <p className="mt-1 text-slate-500">{t('about.by')}</p>
        <p className="mt-1 text-xs text-slate-400">{t('about.version', { v: APP_VERSION })}</p>
        <p className="mt-4 text-sm">{t('about.tagline')}</p>
        <button className="btn btn-primary mt-5" onClick={() => void platform.openUrl(SITE_URL)}>
          <ExternalLink size={14} /> thereadscope.com/pressdock
        </button>
        <p className="mt-6 text-xs text-slate-400">com.thereadscope.pressdock</p>
      </div>
    </div>
  );
}

export function SettingsView() {
  const { t } = useTranslation();
  const { theme, setTheme, lang, setLang } = useApp();
  const { settings, setSettings } = useSync();
  const [upd, setUpd] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function checkUpdates() {
    setBusy(true);
    setUpd(null);
    try {
      const { check } = await import('@tauri-apps/plugin-updater');
      const u = await check();
      if (!u) setUpd(t('settings.upToDate'));
      else {
        setUpd(t('settings.updateFound', { v: u.version }));
        if (window.confirm(t('settings.installUpdate', { v: u.version }))) {
          await u.downloadAndInstall();
          const { relaunch } = await import('@tauri-apps/plugin-process');
          await relaunch();
        }
      }
    } catch (e) {
      setUpd(t('settings.updateFailed', { detail: e instanceof Error ? e.message : String(e) }));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-xl space-y-6">
        <h1 className="text-xl font-semibold">{t('nav.settings')}</h1>
        <div>
          <label className="label">{t('settings.language')}</label>
          <select className="input max-w-xs" value={lang} onChange={(e) => setLang(e.target.value as 'en' | 'bn')}>
            <option value="en">English</option>
            <option value="bn">বাংলা</option>
          </select>
        </div>
        <div>
          <label className="label">{t('settings.theme')}</label>
          <select className="input max-w-xs" value={theme} onChange={(e) => setTheme(e.target.value as Theme)}>
            <option value="system">{t('settings.system')}</option>
            <option value="light">{t('settings.light')}</option>
            <option value="dark">{t('settings.dark')}</option>
          </select>
        </div>
        <div>
          <label className="label">{t('settings.autoSync')}</label>
          <label className="mb-2 flex items-center gap-2">
            <input type="checkbox" className="accent-brand-600" checked={settings.autoSync} onChange={(e) => setSettings({ ...settings, autoSync: e.target.checked })} />
            {t('settings.autoSyncOn')}
          </label>
          <select className="input max-w-xs" value={settings.intervalMinutes} disabled={!settings.autoSync} onChange={(e) => setSettings({ ...settings, intervalMinutes: Number(e.target.value) })}>
            {[1, 2, 5, 10, 15, 30, 60].map((m) => (
              <option key={m} value={m}>
                {t('settings.everyMinutes', { count: m })}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="label">{t('settings.updates')}</label>
          <button className="btn" disabled={busy || !platform.isTauri} onClick={() => void checkUpdates()}>
            {busy ? '…' : t('settings.checkUpdates')}
          </button>
          {!platform.isTauri && <p className="mt-1 text-xs text-slate-500">{t('settings.desktopOnly')}</p>}
          {upd && <p className="mt-2 text-sm">{upd}</p>}
        </div>
      </div>
    </div>
  );
}
