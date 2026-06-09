// SystemETI — service worker. Cacheia SÓ assets estáticos (cache-first).
// Páginas PHP e o scan_api vão SEMPRE direto pra rede (não passam pelo SW),
// evitando qualquer trava com o servidor single-thread.
const CACHE = 'systemeti-v2';

self.addEventListener('install', (e) => { self.skipWaiting(); });
self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  // Apenas assets estáticos passam pelo cache; o resto não é interceptado.
  if (!/\.(css|js|png|jpg|jpeg|svg|gif|woff2?|ttf|webmanifest|ico)$/i.test(url.pathname)) return;
  e.respondWith(
    caches.match(req).then((hit) => hit || fetch(req).then((resp) => {
      const copy = resp.clone();
      caches.open(CACHE).then((c) => { try { c.put(req, copy); } catch (_) {} });
      return resp;
    }).catch(() => hit))
  );
});
