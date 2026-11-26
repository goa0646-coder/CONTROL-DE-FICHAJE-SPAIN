// Solo cachea la interfaz. Fichar siempre necesita conexión (la hora la pone el servidor).
const CACHE = 'inda-v5';
const ARCHIVOS = ['assets/estilo.css', 'assets/app.js', 'assets/icono.svg'];
self.addEventListener('install', e => e.waitUntil(caches.open(CACHE).then(c => c.addAll(ARCHIVOS))));
self.addEventListener('activate', e => e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))));
// Red primero: así una versión nueva se ve sin cambiar el nombre de la caché.
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET' || !e.request.url.includes('/assets/')) return;
  e.respondWith(fetch(e.request, { cache: 'no-cache' }).then(r => {
    const copia = r.clone();
    caches.open(CACHE).then(c => c.put(e.request, copia));
    return r;
  }).catch(() => caches.match(e.request)));
});

// Push sin contenido: se pide a INDA el último aviso.
self.addEventListener('push', e => e.waitUntil(
  fetch('index.php?p=avisos&json=1', { credentials: 'include' }).then(r => r.json()).catch(() => null).then(a =>
    self.registration.showNotification('INDA', { body: a ? a.texto : 'Tienes un aviso nuevo.', icon: 'assets/icono.svg', data: a ? a.url : '?p=avisos' }))
));
self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(clients.openWindow(new URL(e.notification.data, self.registration.scope).href));
});
