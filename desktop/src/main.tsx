import React from 'react';
import ReactDOM from 'react-dom/client';
import './i18n';
import './index.css';
import { AppProvider } from './state/app';
import { SyncProvider } from './state/sync';
import App from './App';

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <AppProvider>
      <SyncProvider>
        <App />
      </SyncProvider>
    </AppProvider>
  </React.StrictMode>,
);
