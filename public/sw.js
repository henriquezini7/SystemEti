// SystemETI — service worker (network-first, com fallback offline).
const CACHE = 'systemeti-v1';

self.addEventListener('install', (e) => { self.skipWaiting(); });
self.addEventListener('activate', (e) => { e.waitUntil(self.clients.claim()); });

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // bipagem/POST sempre na rede
  e.respondWith(
    fetch(req).then((resp) => {
      const copy = resp.clone();
      caches.open(CACHE).then((c) => { try { c.put(req, copy); } catch (_) {} });
      return resp;
    }).catch(() => caches.match(req))
  );
});
