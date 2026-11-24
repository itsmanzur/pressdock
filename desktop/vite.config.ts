import { defineConfig, type Plugin } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/**
 * Content-Security-Policy for the hosted web app (https://app.thereadscope.com/), added only in
 * `vite build --mode web`. Tauri and the dev server use the same index.html and must not get it:
 * Tauri talks to its core over ipc:, and Vite's dev client needs inline scripts and websockets.
 *
 * - connect-src https: — the WordPress sites are chosen by the user; http://localhost and
 *   http://127.0.0.1 stay reachable for local development sites (browsers allow them from HTTPS).
 * - 'wasm-unsafe-eval' — sql.js (SQLite) is WebAssembly. No 'unsafe-eval' for JavaScript.
 * - 'unsafe-inline' styles only — React sets style attributes; scripts stay 'self'.
 * frame-ancestors cannot be set from a meta tag; that needs an HTTP header the host must send.
 */
const WEB_CSP = [
  "default-src 'self'",
  "script-src 'self' 'wasm-unsafe-eval'",
  "worker-src 'self' blob:",
  "connect-src 'self' https: http://localhost:* http://127.0.0.1:*",
  "img-src 'self' data: blob: https:",
  "style-src 'self' 'unsafe-inline'",
  "font-src 'self' data:",
  "manifest-src 'self'",
  "object-src 'none'",
  "base-uri 'none'",
  "form-action 'none'",
].join('; ');

function webCsp(mode: string): Plugin {
  return {
    name: 'pressdock-web-csp',
    apply: 'build',
    transformIndexHtml(html) {
      if (mode !== 'web') return html;
      return html.replace('<meta charset="UTF-8" />', `<meta charset="UTF-8" />\n    <meta http-equiv="Content-Security-Policy" content="${WEB_CSP}" />`);
    },
  };
}

// Tauri expects a fixed dev port; the same build also runs in a plain browser (dev preview).
export default defineConfig(({ mode }) => ({
  // The hosted web app lives at the root of its own origin; PRESSDOCK_BASE is only for a sub-path copy.
  base: process.env.PRESSDOCK_BASE || '/',
  plugins: [react(), tailwindcss(), webCsp(mode)],
  clearScreen: false,
  server: { port: 1420, strictPort: true, host: '127.0.0.1' },
  build: { target: 'es2022', chunkSizeWarningLimit: 1500 },
  test: { environment: 'node', include: ['src/**/*.test.ts'] },
}));
