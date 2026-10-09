// Работа без сети: страница и всё, что уже открывали, остаются в памяти телефона.
const CACHE = 'rzd-1';
const plain = url => url.origin + url.pathname;
self.addEventListener('install', e => { self.skipWaiting(); e.waitUntil(caches.open(CACHE).then(c => c.addAll(['./', 'manifest.webmanifest', 'icon-192.png']))); });
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

// страница и список документов - сначала из сети (чтобы видеть обновления), без сети - из памяти
async function netFirst(url) {
  const c = await caches.open(CACHE);
  try {
    const r = await fetch(plain(url), {cache: 'no-cache'});
    if (r.ok) c.put(plain(url), r.clone());
    return r;
  } catch (e) {
    const hit = await c.match(plain(url));
    if (hit) return hit;
    throw e;
  }
}
// индекс и файлы документов не меняются - сначала из памяти
async function cacheFirst(req) {
  const c = await caches.open(CACHE), hit = await c.match(req.url);
  if (hit) return hit;
  const r = await fetch(req);
  if (r.status === 200 && !req.headers.has('range')) c.put(req.url, r.clone());
  return r;
}
self.addEventListener('fetch', e => {
  const req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  const fresh = req.mode === 'navigate' || /\/$|\/(index\.html|meta\.json|tickets\.json|sw\.js)$/.test(url.pathname);
  e.respondWith(fresh ? netFirst(url) : cacheFirst(req));
});
// после пересборки сайта страница просит убрать индекс прошлой сборки
self.addEventListener('message', e => {
  const v = e.data && e.data.keep;
  if (v) e.waitUntil(caches.open(CACHE).then(async c => Promise.all((await c.keys()).filter(k => { const p = new URL(k.url).searchParams.get('v'); return p && p !== v; }).map(k => c.delete(k)))));
});
