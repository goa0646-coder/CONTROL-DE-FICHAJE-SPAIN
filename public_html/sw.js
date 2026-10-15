// Solo cachea la interfaz. Fichar siempre necesita conexión (la hora la pone el servidor).
const CACHE = 'fichaje-v1';
const ARCHIVOS = ['assets/estilo.css', 'assets/app.js', 'assets/icono.svg'];
self.addEventListener('install', e => e.waitUntil(caches.open(CACHE).then(c => c.addAll(ARCHIVOS))));
self.addEventListener('activate', e => e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))));
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET' || !e.request.url.includes('/assets/')) return;
  e.respondWith(caches.match(e.request).then(r => r || fetch(e.request)));
});
