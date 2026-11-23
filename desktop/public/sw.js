/* Pressdock web app service worker: offline app shell. Never touches API requests to WordPress sites. */
const CACHE = 'pressdock-shell-v1';
const SCOPE = new URL(self.registration.scope);

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(CACHE);
      // index.html plus every asset it references (the hashed JS/CSS bundles)
      const res = await fetch(SCOPE.href, { cache: 'no-cache' });
      const html = await res.clone().text();
      await cache.put(SCOPE.href, res);
      const urls = [...html.matchAll(/(?:src|href)="([^"]+)"/g)]
        .map((m) => new URL(m[1], SCOPE.href))
        .filter((u) => u.origin === SCOPE.origin && u.pathname.startsWith(SCOPE.pathname));
      await Promise.all(urls.map((u) => cache.add(u.href).catch(() => undefined)));
      await self.skipWaiting();
    })(),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      for (const key of await caches.keys()) if (key !== CACHE) await caches.delete(key);
      await self.clients.claim();
    })(),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  // Only this app's own files. WordPress API calls (other origins) go straight to the network.
  if (req.method !== 'GET' || url.origin !== SCOPE.origin || !url.pathname.startsWith(SCOPE.pathname)) return;

  if (req.mode === 'navigate') {
    // network first so updates arrive; cached shell when offline
    event.respondWith(
      (async () => {
        try {
          const res = await fetch(req);
          const cache = await caches.open(CACHE);
          await cache.put(SCOPE.href, res.clone());
          return res;
        } catch {
          return (await caches.match(SCOPE.href)) || Response.error();
        }
      })(),
    );
    return;
  }

  // hashed assets, wasm, fonts, worker: cache first, fill on demand
  event.respondWith(
    (async () => {
      const hit = await caches.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok && res.type === 'basic') {
        const cache = await caches.open(CACHE);
        await cache.put(req, res.clone());
      }
      return res;
    })(),
  );
});
