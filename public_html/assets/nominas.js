// Nóminas: separar el PDF en páginas (pdf-lib), pedir a INDA de quién es cada una, agrupar las páginas
// seguidas de la misma persona y enviar solo cuando el admin lo aprueba.
(() => {
  const caja = document.querySelector('[data-nominas]');
  if (!caja || !window.PDFLib) return;
  const { PDFDocument } = PDFLib;
  const $ = s => document.querySelector(s);
  const personas = JSON.parse(caja.dataset.personas);
  const conIA = caja.dataset.ia === '1';
  const progreso = $('[data-progreso]');
  const meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  let grupos = [];

  const enviar = async (datos) => {
    const f = new FormData();
    f.append('csrf', caja.dataset.csrf);
    for (const [k, v] of Object.entries(datos)) f.append(k, v);
    const r = await fetch('?p=nominas', { method: 'POST', body: f });
    const j = await r.json().catch(() => ({ error: 'Respuesta no válida del servidor' }));
    if (!r.ok) throw new Error(j.error || 'Error');
    return j;
  };
  const pdfBlob = bytes => new Blob([bytes], { type: 'application/pdf' });
  const unir = async partes => {
    if (partes.length === 1) return partes[0].bytes;
    const doc = await PDFDocument.create();
    for (const p of partes) {
      const src = await PDFDocument.load(p.bytes);
      for (const pg of await doc.copyPages(src, src.getPageIndices())) doc.addPage(pg);
    }
    return doc.save();
  };

  $('[data-leer]').addEventListener('click', async () => {
    const archivos = [...$('[data-archivos]').files];
    if (!archivos.length) { progreso.textContent = 'Elige primero el PDF.'; return; }
    $('[data-leer]').disabled = true;
    try {
      // 1. Unidades: cada página si es un solo PDF con varias; cada archivo si son varios.
      const unidades = [];
      for (const a of archivos) {
        const bytes = new Uint8Array(await a.arrayBuffer());
        const src = await PDFDocument.load(bytes, { ignoreEncryption: true });
        if (archivos.length === 1 && src.getPageCount() > 1) {
          for (let i = 0; i < src.getPageCount(); i++) {
            const d = await PDFDocument.create();
            const [pg] = await d.copyPages(src, [i]);
            d.addPage(pg);
            unidades.push({ bytes: await d.save(), etiqueta: `pág. ${i + 1}` });
          }
        } else unidades.push({ bytes, etiqueta: a.name });
      }
      // 2. Leer cada unidad con la IA (de una en una, para no saturar).
      for (const [i, u] of unidades.entries()) {
        progreso.textContent = conIA ? `Leyendo ${i + 1} de ${unidades.length}…` : 'Preparando…';
        if (!conIA) { u.leido = { es_nomina: true }; continue; }
        try { u.leido = await enviar({ que: 'leer', pagina: new File([pdfBlob(u.bytes)], 'pagina.pdf', { type: 'application/pdf' }) }); }
        catch (e) { u.leido = { es_nomina: true, error: e.message }; }
      }
      // 3. Agrupar: páginas seguidas de la misma persona, y las que no son nómina (continuaciones) con la anterior.
      grupos = [];
      for (const u of unidades) {
        const l = u.leido, ult = grupos[grupos.length - 1];
        const misma = ult && conIA && (!l.es_nomina || (l.uid && l.uid === ult.uid) || (l.dni && l.dni === ult.dni));
        if (misma) { ult.partes.push(u); continue; }
        if (conIA && !l.es_nomina && !ult) continue; // portada o resumen al principio: se descarta
        grupos.push({ partes: [u], uid: l.uid || '', dni: l.dni || '', nombre: l.nombre || '', periodo: l.periodo || '', por: l.por || '', error: l.error || '' });
      }
      for (const g of grupos) g.bytes = await unir(g.partes);
      pintar();
      progreso.textContent = `${grupos.length} nóminas preparadas. Revísalas abajo.`;
    } catch (e) {
      progreso.textContent = 'No se pudo leer el PDF: ' + e.message;
    } finally {
      $('[data-leer]').disabled = false;
    }
  });

  function pintar() {
    const opciones = '<option value="">— Elegir persona —</option>' + personas.map(p => `<option value="${p.id}">${p.nombre.replace(/</g, '&lt;')}${p.dni ? ' · ' + p.dni : ''}</option>`).join('');
    const t = $('[data-tabla]');
    t.innerHTML = '<tr><th>Páginas</th><th>Leído por la IA</th><th>Persona</th><th>Título</th><th></th></tr>';
    grupos.forEach((g, i) => {
      const [a, m] = g.periodo.split('-');
      g.titulo = $('[data-titulo]').value.trim() || (g.periodo ? `Nómina ${meses[+m - 1]} ${a}` : 'Nómina');
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${g.partes.map(p => p.etiqueta).join(', ')}</td>
        <td>${g.error ? '<span class="error">' + g.error + '</span>' : [g.nombre, g.dni, g.periodo].filter(Boolean).join(' · ') || '—'}
          ${g.por ? `<br><small class="nota">emparejado por ${g.por === 'dni' ? 'DNI' : 'nombre'}</small>` : ''}</td>
        <td><select data-i="${i}">${opciones}</select></td>
        <td><input data-t="${i}" value="${g.titulo.replace(/"/g, '&quot;')}"></td>
        <td><a href="#" data-ver="${i}">Ver</a> <span data-estado="${i}"></span></td>`;
      tr.querySelector('select').value = g.uid || '';
      if (!g.uid) tr.querySelector('select').classList.add('falta');
      t.append(tr);
    });
    t.onchange = e => {
      if (e.target.dataset.i !== undefined) { grupos[e.target.dataset.i].uid = e.target.value; e.target.classList.toggle('falta', !e.target.value); }
      if (e.target.dataset.t !== undefined) grupos[e.target.dataset.t].titulo = e.target.value;
      contar();
    };
    t.onclick = e => { if (e.target.dataset.ver !== undefined) { e.preventDefault(); open(URL.createObjectURL(pdfBlob(grupos[e.target.dataset.ver].bytes))); } };
    $('[data-revision]').hidden = false;
    contar();
  }
  const contar = () => {
    const n = grupos.filter(g => g.uid && !g.enviada).length;
    const b = $('[data-enviar]');
    b.textContent = `Enviar ${n} nóminas`;
    b.disabled = !n;
    const repes = grupos.map(g => g.uid).filter((u, i, a) => u && a.indexOf(u) !== i);
    b.title = repes.length ? 'Atención: hay personas con más de una nómina' : '';
  };

  $('[data-enviar]').addEventListener('click', async () => {
    const lista = grupos.filter(g => g.uid && !g.enviada);
    const repes = new Set(lista.map(g => g.uid)).size !== lista.length;
    if (!confirm(`Vas a enviar ${lista.length} nóminas. Cada persona recibirá solo la suya.${repes ? '\n\nOJO: alguna persona recibe más de una.' : ''}\n\n¿Confirmas el envío?`)) return;
    $('[data-enviar]').disabled = true;
    let ok = 0;
    for (const g of lista) {
      const i = grupos.indexOf(g), estado = document.querySelector(`[data-estado="${i}"]`);
      try {
        await enviar({ que: 'enviar', uid: g.uid, titulo: g.titulo, firma: $('[data-firma]').checked ? '1' : '', pdf: new File([pdfBlob(g.bytes)], 'nomina.pdf', { type: 'application/pdf' }) });
        g.enviada = true; ok++; estado.innerHTML = '<span class="pildora ok">Enviada</span>';
      } catch (e) { estado.innerHTML = '<span class="error">' + e.message + '</span>'; }
    }
    progreso.textContent = `${ok} de ${lista.length} nóminas enviadas.`;
    contar();
  });
})();
