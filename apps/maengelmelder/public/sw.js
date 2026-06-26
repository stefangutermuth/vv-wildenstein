/* Mängelmelder – einfacher Service Worker (App-Shell-Cache + Offline-Fallback). */
const CACHE = 'melder-v2';
const SHELL = [
  '/',
  '/meldungen',
  '/willkommen',
  '/mangel-einreichen',
  '/mehr',
  '/manifest.webmanifest',
  '/images/wappen.svg',
  '/images/melder-logo.svg',
  '/appicons/icon-192.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  // Fremde Hosts (Kartenkacheln, REST-API, Adresssuche) immer direkt aus dem Netz.
  if (url.origin !== self.location.origin) return;

  // Seitenaufrufe: erst Netz, dann Cache, sonst Startseite als Fallback.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req).then((r) => r || caches.match('/')))
    );
    return;
  }

  // Statische Assets: erst Cache, sonst Netz (und nachladen).
  event.respondWith(
    caches.match(req).then(
      (cached) =>
        cached ||
        fetch(req).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        })
    )
  );
});
