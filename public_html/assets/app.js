document.documentElement.classList.add('js');
for (const b of document.querySelectorAll('.menu-boton')) b.addEventListener('click', () => {
  const abierto = b.closest('nav').classList.toggle('abierto');
  b.setAttribute('aria-expanded', abierto);
});
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js');

// Reloj en hora de Madrid
for (const el of document.querySelectorAll('[data-reloj]')) {
  const pinta = () => el.textContent = new Date().toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Madrid' });
  pinta(); setInterval(pinta, 10000);
}

// GPS: una sola lectura al pulsar el botón (nunca seguimiento).
for (const form of document.querySelectorAll('form[data-gps]')) {
  form.addEventListener('submit', ev => {
    const modo = form.dataset.gps;
    if (modo === 'no' || form.dataset.listo) return;
    ev.preventDefault();
    const boton = ev.submitter;
    for (const b of form.querySelectorAll('button')) b.disabled = true;
    const enviar = () => {
      form.dataset.listo = '1';
      const t = document.createElement('input');
      t.type = 'hidden'; t.name = boton.name; t.value = boton.value;
      form.append(t);
      form.submit();
    };
    if (!navigator.geolocation) return modo === 'obligatorio' ? alert('Tu navegador no permite la ubicación.') : enviar();
    navigator.geolocation.getCurrentPosition(p => {
      form.lat.value = p.coords.latitude;
      form.lng.value = p.coords.longitude;
      form.precision.value = Math.round(p.coords.accuracy);
      enviar();
    }, () => {
      if (modo === 'obligatorio') {
        alert('Para fichar debes permitir la ubicación en tu navegador.');
        for (const b of form.querySelectorAll('button')) b.disabled = false;
      } else enviar();
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  });
}

for (const b of document.querySelectorAll('[data-imprimir]')) b.addEventListener('click', () => window.print());

// Ausencias: por días o por horas según el tipo.
for (const f of document.querySelectorAll('form[data-ausencia]')) {
  const sel = f.tipo_id;
  const pinta = () => {
    const o = sel.selectedOptions[0];
    const horas = o && o.dataset.horas === '1';
    for (const el of f.querySelectorAll('[data-horas]')) el.hidden = !horas;
    for (const el of f.querySelectorAll('[data-dias]')) el.hidden = horas;
    f.querySelector('[data-pide]').hidden = !(o && o.dataset.justificante === '1');
  };
  sel.addEventListener('change', pinta); pinta();
}

// Avisos push: suscribe este navegador (sin contenido; el service worker pide el texto a INDA).
for (const b of document.querySelectorAll('[data-push]')) {
  const estado = document.querySelector('[data-push-estado]');
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) { b.disabled = true; estado.textContent = 'Este navegador no admite avisos. En iPhone, instala INDA en la pantalla de inicio primero.'; continue; }
  b.addEventListener('click', async () => {
    try {
      if (await Notification.requestPermission() !== 'granted') throw new Error('Has denegado los avisos en el navegador.');
      const reg = await navigator.serviceWorker.ready;
      const clave = Uint8Array.from(atob(b.dataset.push.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
      const sub = await reg.pushManager.getSubscription() || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: clave });
      const r = await fetch('?p=perfil', { method: 'POST', body: new URLSearchParams({ csrf: b.dataset.csrf, que: 'push', endpoint: sub.endpoint }) });
      estado.textContent = (await r.text()) === 'ok' ? 'Avisos activados en este dispositivo.' : 'No se pudo activar.';
    } catch (e) { estado.textContent = e.message; }
  });
}

// QR: data-qr="texto" se pinta una vez; data-qr-quiosco se renueva cada 20 s.
const pintaQr = (el, texto) => { const q = qrcode(0, 'M'); q.addData(texto); q.make(); el.innerHTML = q.createSvgTag({ cellSize: 6, margin: 2, scalable: true }); };
if (window.qrcode) {
  for (const el of document.querySelectorAll('[data-qr]')) pintaQr(el, el.dataset.qr);
  for (const el of document.querySelectorAll('[data-qr-quiosco]')) {
    const pide = () => fetch('?p=quiosco&codigo=1').then(r => r.json()).then(d => pintaQr(el, d.url)).catch(() => { el.textContent = 'Sin conexión'; });
    pide(); setInterval(pide, 20000);
  }
}

// Instalar INDA como app (Android/Chrome: botón; iPhone: instrucciones). No se muestra si ya está instalada.
const instalada = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
const cajaInstalar = document.querySelector('[data-instalar]');
if (cajaInstalar && !instalada) {
  if (/iPhone|iPad/.test(navigator.userAgent)) {
    cajaInstalar.hidden = false;
    cajaInstalar.querySelector('[data-instalar-boton]').hidden = true;
    cajaInstalar.querySelector('[data-ios]').hidden = false;
  }
  addEventListener('beforeinstallprompt', ev => {
    ev.preventDefault();
    cajaInstalar.hidden = false;
    cajaInstalar.querySelector('[data-instalar-boton]').onclick = async () => { ev.prompt(); await ev.userChoice; cajaInstalar.hidden = true; };
  });
}

// Usuarios: leer el DNI con IA y rellenar nombre, apellidos y DNI (el admin lo revisa antes de guardar).
for (const caja of document.querySelectorAll('[data-ia-dni]')) {
  const form = caja.closest('form'), estado = caja.querySelector('[data-ia-estado]');
  caja.querySelector('button').addEventListener('click', async () => {
    const f = caja.querySelector('input[type=file]').files[0];
    if (!f) { estado.textContent = 'Elige primero la foto.'; return; }
    const datos = new FormData(); datos.append('csrf', form.csrf.value); datos.append('archivo', f);
    estado.textContent = 'Leyendo…';
    try {
      const r = await fetch('?p=ia', { method: 'POST', body: datos }); const d = await r.json();
      if (!r.ok) throw new Error(d.error);
      for (const k of ['nombre', 'apellidos', 'dni']) if (d[k]) form[k].value = d[k];
      estado.textContent = d.dni_valido ? 'Leído. Revisa los datos y guarda.' : 'Leído, pero la letra del DNI no cuadra: revísalo.';
    } catch (e) { estado.textContent = e.message; }
  });
}
