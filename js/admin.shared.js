(function(){
  function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  // Simple toast utility
  function showToast(msg, type){
    try{
      const id = 'hermes-toast-container';
      let cont = document.getElementById(id);
      if (!cont){
        cont = document.createElement('div');
        cont.id = id;
        cont.style.position = 'fixed';
        cont.style.right = '16px';
        cont.style.bottom = '16px';
        cont.style.zIndex = '100000';
        cont.style.display = 'flex';
        cont.style.flexDirection = 'column';
        cont.style.gap = '8px';
        document.body.appendChild(cont);
      }
    }catch(_){ alert(msg); }
  }
  document.addEventListener('DOMContentLoaded', function(){
    try{
      if (document.getElementById('cuerpoAsignacionEmpleados') && typeof window.cargarVistaAsignacion==='function') {
        window.cargarVistaAsignacion();
      }
    }catch(_){ }
  });

  // Expose assignment modal helpers globally to avoid scope issues
  if (typeof window.__asig_ctx === 'undefined') window.__asig_ctx = { empleado_id: 0, nombre: '', modo: 'asignar' };
  function ensureAsignacionModal(){
    let modal = document.getElementById('modalAsignacion');
    if (modal) return modal;
    console.debug('[Asignacion] Creando modal dinámicamente');
    modal = document.createElement('div');
    modal.id = 'modalAsignacion';
    modal.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;';
    modal.innerHTML = '<div style="background:#fff; width:95%; max-width:700px; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.2);">\
      <div style="padding:14px 18px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between;">\
        <h3 id="modalAsignacionTitulo" style="margin:0; font-size:18px;">Asignar rutas</h3>\
        <button id="modalAsignacionCerrar" class="btn btn-secundario" style="padding:6px 10px;">✕</button>\
      </div>\
      <div style="padding:16px; max-height:60vh; overflow:auto;">\
        <div id="modalAsignacionContenido"><p class="text-muted">Cargando...</p></div>\
      </div>\
      <div style="padding:12px 16px; border-top:1px solid #e9ecef; display:flex; gap:8px; justify-content:flex-end;">\
        <button id="modalAsignacionCancelar" class="btn btn-secundario">Cancelar</button>\
        <button id="modalAsignacionAceptar" class="btn btn-primario">Aceptar</button>\
      </div>\
    </div>';
    document.body.appendChild(modal);
    if (typeof window.wireModalAsignacionButtons==='function') window.wireModalAsignacionButtons();
    return modal;
  }
  if (typeof window.abrirModalAsignacion === 'undefined') {
    window.abrirModalAsignacion = async function(empleado_id, nombre){
      window.__asig_ctx = { empleado_id, nombre: nombre||'', modo: 'asignar' };
      const modal = ensureAsignacionModal();
      const titulo = document.getElementById('modalAsignacionTitulo');
      const cont = document.getElementById('modalAsignacionContenido');
      if (!modal || !titulo || !cont) return;
      console.debug('[Asignacion] abrirModalAsignacion', empleado_id, nombre);
      titulo.textContent = `Asignar rutas a ${nombre||('Empleado '+empleado_id)} (ID ${empleado_id})`;
      cont.innerHTML = '<p class="text-muted">Cargando rutas…</p>';
      modal.style.display = 'flex';
      try{
        const r = await fetch('php/admin.php?accion=tarifas_agrupadas', { cache:'no-store' });
        const d = await r.json();
        const data = d && d.exito && d.datos ? d.datos : {};
        cont.innerHTML = window.renderAgrupadas ? window.renderAgrupadas(data) : '<pre>'+esc(JSON.stringify(data,null,2))+'</pre>';
      }catch(e){ cont.innerHTML = '<p>Error al cargar rutas</p>'; }
      if (typeof window.wireModalAsignacionButtons==='function') window.wireModalAsignacionButtons();
    }
  }
  if (typeof window.abrirModalVaciar === 'undefined') {
    window.abrirModalVaciar = async function(empleado_id, nombre){
      window.__asig_ctx = { empleado_id, nombre: nombre||'', modo: 'vaciar' };
      const modal = ensureAsignacionModal();
      const titulo = document.getElementById('modalAsignacionTitulo');
      const cont = document.getElementById('modalAsignacionContenido');
      if (!modal || !titulo || !cont) return;
      console.debug('[Asignacion] abrirModalVaciar', empleado_id, nombre);
      titulo.textContent = `Vaciar asignaciones de ${nombre||('Empleado '+empleado_id)} (ID ${empleado_id})`;
      cont.innerHTML = '<p class="text-muted">Cargando rutas asignadas…</p>';
      modal.style.display = 'flex';
      try{
        const r = await fetch('php/admin.php?accion=todos_paquetes', { cache:'no-store' });
        const d = await r.json();
        const items = Array.isArray(d?.datos) ? d.datos : [];
        const distritos = Array.from(new Set(items.filter(p=> parseInt(p.empleado_id||'0',10)===empleado_id).map(p=> String(p.distrito||'').trim()).filter(Boolean)));
        const data = { 'Rutas asignadas': distritos };
        cont.innerHTML = window.renderAgrupadas ? window.renderAgrupadas(data) : '<pre>'+esc(JSON.stringify(data,null,2))+'</pre>';
        Array.from(cont.querySelectorAll('input[type="checkbox"]')).forEach(ch=> ch.checked = true);
      }catch(e){ cont.innerHTML = '<p>Error al cargar rutas asignadas</p>'; }
      if (typeof window.wireModalAsignacionButtons==='function') window.wireModalAsignacionButtons();
    }
  }
  if (typeof window.abrirModalHistorial === 'undefined') {
    window.abrirModalHistorial = async function(empleado_id, nombre){
      const modal = ensureAsignacionModal();
      const titulo = document.getElementById('modalAsignacionTitulo');
      const cont = document.getElementById('modalAsignacionContenido');
      const btnA = document.getElementById('modalAsignacionAceptar');
      if (!modal || !titulo || !cont) return;
      console.debug('[Asignacion] abrirModalHistorial', empleado_id, nombre);
      if (btnA) btnA.style.display = 'none';
      titulo.textContent = `Historial de ${nombre||('Empleado '+empleado_id)} (ID ${empleado_id})`;
      cont.innerHTML = '<p class="text-muted">Cargando historial…</p>';
      modal.style.display = 'flex';
      try{
        const r = await fetch(`/HERMES.EXPRESS/admin/asignacion/historial.php?empleado_id=${encodeURIComponent(empleado_id)}`, { cache:'no-store', credentials:'same-origin' });
        const raw = await r.text();
        if (!r.ok) {
          cont.innerHTML = `<div style="color:#b00020;">Error ${r.status}: ${esc(raw||'Fallo al obtener historial')}</div>`;
          return;
        }
        let d; try { d = JSON.parse(raw); } catch(_){ d = { exito:false, mensaje: raw }; }
        if (!d.exito) { cont.innerHTML = `<div style="color:#b00020;">${esc(d.mensaje||'No se pudo cargar historial')}</div>`; return; }
        cont.innerHTML = (window.renderTablaHistorial ? window.renderTablaHistorial(d.historial||[]) : '<pre>'+esc(JSON.stringify(d,null,2))+'</pre>');
      }catch(e){ cont.innerHTML = `<p>Error al cargar historial: ${esc(e.message||e)}</p>`; }
      const btnX = document.getElementById('modalAsignacionCerrar');
      const btnC = document.getElementById('modalAsignacionCancelar');
      const restore = ()=> { if (btnA) btnA.style.display = ''; };
      if (btnX) btnX.onclick = ()=> { restore(); window.cerrarModalAsignacion && window.cerrarModalAsignacion(); };
      if (btnC) btnC.onclick = ()=> { restore(); window.cerrarModalAsignacion && window.cerrarModalAsignacion(); };
      modal.addEventListener('click', function onBg(e){ if (e.target === modal){ restore(); window.cerrarModalAsignacion && window.cerrarModalAsignacion(); modal.removeEventListener('click', onBg); } });
    }
  }
  if (typeof window.cerrarModalAsignacion === 'undefined') {
    window.cerrarModalAsignacion = function(){ const m=document.getElementById('modalAsignacion'); if (m) m.style.display='none'; }
  }
  if (typeof window.wireModalAsignacionButtons === 'undefined') {
    window.wireModalAsignacionButtons = function(){
      const modal = document.getElementById('modalAsignacion');
      const btnX = document.getElementById('modalAsignacionCerrar');
      const btnC = document.getElementById('modalAsignacionCancelar');
      const btnA = document.getElementById('modalAsignacionAceptar');
      if (btnX) btnX.onclick = ()=> window.cerrarModalAsignacion();
      if (btnC) btnC.onclick = ()=> window.cerrarModalAsignacion();
      if (btnA) btnA.onclick = ()=> window.aceptarAsignacion && window.aceptarAsignacion();
      modal && modal.addEventListener('click', function(e){ if (e.target === modal) window.cerrarModalAsignacion(); });
    }
  }
  if (typeof window.renderAgrupadas === 'undefined') {
    window.renderAgrupadas = function(obj){
      const grupos = Object.keys(obj||{});
      if (!grupos.length) return '<p class="text-muted">No hay rutas configuradas.</p>';
      let html='';
      grupos.forEach(g=>{
        const zonas = Array.isArray(obj[g]) ? obj[g] : [];
        const gid = `grp_${g.replace(/[^a-z0-9]/gi,'_')}`;
        html += `<fieldset style="margin-bottom:14px; border:1px solid #e9ecef; border-radius:6px;"><legend style="padding:0 8px; font-weight:600;">${esc(g)}</legend><div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px,1fr)); gap:8px; padding:8px 12px;">${zonas.map((z,idx)=>{ const id=`${gid}_${idx}`; const val=String(z||'').trim(); return `<label for="${id}" style="display:flex; align-items:center; gap:6px;"><input type="checkbox" id="${id}" value="${esc(val)}"><span>${esc(val)}</span></label>`; }).join('')}</div></fieldset>`;
      });
      return html;
    }
  }
  if (typeof window.renderTablaHistorial === 'undefined') {
    window.renderTablaHistorial = function(rows){
      if (!Array.isArray(rows) || rows.length===0) return '<p class="text-muted">Sin historial.</p>';
      const esc2 = (s)=> String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      let html = '<div class="tabla-container"><table class="tabla" style="width:100%"><thead><tr><th>Fecha asignación</th><th>Ruta</th><th>Paquete</th><th>Cantidad</th><th>Estado actual</th><th>Fecha finalización</th></tr></thead><tbody>';
      html += rows.map(r=>{ const fa=r.fecha_asignacion||''; const ruta=r.ruta||''; const pkg=r.paquete_codigo||r.paquete_id||''; const estado=r.estado_actual||''; const fin=r.fecha_finalizacion||''; return `<tr><td>${esc2(fa)}</td><td>${esc2(ruta)}</td><td>${esc2(pkg)}</td><td class="text-center">1</td><td>${esc2(estado)}</td><td>${esc2(fin)}</td></tr>`; }).join('');
      html += '</tbody></table></div>';
      return html;
    }
  }
  if (typeof window.aceptarAsignacion === 'undefined') {
    window.aceptarAsignacion = async function(){
      const cont = document.getElementById('modalAsignacionContenido'); if (!cont) return;
      const checks = Array.from(cont.querySelectorAll('input[type="checkbox"]:checked'));
      const distritos = checks.map(ch=> ch.value).filter(Boolean);
      const ctx = window.__asig_ctx || { empleado_id:0, modo:'asignar' };
      if (!distritos.length) { alert('Seleccione al menos una subruta.'); return; }
      if (!ctx.empleado_id) { alert('Empleado inválido'); return; }
      try{
        if (ctx.modo === 'vaciar') {
          const r = await fetch('/HERMES.EXPRESS/admin/asignacion/vaciar.php', { method:'POST', headers: { 'Content-Type': 'application/json' }, credentials:'same-origin', body: JSON.stringify({ empleado_id: ctx.empleado_id, rutas: distritos }) });
          const raw = await r.text();
          let d; try { d = JSON.parse(raw); } catch(_){ d = { exito:false, mensaje: raw }; }
          if (!r.ok || !d.exito) { alert((d&&d.mensaje) ? d.mensaje : (`Error ${r.status}: ${raw.slice(0,200)}`)); return; }
          showToast(`Desasignados ${d.desasignados||0} paquetes`, 'ok');
        } else {
          const r = await fetch('/HERMES.EXPRESS/admin/asignacion/asignar.php', { method:'POST', headers: { 'Content-Type': 'application/json' }, credentials:'same-origin', body: JSON.stringify({ empleado_id: ctx.empleado_id, rutas: distritos }) });
          const raw = await r.text();
          let d; try { d = JSON.parse(raw); } catch(_){ d = { exito:false, mensaje: raw }; }
          if (!r.ok || !d.exito) { alert((d&&d.mensaje) ? d.mensaje : (`Error ${r.status}: ${raw.slice(0,200)}`)); return; }
          showToast(`Asignados ${d.asignados||0} paquetes`, 'ok');
        }
        window.cerrarModalAsignacion();
        if (typeof window.cargarVistaAsignacion === 'function') await window.cargarVistaAsignacion();
      }catch(e){ alert('Error al procesar: '+ e.message); }
    }
  }

  // ===== Rutas =====
  if (typeof window.cargarRutasConfig === 'undefined') {
    window.cargarRutasConfig = async function cargarRutasConfig(){
      try {
        const tbody = document.getElementById('cuerpoTablaRutasConfig');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4">Cargando...</td></tr>';
        const resp = await fetch('php/admin.php?accion=rutas', { cache: 'no-store', credentials: 'same-origin' });
        const raw = await resp.text();
        let data; try { data = JSON.parse(raw); } catch(err){
          tbody.innerHTML = `<tr><td colspan="4">Error: respuesta no JSON<br><small>${raw.slice(0,200)}</small></td></tr>`;
          console.error('cargarRutasConfig parse', err, raw.slice(0,500));
          return;
        }

  // Historial
  async function abrirModalHistorial(empleado_id, nombre){
    const modal = document.getElementById('modalAsignacion');
    const titulo = document.getElementById('modalAsignacionTitulo');
    const cont = document.getElementById('modalAsignacionContenido');
    const btnA = document.getElementById('modalAsignacionAceptar');
    if (!modal || !titulo || !cont) return;
    // En modo historial, ocultar botón Aceptar
    if (btnA) btnA.style.display = 'none';
    titulo.textContent = `Historial de ${nombre||('Empleado '+empleado_id)} (ID ${empleado_id})`;
    cont.innerHTML = '<p class="text-muted">Cargando historial…</p>';
    modal.style.display = 'flex';
    try{
      const r = await fetch(`admin/asignacion/historial.php?empleado_id=${encodeURIComponent(empleado_id)}`, { cache:'no-store' });
      const d = await r.json();
      if (!d.exito) { cont.innerHTML = `<p>${(d.mensaje||'No se pudo cargar el historial')}</p>`; return; }
      cont.innerHTML = renderTablaHistorial(d.historial||[]);
    }catch(e){
      cont.innerHTML = '<p>Error al cargar historial</p>';
    }
    // Restaurar visibilidad del botón Aceptar al cerrar
    const btnX = document.getElementById('modalAsignacionCerrar');
    const btnC = document.getElementById('modalAsignacionCancelar');
    const restore = ()=> { if (btnA) btnA.style.display = ''; };
    if (btnX) btnX.onclick = ()=> { restore(); cerrarModalAsignacion(); };
    if (btnC) btnC.onclick = ()=> { restore(); cerrarModalAsignacion(); };
    modal.addEventListener('click', function onBg(e){ if (e.target === modal){ restore(); cerrarModalAsignacion(); modal.removeEventListener('click', onBg); } });
  }

  function renderTablaHistorial(rows){
    if (!Array.isArray(rows) || rows.length===0) return '<p class="text-muted">Sin historial.</p>';
    const esc2 = (s)=> String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let html = '<div class="tabla-container"><table class="tabla" style="width:100%"><thead><tr>'+
               '<th>Fecha asignación</th><th>Ruta</th><th>Paquete</th><th>Cantidad</th><th>Estado actual</th><th>Fecha finalización</th>'+
               '</tr></thead><tbody>';
    html += rows.map(r => {
      const fa = r.fecha_asignacion || '';
      const ruta = r.ruta || '';
      const pkg = r.paquete_codigo || r.paquete_id || '';
      const estado = r.estado_actual || '';
      const fin = r.fecha_finalizacion || '';
      return `<tr><td>${esc2(fa)}</td><td>${esc2(ruta)}</td><td>${esc2(pkg)}</td><td class="text-center">1</td><td>${esc2(estado)}</td><td>${esc2(fin)}</td></tr>`;
    }).join('');
    html += '</tbody></table></div>';
    return html;
  }

  let __asig_ctx = { empleado_id: 0, nombre: '', modo: 'asignar' };

  async function abrirModalAsignacion(empleado_id, nombre){
    __asig_ctx = { empleado_id, nombre: nombre||'', modo: 'asignar' };
    const modal = document.getElementById('modalAsignacion');
    const titulo = document.getElementById('modalAsignacionTitulo');
    const cont = document.getElementById('modalAsignacionContenido');
    if (!modal || !titulo || !cont) return;
    titulo.textContent = `Asignar rutas a ${nombre||('Empleado '+empleado_id)} (ID ${empleado_id})`;
    cont.innerHTML = '<p class="text-muted">Cargando rutas…</p>';
    modal.style.display = 'flex';
    try{
      const r = await fetch('php/admin.php?accion=tarifas_agrupadas', { cache:'no-store' });
      const d = await r.json();
      const data = d && d.exito && d.datos ? d.datos : {};
      cont.innerHTML = renderAgrupadas(data);
    }catch(e){
      cont.innerHTML = '<p>Error al cargar rutas</p>';
    }
    wireModalAsignacionButtons();
  }

  async function abrirModalVaciar(empleado_id, nombre){
    __asig_ctx = { empleado_id, nombre: nombre||'', modo: 'vaciar' };
    const modal = document.getElementById('modalAsignacion');
    const titulo = document.getElementById('modalAsignacionTitulo');
    const cont = document.getElementById('modalAsignacionContenido');
    if (!modal || !titulo || !cont) return;
    titulo.textContent = `Vaciar asignaciones de ${nombre||('Empleado '+empleado_id)} (ID ${empleado_id})`;
    cont.innerHTML = '<p class="text-muted">Cargando rutas asignadas…</p>';
    modal.style.display = 'flex';
    try{
      const r = await fetch('php/admin.php?accion=todos_paquetes', { cache:'no-store' });
      const d = await r.json();
      const items = Array.isArray(d?.datos) ? d.datos : [];
      const distritos = Array.from(new Set(items
        .filter(p => parseInt(p.empleado_id||'0',10) === empleado_id)
        .map(p => String(p.distrito||'').trim())
        .filter(Boolean)
      ));
      const data = { 'Rutas asignadas': distritos };
      cont.innerHTML = renderAgrupadas(data);
      // marcar todo por defecto
      Array.from(cont.querySelectorAll('input[type="checkbox"]')).forEach(ch=> ch.checked = true);
    }catch(e){
      cont.innerHTML = '<p>Error al cargar rutas asignadas</p>';
    }
    wireModalAsignacionButtons();
  }

  function renderAgrupadas(obj){
    const grupos = Object.keys(obj||{});
    if (!grupos.length) return '<p class="text-muted">No hay rutas configuradas.</p>';
    let html = '';
    grupos.forEach(g=>{
      const zonas = Array.isArray(obj[g]) ? obj[g] : [];
      const gid = `grp_${g.replace(/[^a-z0-9]/gi,'_')}`;
      html += `<fieldset style="margin-bottom:14px; border:1px solid #e9ecef; border-radius:6px;">
        <legend style="padding:0 8px; font-weight:600;">${esc(g)}</legend>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px,1fr)); gap:8px; padding:8px 12px;">
          ${zonas.map((z,idx)=>{
            const id = `${gid}_${idx}`;
            const val = String(z||'').trim();
            return `<label for="${id}" style="display:flex; align-items:center; gap:6px;">
              <input type="checkbox" id="${id}" value="${esc(val)}">
              <span>${esc(val)}</span>
            </label>`;
          }).join('')}
        </div>
      </fieldset>`;
    });
    return html;
  }

  function wireModalAsignacionButtons(){
    const modal = document.getElementById('modalAsignacion');
    const btnX = document.getElementById('modalAsignacionCerrar');
    const btnC = document.getElementById('modalAsignacionCancelar');
    const btnA = document.getElementById('modalAsignacionAceptar');
    if (btnX) btnX.onclick = ()=> cerrarModalAsignacion();
    if (btnC) btnC.onclick = ()=> cerrarModalAsignacion();
    if (btnA) btnA.onclick = ()=> aceptarAsignacion();
    modal.addEventListener('click', function(e){ if (e.target === modal) cerrarModalAsignacion(); });
  }

  function cerrarModalAsignacion(){
    const modal = document.getElementById('modalAsignacion');
    if (modal) modal.style.display = 'none';
  }

  async function aceptarAsignacion(){
    const cont = document.getElementById('modalAsignacionContenido');
    if (!cont) return;
    const checks = Array.from(cont.querySelectorAll('input[type="checkbox"]:checked'));
    const distritos = checks.map(ch=> ch.value).filter(Boolean);
    if (!distritos.length) { alert('Seleccione al menos una subruta.'); return; }
    if (!__asig_ctx.empleado_id) { alert('Empleado inválido'); return; }
    try{
      if (__asig_ctx.modo === 'vaciar') {
        const r = await fetch('admin/asignacion/vaciar.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ empleado_id: __asig_ctx.empleado_id, rutas: distritos })
        });
        const d = await r.json();
        if (!d.exito) { alert(d.mensaje||'No se pudo desasignar'); return; }
        showToast(`Desasignados ${d.desasignados||0} paquetes`, 'ok');
      } else {
        const r = await fetch('admin/asignacion/asignar.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ empleado_id: __asig_ctx.empleado_id, rutas: distritos })
        });
        const d = await r.json();
        if (!d.exito) { alert(d.mensaje||'No se pudo asignar'); return; }
        showToast(`Asignados ${d.asignados||0} paquetes`, 'ok');
      }
      cerrarModalAsignacion();
      if (typeof window.cargarVistaAsignacion === 'function') await window.cargarVistaAsignacion();
    }catch(e){
      alert('Error al procesar: '+ e.message);
    }
  }
        if (!data || !data.exito || !Array.isArray(data.datos) || data.datos.length===0) {
          tbody.innerHTML = '<tr><td colspan="4">No hay rutas</td></tr>';
          return;
        }
        const fmtZonas = z => Array.isArray(z) ? z.join(', ') : String(z || '');
        tbody.innerHTML = data.datos.map(r => `
          <tr>
            <td>${esc(r.id)}</td>
            <td>${esc(r.nombre)}</td>
            <td>${esc(fmtZonas(r.zonas))}</td>
            <td><button class="btn btn-pequeno" disabled>Editar</button></td>
          </tr>
        `).join('');
      } catch(e){
        const tbody = document.getElementById('cuerpoTablaRutasConfig');
        if (tbody) tbody.innerHTML = '<tr><td colspan="4">Error al cargar rutas</td></tr>';
        console.error('cargarRutasConfig', e);
      }
    }
  }

  if (typeof window.seedRutasDesdeUI === 'undefined') {
    window.seedRutasDesdeUI = async function seedRutasDesdeUI(){
      try{
        const btns = Array.from(document.querySelectorAll('button')).filter(b => /Sincronizar rutas/i.test(b.textContent||''));
        btns.forEach(b=>{ b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sincronizando...'; });
        const form = new URLSearchParams(); form.append('accion','seed_rutas');
        const resp = await fetch('php/admin.php', { method:'POST', body: form });
        const raw = await resp.text();
        let data; try { data = JSON.parse(raw); } catch (e) { data = { exito:false, mensaje: 'Respuesta no JSON', raw }; }
        if (!data.exito) { alert('Error al sincronizar rutas: ' + (data.mensaje || 'Desconocido')); return; }
        alert(`Rutas sincronizadas. Creadas: ${data.creadas||0}, Actualizadas: ${data.actualizadas||0}`);
        if (typeof window.cargarRutasConfig === 'function') window.cargarRutasConfig();
      } catch(e){
        console.error('seedRutasDesdeUI', e);
        alert('Error al sincronizar rutas: ' + e.message);
      } finally {
        const btns = Array.from(document.querySelectorAll('button')).filter(b => /Sincronizar rutas/i.test(b.textContent||''));
        btns.forEach(b=>{ b.disabled = false; b.textContent = 'Sincronizar rutas'; });
      }
    }
  }

  // ===== Asignación =====
  if (typeof window.cargarPaquetesSinAsignar === 'undefined') {
    window.cargarPaquetesSinAsignar = async function cargarPaquetesSinAsignar(){
      try {
        const resp = await fetch('php/admin.php?accion=todos_paquetes', { cache: 'no-store' });
        const d = await resp.json();
        if (!d.exito) return;
        const tbody = document.getElementById('cuerpoSinAsignar');
        if (!tbody) return;
        const sin = (d.datos || []).filter(p => !p.empleado_id);
        tbody.innerHTML = sin.map(p => `
          <tr>
            <td>${esc(p.id)}</td>
            <td>${esc(p.codigo || '')}</td>
            <td>${esc(p.destinatario || '')}</td>
            <td>${esc(p.distrito || '')}</td>
            <td><button class="btn btn-pequeno btn-primario" onclick="reasignarPrompt(${Number(p.id)})">Reasignar</button></td>
          </tr>
        `).join('');
      } catch (e) { console.error('cargarPaquetesSinAsignar', e); }
    }
  }

  if (typeof window.asignarPaquetesAuto === 'undefined') {
    window.asignarPaquetesAuto = async function asignarPaquetesAuto(){
      try {
        const fd = new URLSearchParams(); fd.append('accion','asignar_paquetes_auto');
        const r = await fetch('php/admin.php', { method:'POST', body: fd });
        const d = await r.json();
        alert(`Asignación: asignados=${d.asignados||0}, sin_ruta=${d.sin_ruta||0}, sin_empleado=${d.sin_empleado||0}`);
        if (typeof cargarPaquetesSinAsignar==='function') await cargarPaquetesSinAsignar();
        if (typeof cargarResumenAsignados==='function') await cargarResumenAsignados();
      } catch(e){ console.error('asignarPaquetesAuto', e); alert('Error asignando'); }
    }
  }

  if (typeof window.reasignarPrompt === 'undefined') {
    window.reasignarPrompt = async function reasignarPrompt(paqueteId){
      try {
        const empleadoId = prompt('ID de empleado destino:');
        if (!empleadoId) return;
        const fd = new FormData();
        fd.append('accion','reasignar_paquete');
        fd.append('paquete_id', String(paqueteId));
        fd.append('empleado_id', String(empleadoId));
        const r = await fetch('php/admin.php', { method:'POST', body: fd });
        const d = await r.json();
        if (!d.exito) { alert(d.mensaje || 'No se pudo reasignar'); return; }
        if (typeof cargarPaquetesSinAsignar==='function') await cargarPaquetesSinAsignar();
        if (typeof cargarResumenAsignados==='function') await cargarResumenAsignados();
      } catch(e){ console.error('reasignarPrompt', e); }
    }
  }

  if (typeof window.cargarResumenAsignados === 'undefined') {
    window.cargarResumenAsignados = async function cargarResumenAsignados(){
      try{
        const r = await fetch('php/admin.php?accion=todos_paquetes', { cache: 'no-store' });
        const d = await r.json();
        if (!d.exito) return;
        const items = Array.isArray(d.datos) ? d.datos : [];
        const asignados = items.filter(x => {
          const raw = (x.empleado_id !== undefined && x.empleado_id !== null) ? String(x.empleado_id).trim() : '';
          const id = parseInt(raw, 10);
          return Number.isFinite(id) && id > 0;
        });
        const porEmpleado = {};
        for (const p of asignados){
          const raw = (p.empleado_id !== undefined && p.empleado_id !== null) ? String(p.empleado_id).trim() : '';
          const empId = parseInt(raw, 10) || 0; if (!empId) continue;
          const key = String(empId);
          const nombre = (p.empleado_nombre && String(p.empleado_nombre).trim()) ? String(p.empleado_nombre).trim() : ('Empleado '+key);
          if (!porEmpleado[key]) porEmpleado[key] = { empleado_id: empId, empleado_nombre: nombre, zona: p.zona || '', total:0, lista: [] };
          porEmpleado[key].total++;
          porEmpleado[key].lista.push({ id: p.id, codigo: p.codigo || '', destinatario: p.destinatario || p.consignado || '', distrito: p.distrito || '' });
        }
        const tbody = document.getElementById('cuerpoResumenAsignados');
        if (!tbody) return;
        const arr = Object.values(porEmpleado).sort((a,b)=>b.total-a.total);
        if (arr.length === 0) {
          tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Sin asignaciones</td></tr>';
          const cont = document.getElementById('listaAsignadosEmpleado'); if (cont) cont.style.display='';
          return;
        }
        tbody.innerHTML = arr.map(e => `
          <tr>
            <td>${esc(e.empleado_nombre)}</td>
            <td>${esc(e.zona || '')}</td>
            <td class="text-center">${esc(e.total)}</td>
            <td><button class="btn btn-pequeno" onclick="verPaquetesEmpleado(${e.empleado_id})">Ver</button></td>
          </tr>
        `).join('');
        window.__asignados_por_empleado = porEmpleado;
      }catch(err){ console.error('cargarResumenAsignados', err); }
    }
  }

  if (typeof window.verPaquetesEmpleado === 'undefined') {
    window.verPaquetesEmpleado = function verPaquetesEmpleado(empleadoId){
      const data = (window.__asignados_por_empleado||{})[String(empleadoId)];
      const cont = document.getElementById('listaAsignadosEmpleado');
      const titulo = document.getElementById('tituloListaEmpleado');
      const tbody = document.getElementById('cuerpoListaEmpleado');
      if (!data || !cont || !tbody || !titulo) return;
      titulo.textContent = `Paquetes de ${data.empleado_nombre} (${data.total})`;
      tbody.innerHTML = data.lista.map(p => `
        <tr>
          <td>${esc(p.id)}</td>
          <td>${esc(p.codigo)}</td>
          <td>${esc(p.destinatario)}</td>
          <td>${esc(p.distrito)}</td>
        </tr>
      `).join('');
      cont.style.display = '';
    }
  }

  // ===== Vista Asignación (panel admin) =====
  if (typeof window.cargarVistaAsignacion === 'undefined') {
    window.cargarVistaAsignacion = async function cargarVistaAsignacion() {
      try {
        const tbody = document.getElementById('cuerpoAsignacionEmpleados');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5">Cargando...</td></tr>';

        // 1) Obtener empleados (usaremos endpoint existente php/admin.php?accion=empleados)
        const rEmp = await fetch('php/admin.php?accion=empleados', { cache: 'no-store' });
        const dEmp = await rEmp.json();
        const empleadosAll = Array.isArray(dEmp?.datos) ? dEmp.datos : [];
        const empleados = empleadosAll.filter(e => String(e.tipo||'').toLowerCase() === 'empleado');

        // 2) Obtener paquetes para contar asignados por empleado
        const rPk = await fetch('php/admin.php?accion=todos_paquetes', { cache: 'no-store' });
        const dPk = await rPk.json();
        const paquetes = Array.isArray(dPk?.datos) ? dPk.datos : [];
        const conteo = {};
        for (const p of paquetes) {
          const raw = (p.empleado_id !== undefined && p.empleado_id !== null) ? String(p.empleado_id).trim() : '';
          const id = parseInt(raw, 10);
          if (Number.isFinite(id) && id > 0) {
            conteo[id] = (conteo[id]||0) + 1;
          }
        }

        // 3) Renderizar filas
        if (empleados.length === 0) {
          tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No hay empleados</td></tr>';
          return;
        }
        tbody.innerHTML = empleados.map(e => {
          const asignados = conteo[e.id] || 0;
          const rol = (e.tipo || 'empleado');
          return `
            <tr>
              <td>${esc(e.nombre || e.usuario || '')}</td>
              <td>${esc(e.id)}</td>
              <td>${esc(rol)}</td>
              <td class="text-center">${asignados}</td>
              <td>
                <div class="d-flex" style="gap:6px;flex-wrap:wrap;">
                  <button class="btn btn-primario" data-emp-id="${e.id}" data-action="asignar">Asignar</button>
                  <button class="btn btn-peligro" data-emp-id="${e.id}" data-action="vaciar">Vaciar</button>
                  <button class="btn btn-secundario" data-emp-id="${e.id}" data-action="historial">Historial</button>
                </div>
              </td>
            </tr>
          `;
        }).join('');

        console.debug('[Asignacion] Delegation binding active');
        tbody.addEventListener('click', function(ev){
          const btn = ev.target.closest('button[data-action]');
          if (!btn) return;
          const empId = parseInt(btn.getAttribute('data-emp-id')||'0',10) || 0;
          const action = btn.getAttribute('data-action');
          if (action === 'asignar' && empId) {
            const tr = btn.closest('tr');
            const nombre = tr ? (tr.children[0]?.textContent||'') : '';
            if (typeof window.abrirModalAsignacion === 'function') window.abrirModalAsignacion(empId, nombre);
          }
          if (action === 'vaciar' && empId) {
            const tr = btn.closest('tr');
            const nombre = tr ? (tr.children[0]?.textContent||'') : '';
            if (typeof window.abrirModalVaciar === 'function') window.abrirModalVaciar(empId, nombre);
          }
          if (action === 'historial' && empId) {
            const tr = btn.closest('tr');
            const nombre = tr ? (tr.children[0]?.textContent||'') : '';
            if (typeof window.abrirModalHistorial === 'function') window.abrirModalHistorial(empId, nombre);
          }
        }, { once: false });

        // Explicit per-button bindings as primary handler (in addition to inline/ delegation)
        try {
          const btnAsignarList = tbody.querySelectorAll('button[data-action="asignar"]');
          const btnVaciarList = tbody.querySelectorAll('button[data-action="vaciar"]');
          const btnHistList = tbody.querySelectorAll('button[data-action="historial"]');
          btnAsignarList.forEach(b=>{
            b.addEventListener('click', function(ev){
              ev.stopPropagation();
              const id = parseInt(this.getAttribute('data-emp-id')||'0',10)||0;
              const nombre = this.closest('tr')?.children[0]?.textContent||'';
              if (typeof window.abrirModalAsignacion==='function') window.abrirModalAsignacion(id, nombre);
            });
          });
          btnVaciarList.forEach(b=>{
            b.addEventListener('click', function(ev){
              ev.stopPropagation();
              const id = parseInt(this.getAttribute('data-emp-id')||'0',10)||0;
              const nombre = this.closest('tr')?.children[0]?.textContent||'';
              if (typeof window.abrirModalVaciar==='function') window.abrirModalVaciar(id, nombre);
            });
          });
          btnHistList.forEach(b=>{
            b.addEventListener('click', function(ev){
              ev.stopPropagation();
              const id = parseInt(this.getAttribute('data-emp-id')||'0',10)||0;
              const nombre = this.closest('tr')?.children[0]?.textContent||'';
              if (typeof window.abrirModalHistorial==='function') window.abrirModalHistorial(id, nombre);
            });
          });
          console.debug('[Asignacion] Explicit button bindings attached', btnAsignarList.length, btnVaciarList.length, btnHistList.length);
        } catch(err){ console.warn('Binding explicit handlers failed', err); }

      } catch (e) {
        console.error('cargarVistaAsignacion', e);
        const tbody = document.getElementById('cuerpoAsignacionEmpleados');
        if (tbody) tbody.innerHTML = '<tr><td colspan="5">Error al cargar la vista</td></tr>';
      }
    }
  }

})();
