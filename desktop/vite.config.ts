import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Tauri expects a fixed dev port; the same build also runs in a plain browser (dev preview).
export default defineConfig({
  // Hosted web app (GitHub Pages) builds with PRESSDOCK_BASE=/pressdock/; Tauri and dev use "/".
  base: process.env.PRESSDOCK_BASE || '/',
  plugins: [react(), tailwindcss()],
  clearScreen: false,
  server: { port: 1420, strictPort: true, host: '127.0.0.1' },
  build: { target: 'es2022', chunkSizeWarningLimit: 1500 },
  test: { environment: 'node', include: ['src/**/*.test.ts'] },
});
