const $ = id => document.getElementById(id);
const BOTONES = { fuera: [['entrar', 'Entrar']], dentro: [['pausa', 'Empezar pausa'], ['salir', 'Salir']], pausa: [['volver', 'Volver de la pausa'], ['salir', 'Salir']] };
const NOMBRES = { fuera: 'Fuera', dentro: 'Trabajando', pausa: 'En pausa' };
let cfg = {};

async function api(accion, orden) {
  const r = await fetch(`${cfg.url}/?p=api&accion=${accion}`, {
    method: orden ? 'POST' : 'GET',
    headers: { Authorization: `Bearer ${cfg.token}`, ...(orden ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) },
    body: orden ? new URLSearchParams({ orden }) : undefined,
  });
  const d = await r.json();
  if (!r.ok) throw new Error(d.error || 'Error');
  return d;
}

function pinta(estado, extra = '') {
  $('estado').textContent = `${NOMBRES[estado]}${extra}`;
  $('botones').innerHTML = '';
  for (const [orden, txt] of BOTONES[estado]) {
    const b = document.createElement('button');
    b.className = orden; b.textContent = txt;
    b.onclick = async () => {
      b.disabled = true;
      try { const d = await api('fichar', orden); $('msg').textContent = d.mensaje; pinta(d.estado); }
      catch (e) { $('msg').textContent = e.message; b.disabled = false; }
    };
    $('botones').append(b);
  }
}

async function inicio() {
  cfg = await chrome.storage.local.get(['url', 'token']);
  if (!cfg.url || !cfg.token) { $('config').hidden = false; return; }
  $('fichar').hidden = false;
  try { const d = await api('estado'); pinta(d.estado, ` · hoy ${d.hoy} h`); }
  catch (e) { $('msg').textContent = e.message; }
}

$('guardar').onclick = async () => {
  const url = $('url').value.trim().replace(/\/+$/, '');
  if (!/^https:\/\//.test(url)) { $('msg').textContent = 'La dirección debe empezar por https://'; return; }
  if (!await chrome.permissions.request({ origins: [`${url}/*`] })) { $('msg').textContent = 'Hace falta el permiso para conectar con INDA.'; return; }
  await chrome.storage.local.set({ url, token: $('token').value.trim() });
  $('config').hidden = true; inicio();
};
$('cambiar').onclick = e => { e.preventDefault(); $('fichar').hidden = true; $('config').hidden = false; $('url').value = cfg.url || ''; };
inicio();
