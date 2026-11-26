document.documentElement.classList.add('js');
// Móvil: «Más» abre el menú lateral como una hoja; el velo o Escape lo cierran.
const menu = abierto => {
  document.body.classList.toggle('menu-abierto', abierto);
  for (const b of document.querySelectorAll('.menu-boton')) b.setAttribute('aria-expanded', abierto);
};
for (const b of document.querySelectorAll('.menu-boton')) b.addEventListener('click', () => menu(!document.body.classList.contains('menu-abierto')));
for (const v of document.querySelectorAll('[data-cerrar-menu]')) v.addEventListener('click', () => menu(false));
addEventListener('keydown', e => { if (e.key === 'Escape') menu(false); });
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').then(r => r.update()).catch(() => {});

// Versión nueva en el servidor: barra «Actualizar» (vacía la caché de la app y recarga). Se mira al abrir y al volver a la app.
const mirarVersion = () => fetch('?p=version', { cache: 'no-store' }).then(r => r.ok ? r.text() : '').then(v => {
  v = v.trim();
  if (!v || v === document.documentElement.dataset.version || document.querySelector('.nueva-version')) return;
  const b = document.createElement('div');
  b.className = 'nueva-version';
  b.innerHTML = '<span>Hay una versión nueva de INDA (' + v.replace(/[^\w.]/g, '') + ').</span><button type="button">Actualizar</button>';
  b.querySelector('button').onclick = async () => {
    try { for (const k of await caches.keys()) await caches.delete(k); } catch (e) {}
    try { for (const r of await navigator.serviceWorker.getRegistrations()) await r.update(); } catch (e) {}
    location.reload();
  };
  document.body.append(b);
}).catch(() => {});
mirarVersion();
document.addEventListener('visibilitychange', () => { if (!document.hidden) mirarVersion(); });
setInterval(mirarVersion, 15 * 60 * 1000);

// Fondo claro u oscuro: el botón alterna y se recuerda en este dispositivo.
for (const b of document.querySelectorAll('[data-tema-boton]')) b.addEventListener('click', () => {
  const r = document.documentElement;
  const oscuro = r.dataset.tema ? r.dataset.tema === 'oscuro' : matchMedia('(prefers-color-scheme: dark)').matches;
  r.dataset.tema = oscuro ? 'claro' : 'oscuro';
  try { localStorage.setItem('tema', r.dataset.tema); } catch (e) {}
});

