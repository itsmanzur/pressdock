import React from 'react';
import ReactDOM from 'react-dom/client';
import './i18n';
import './index.css';
import { AppProvider } from './state/app';
import { SyncProvider } from './state/sync';
import App from './App';
import { platform } from './platform';
import { acquireTabLock } from './platform/web';

const root = ReactDOM.createRoot(document.getElementById('root')!);

function OtherTab() {
  return (
    <div className="flex h-full items-center justify-center p-6 text-center">
      <div className="card max-w-md p-6">
        <h1 className="text-lg font-semibold">Pressdock is already open in another tab</h1>
        <p className="mt-2 text-sm text-slate-500">
          Pressdock keeps your data in this browser and can only run in one tab at a time, so two tabs never overwrite each other's changes. Close
          the other tab, then reload this one.
        </p>
        <p className="mt-2 text-sm text-slate-500" lang="bn">
          Pressdock অন্য একটি ট্যাবে খোলা আছে। ডেটা যেন হারিয়ে না যায়, তাই একসাথে একটাই ট্যাব চলে। অন্য ট্যাবটি বন্ধ করে এটি রিলোড করুন।
        </p>
        <button className="btn btn-primary mt-4" onClick={() => location.reload()}>
          Reload
        </button>
      </div>
    </div>
  );
}

async function start() {
  if (!platform.isTauri && !(await acquireTabLock())) {
    root.render(<OtherTab />);
    return;
  }
  root.render(
    <React.StrictMode>
      <AppProvider>
        <SyncProvider>
          <App />
        </SyncProvider>
      </AppProvider>
    </React.StrictMode>,
  );
  // Hosted web app: install + offline shell. Never inside Tauri, never in dev.
  if (!platform.isTauri && import.meta.env.PROD && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register(import.meta.env.BASE_URL + 'sw.js', { scope: import.meta.env.BASE_URL }).catch(() => undefined);
  }
}

void start();
