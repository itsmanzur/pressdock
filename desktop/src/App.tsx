import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';
import { useApp } from './state/app';
import { Sidebar, TopBar } from './components/Shell';
import SitesView from './components/SitesView';
import EntityView from './components/EntityView';
import RecordForm from './components/RecordForm';
import { ConflictsView, HistoryView } from './components/SyncViews';
import { AboutView, SettingsView } from './components/InfoViews';

export default function App() {
  const { t } = useTranslation();
  const { ready, view, site, entities, toasts, dismissToast, navigate } = useApp();
  if (!ready) return <div className="flex h-full items-center justify-center text-slate-500">Pressdock…</div>;

  let main;
  switch (view.name) {
    case 'sites':
      main = <SitesView />;
      break;
    case 'entity':
      main = <EntityView key={`${site?.id}:${view.key}`} entityKey={view.key} />;
      break;
    case 'form':
      main = <RecordForm key={`${view.key}:${view.uuid ?? 'new'}`} entityKey={view.key} uuid={view.uuid} />;
      break;
    case 'conflicts':
      main = <ConflictsView />;
      break;
    case 'history':
      main = <HistoryView />;
      break;
    case 'settings':
      main = <SettingsView />;
      break;
    case 'about':
      main = <AboutView />;
      break;
  }

  return (
    <div className="flex h-full">
      <Sidebar />
      <div className="flex min-w-0 flex-1 flex-col">
        <TopBar />
        <main className="min-h-0 flex-1">
          {site && entities.length === 0 && view.name === 'entity' ? (
            <div className="p-8 text-center text-slate-500">
              <p>{t('nav.noEntities')}</p>
              <button className="btn mt-3" onClick={() => navigate({ name: 'sites' })}>
                {t('nav.manageSites')}
              </button>
            </div>
          ) : (
            main
          )}
        </main>
      </div>
      <div className="fixed bottom-4 right-4 z-[60] flex flex-col gap-2">
        {toasts.map((x) => (
          <div
            key={x.id}
            className={`card flex max-w-sm items-start gap-2 px-3 py-2 text-sm shadow-lg ${x.kind === 'error' ? 'border-red-400' : x.kind === 'success' ? 'border-emerald-400' : ''}`}
          >
            <span className="flex-1">{x.text}</span>
            <button onClick={() => dismissToast(x.id)} aria-label="Close">
              <X size={14} />
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}
