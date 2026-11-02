/* Finance 360 service worker: caches ONLY the app shell (CSS, JS, fonts, logo) so the app opens fast and shows an
   offline notice. API responses (money, staff, salary data) are never cached on the phone. */
const V = 'f360-23.0.0';
const SHELL = ['offline.html', 'assets/f360/app.css?v=23.0.0', 'assets/f360/core.js?v=23.0.0', 'assets/f360/daily.js?v=23.0.0', 'assets/f360/accounts.js?v=23.0.0', 'assets/f360/staff.js?v=23.0.0', 'assets/f360/purchase.js?v=23.0.0', 'assets/f360/stock.js?v=23.0.0', 'assets/f360/control.js?v=23.0.0', 'assets/f360/salary.js?v=23.0.0', 'assets/f360/integrations.js?v=23.0.0', 'assets/f360/staff_import.js?v=23.0.0', 'assets/f360/admin.js?v=23.0.0', 'assets/f360/v23.js?v=23.0.0',
  'assets/brand/logo-mark-96.png', 'assets/brand/logo-full.png', 'assets/brand/favicon-32.png', 'assets/f360/fonts/mukta-latin-400-normal.woff2', 'assets/f360/fonts/mukta-latin-600-normal.woff2', 'assets/f360/fonts/mukta-latin-800-normal.woff2', 'assets/f360/fonts/alfa-slab-one-latin-400-normal.woff2'];
self.addEventListener('install', e => { e.waitUntil(caches.open(V).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== V).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin) return;
  if (/\.php$/.test(u.pathname) && !/\/index\.php$|\/$/.test(u.pathname)) return;          // API & server pages: always network, never cached
  if (u.pathname.endsWith('/') || u.pathname.endsWith('/index.php')) {                        // app page: network first, offline notice if no connection
    e.respondWith(fetch(e.request).catch(() => caches.match('offline.html'))); return; }
  if (/\/assets\//.test(u.pathname)) e.respondWith(caches.match(e.request).then(r => r || fetch(e.request).then(res => { if (res.ok) { const cp = res.clone(); caches.open(V).then(c => c.put(e.request, cp)); } return res; })));
});
