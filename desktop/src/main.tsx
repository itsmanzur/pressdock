import React from 'react';
import ReactDOM from 'react-dom/client';
import './i18n';
import './index.css';
import { AppProvider } from './state/app';
import App from './App';

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <AppProvider>
      <App />
    </AppProvider>
  </React.StrictMode>,
);
