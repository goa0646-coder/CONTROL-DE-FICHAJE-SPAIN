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
      t.type = 'hidden'; t.name = 'tipo'; t.value = boton.value;
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