// Reloj en hora de Madrid
for (const el of document.querySelectorAll('[data-reloj]')) {
  const seg = el.hasAttribute('data-segundos');
  const pinta = () => el.textContent = new Date().toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', ...(seg ? { second: '2-digit' } : {}), timeZone: 'Europe/Madrid' });
  pinta(); setInterval(pinta, seg ? 1000 : 10000);
}
// Contador de horas trabajadas hoy, con segundero mientras se está trabajando.
for (const el of document.querySelectorAll('[data-contador]')) {
  const base = +el.dataset.contador, inicio = Date.now();
  const pinta = () => {
    const s = base + (el.hasAttribute('data-corre') ? Math.floor((Date.now() - inicio) / 1000) : 0);
    el.textContent = `${Math.floor(s / 3600)}:${String(Math.floor(s % 3600 / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
  };
  pinta(); if (el.hasAttribute('data-corre')) setInterval(pinta, 1000);
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
      for (const k of ['nombre', 'apellidos', 'dni', 'fecha_nacimiento']) if (d[k] && form[k]) form[k].value = d[k];
      estado.textContent = d.dni_valido ? 'Leído. Revisa los datos y guarda.' : 'Leído, pero la letra del DNI no cuadra: revísalo.';
    } catch (e) { estado.textContent = e.message; }
  });
}

// Botones con confirmación (p. ej. dar de baja).
for (const b of document.querySelectorAll('[data-confirmar]')) b.addEventListener('click', ev => { if (b.dataset.confirmar && !confirm(b.dataset.confirmar)) ev.preventDefault(); });

// Perfil: al elegir la foto se sube sola.
for (const i of document.querySelectorAll('[data-subir-foto]')) i.addEventListener('change', () => { if (i.files.length) i.form.submit(); });

// Nuevo anuncio: buscar personas y contar destinatarios (grupos + personas sueltas, sin repetir).
for (const f of document.querySelectorAll('[data-anuncio]')) {
  const personas = [...f.querySelectorAll('[data-persona]')], total = f.querySelector('[data-total]');
  const contar = () => {
    const g = [...f.querySelectorAll('[data-grupo]:checked')].map(c => c.value.split(':'));
    let n = 0;
    for (const p of personas) {
      const pt = p.dataset.puestos.split(',');
      const en = g.some(([t, v]) => t === 'todos' || (t === 'centro' && p.dataset.centro === v) || (t === 'dep' && p.dataset.dep === v) || (t === 'puesto' && pt.includes(v)));
      p.classList.toggle('incluida', en);
      if (en || p.querySelector('input').checked) n++;
    }
    total.textContent = n;
  };
  f.addEventListener('change', contar);
  f.querySelector('[data-buscar-persona]').addEventListener('input', ev => {
    const q = ev.target.value.trim().toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');
    for (const p of personas) p.hidden = q && !p.dataset.persona.normalize('NFD').replace(/\p{M}/gu, '').includes(q);
  });
  f.addEventListener('submit', ev => { if (total.textContent === '0') { ev.preventDefault(); alert('Elige al menos un destinatario.'); } });
  contar();
}

// Calendario: pulsar el día de una persona → elegir turno → se guarda al momento.
for (const dlg of document.querySelectorAll('dialog.elegir-turno')) {
  const turnos = JSON.parse(dlg.dataset.turnos), ops = dlg.querySelector('[data-opciones]'), estado = dlg.querySelector('[data-estado]');
  let celda = null;
  const boton = (v, letra, color, txt, sub) => `<button type="button" data-v="${v}" class="op-turno"><span class="letra-turno" style="background:${color || '#6b7280'}">${letra}</span><span>${txt}${sub ? `<small>${sub}</small>` : ''}</span></button>`;
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
  const abrir = td => {
    celda = td;
    const dep = td.dataset.d, [a, m, d] = td.dataset.f.split('-');
    dlg.querySelector('[data-titulo]').textContent = `${td.dataset.n} · ${d}/${m}/${a}`;
    const act = dlg.querySelector('[data-actual]');
    if (act) act.textContent = td.title ? 'Ahora: ' + td.title : '';
    const suyos = turnos.filter(t => dep && t.dep == dep), otros = turnos.filter(t => !(dep && t.dep == dep));
    const lista = l => l.map(t => boton(t.id, esc(t.letra || '·'), t.color, esc(t.nombre), t.horas)).join('');
    ops.innerHTML = boton('L', 'D', '#9ca3af', 'Descanso', '') + boton('', '↺', '#9ca3af', 'Su horario habitual', '')
      + (suyos.length ? '<p class="grupo-op">Su departamento</p>' + lista(suyos) : '') + (otros.length ? `<p class="grupo-op">${suyos.length ? 'Otros turnos' : 'Turnos'}</p>` + lista(otros) : '')
      + (turnos.length ? '' : '<p class="nota">No hay turnos creados. Ve a Horarios → «Cargar los turnos de la residencia».</p>');
    estado.textContent = '';
    dlg.showModal();
  };
  document.addEventListener('click', ev => { const td = ev.target.closest('td.editable'); if (td) abrir(td); });
  document.addEventListener('keydown', ev => { const td = ev.target.closest?.('td.editable'); if (td && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); abrir(td); } });
  ops.addEventListener('click', async ev => {
    const b = ev.target.closest('[data-v]');
    if (!b || !celda) return;
    estado.textContent = 'Guardando…';
    const f = new FormData();
    f.append('csrf', dlg.dataset.csrf); f.append('que', 'celda'); f.append('u', celda.dataset.u); f.append('f', celda.dataset.f); f.append('v', b.dataset.v);
    try {
      const r = await fetch(location.href, { method: 'POST', body: f });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error || 'No se pudo guardar');
      celda.textContent = j.txt; celda.title = j.titulo; celda.style.background = j.fondo || '';
      celda.classList.toggle('turno', !!j.fondo); celda.classList.toggle('descanso', j.txt === 'D');
      if (j.avisos.length) { estado.textContent = 'Guardado. Ojo: ' + j.avisos.join(' '); } else dlg.close();
    } catch (e) { estado.textContent = e.message; }
  });
}

// Firma requerida en anuncios: trazo con el dedo o el ratón; se envía como JPEG con fondo blanco y, si hay permiso, la ubicación.
for (const f of document.querySelectorAll('[data-firma-anuncio]')) {
  const cv = f.querySelector('canvas'), cx = cv.getContext('2d');
  let pinta = false, trazos = 0;
  const limpiar = () => { cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height); trazos = 0; };
  limpiar();
  cx.lineWidth = 3; cx.lineCap = 'round'; cx.strokeStyle = '#111';
  const punto = ev => { const r = cv.getBoundingClientRect(); return [(ev.clientX - r.left) * cv.width / r.width, (ev.clientY - r.top) * cv.height / r.height]; };
  cv.addEventListener('pointerdown', ev => { pinta = true; cv.setPointerCapture(ev.pointerId); cx.beginPath(); cx.moveTo(...punto(ev)); });
  cv.addEventListener('pointermove', ev => { if (!pinta) return; cx.lineTo(...punto(ev)); cx.stroke(); trazos++; });
  addEventListener('pointerup', () => { pinta = false; });
  f.querySelector('[data-borrar-firma]').onclick = limpiar;
  const geo = f.querySelector('[data-geo-estado]');
  if (navigator.geolocation) navigator.geolocation.getCurrentPosition(p => {
    f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; f.precision.value = Math.round(p.coords.accuracy);
    geo.textContent = 'Se añadirá la ubicación aproximada de tu dispositivo al certificado.';
  }, () => { geo.textContent = 'Sin permiso de ubicación: el certificado se generará sin ella.'; }, { timeout: 10000, maximumAge: 60000 });
  f.addEventListener('submit', ev => {
    if (trazos < 5) { ev.preventDefault(); alert('Firma con el dedo en el recuadro antes de enviar.'); return; }
    f.firma.value = cv.toDataURL('image/jpeg', 0.85);
  });
}
