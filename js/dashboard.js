// ===============================
// VARIABLES GLOBALES
// ===============================
let datosUsuario = {};
let datosPaquetes = [];
let datosRutas = [];
let datosVehiculos = [];

// ===============================
// INICIALIZACIÓN
// ===============================
document.addEventListener('DOMContentLoaded', function() {
    verificarSesion();
    cargarDatos();
    configurarEventos();
    if (typeof window.cargarRutasConfig === 'function') {
        try { window.cargarRutasConfig(); } catch(e) { console.warn('cargarRutasConfig init', e); }
    }
});

// ===============================
// SESIÓN
// ===============================
function verificarSesion() {
    // La verificación real se hace en el HTML
    // Los datos del usuario se cargarán desde el servidor
}

// ===============================
// CARGA DE DATOS PRINCIPALES
// ===============================
function cargarDatos() {
    cargarResumen();
    cargarPaquetes();
    cargarRutas();
    cargarActividad();
}

// ===============================
// RESUMEN DEL DASHBOARD
// ===============================
function cargarResumen() {
    fetch('php/dashboard.php?accion=resumen')
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                document.getElementById('totalPaquetes').textContent = data.datos.total_paquetes;
                document.getElementById('enTransito').textContent = data.datos.en_transito;
                document.getElementById('entregados').textContent = data.datos.entregados;
                document.getElementById('ingresos').textContent = '$' + formatearNumero(data.datos.ingresos);

                crearGraficoEstados(data.datos.estados);
            }
        })
        .catch(error => console.error('Error:', error));
}

// ===============================
// PAQUETES
// ===============================
function cargarPaquetes() {
    fetch('php/dashboard.php?accion=paquetes')
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                datosPaquetes = data.datos;
                mostrarPaquetes(datosPaquetes);
            }
        })
        .catch(error => console.error('Error:', error));
}

function mostrarPaquetes(paquetes) {
    const tbody = document.getElementById('cuerpoTablaPaquetes');
    tbody.innerHTML = '';

    paquetes.forEach(paquete => {
        const fila = document.createElement('tr');
        fila.innerHTML = `
            <td>${paquete.codigo}</td>
            <td>${paquete.remitente}</td>
            <td>${paquete.destinatario}</td>
            <td><span class="estado estado-${paquete.estado}">${formatearEstado(paquete.estado)}</span></td>
            <td>${paquete.peso} kg</td>
            <td>$${formatearNumero(paquete.precio)}</td>
            <td>${formatearFecha(paquete.fecha_envio)}</td>
            <td>
                <button class="btn btn-pequeno btn-primario" onclick="editarPaquete(${paquete.id})">Editar</button>
                <button class="btn btn-pequeno btn-secundario" onclick="verPaquete(${paquete.id})">Ver</button>
            </td>
        `;
        tbody.appendChild(fila);
    });
}

// ===============================
// RUTAS
// ===============================
function cargarRutas() {
    fetch('php/dashboard.php?accion=rutas')
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                datosRutas = data.datos;
                mostrarRutas(datosRutas);
            }
        })
        .catch(error => console.error('Error:', error));
}

function mostrarRutas(rutas) {
    const container = document.getElementById('listaRutas');
    container.innerHTML = '';

    // Rutas constantes (no editables/ni eliminables)
    if (typeof window !== 'undefined' && window.RUTAS) {
        Object.keys(window.RUTAS).forEach(nombreRuta => {
            const zonas = Array.isArray(window.RUTAS[nombreRuta]) ? window.RUTAS[nombreRuta] : [];
            const div = document.createElement('div');
            div.className = 'item-ruta';
            div.innerHTML = `
                <h4>${nombreRuta}</h4>
                <p><strong>Zonas:</strong> ${zonas.join(', ')}</p>
                <div class="acciones-item">
                    <button class="btn btn-pequeno btn-primario" disabled title="Ruta constante">Editar</button>
                    <button class="btn btn-pequeno btn-secundario" disabled title="Ruta constante">Eliminar</button>
                </div>
            `;
            container.appendChild(div);
        });
    }

    // Rutas dinámicas desde la BD (si existen)
    (Array.isArray(rutas) ? rutas : []).forEach(ruta => {
        const div = document.createElement('div');
        div.className = 'item-ruta';
        div.innerHTML = `
            <h4>${ruta.nombre}</h4>
            <p><strong>Origen:</strong> ${ruta.origen}</p>
            <p><strong>Destino:</strong> ${ruta.destino}</p>
            <p><strong>Distancia:</strong> ${ruta.distancia} km</p>
            <p><strong>Tiempo:</strong> ${ruta.tiempo_estimado} min</p>
            <div class="acciones-item">
                <button class="btn btn-pequeno btn-primario" onclick="editarRuta(${ruta.id})">Editar</button>
                <button class="btn btn-pequeno btn-secundario" onclick="eliminarRuta(${ruta.id})">Eliminar</button>
            </div>
        `;
        container.appendChild(div);
    });
}

// ===============================
// VEHÍCULOS
// ===============================
function cargarVehiculos() {
    fetch('php/dashboard.php?accion=vehiculos')
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                datosVehiculos = data.datos;
                mostrarVehiculos(datosVehiculos);
            }
        })
        .catch(error => console.error('Error:', error));
}

function mostrarVehiculos(vehiculos) {
    const container = document.getElementById('listaVehiculos');
    container.innerHTML = '';

    vehiculos.forEach(vehiculo => {
        const div = document.createElement('div');
        div.className = 'item-vehiculo';
        div.innerHTML = `
            <h4>${vehiculo.placa}</h4>
            <p><strong>Marca:</strong> ${vehiculo.marca}</p>
            <p><strong>Modelo:</strong> ${vehiculo.modelo}</p>
            <p><strong>Capacidad:</strong> ${vehiculo.capacidad} kg</p>
            <p><strong>Estado:</strong> <span class="estado estado-${vehiculo.estado}">${formatearEstado(vehiculo.estado)}</span></p>
            <div class="acciones-item">
                <button class="btn btn-pequeno btn-primario" onclick="editarVehiculo(${vehiculo.id})">Editar</button>
                <button class="btn btn-pequeno btn-secundario" onclick="eliminarVehiculo(${vehiculo.id})">Eliminar</button>
            </div>
        `;
        container.appendChild(div);
    });
}

// ===============================
// ACTIVIDAD RECIENTE
// ===============================
function cargarActividad() {
    fetch('php/dashboard.php?accion=actividad')
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                mostrarActividad(data.datos);
            }
        })
        .catch(error => console.error('Error:', error));
}

function mostrarActividad(actividades) {
    const container = document.getElementById('listaActividad');
    container.innerHTML = '';

    actividades.forEach(actividad => {
        const div = document.createElement('div');
        div.className = 'actividad-item';
        div.innerHTML = `
            <div>
                <strong>${actividad.descripcion}</strong>
                <br><small>${formatearFecha(actividad.fecha)}</small>
            </div>
            <span class="estado estado-${actividad.tipo}">${actividad.tipo}</span>
        `;
        container.appendChild(div);
    });
}

// ===============================
// EVENTOS Y FILTROS
// ===============================
function configurarEventos() {
    const buscar = document.getElementById('buscarPaquete');
    if (buscar) buscar.addEventListener('input', filtrarPaquetes);
    const filtro = document.getElementById('filtroEstado');
    if (filtro) filtro.addEventListener('change', filtrarPaquetes);
    const form = document.getElementById('formPaquete');
    if (form) form.addEventListener('submit', guardarPaquete);
}

function filtrarPaquetes() {
    const busqueda = document.getElementById('buscarPaquete').value.toLowerCase();
    const estado = document.getElementById('filtroEstado').value;

    let paquetesFiltrados = datosPaquetes.filter(paquete => {
        const coincideBusqueda =
            paquete.codigo.toLowerCase().includes(busqueda) ||
            paquete.destinatario.toLowerCase().includes(busqueda) ||
            paquete.remitente.toLowerCase().includes(busqueda);

        const coincidenEstado = !estado || paquete.estado === estado;

        return coincideBusqueda && coincidenEstado;
    });

    mostrarPaquetes(paquetesFiltrados);
}

// ===============================
// UTILIDADES DE UI
// ===============================
function mostrarSeccion(seccion, enlace) {
    document.querySelectorAll('.seccion').forEach(s => s.classList.remove('activa'));
    document.getElementById(`seccion-${seccion}`).classList.add('activa');

    document.querySelectorAll('.menu a').forEach(a => a.classList.remove('activo'));
    if (enlace && enlace.classList) {
        enlace.classList.add('activo');
    } else {
        const sel = document.querySelector(`.menu a[onclick*="mostrarSeccion('${seccion}'"]`);
        if (sel) sel.classList.add('activo');
    }

    const titulos = {
        'inicio': 'Dashboard',
        'paquetes': 'Gestión de Paquetes',
        'rutas': 'Gestión de Rutas',
        'reportes': 'Reportes'
    };
    document.getElementById('tituloSeccion').textContent = titulos[seccion];

    if (seccion === 'rutas' && typeof window.cargarRutasConfig === 'function') {
        try { window.cargarRutasConfig(); } catch(e) { console.warn('cargarRutasConfig nav', e); }
    }
}

function cerrarModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function mostrarNotificacion(mensaje, tipo) {
    const notificacion = document.createElement('div');
    notificacion.className = `notificacion notificacion-${tipo}`;
    notificacion.textContent = mensaje;
    notificacion.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 5px;
        color: white;
        font-weight: bold;
        z-index: 1001;
        animation: slideIn 0.3s ease;
        background: ${tipo === 'exito' ? '#28a745' : '#dc3545'};
    `;

    document.body.appendChild(notificacion);
    setTimeout(() => notificacion.remove(), 3000);
}

// ===============================
// FORMULARIO PAQUETES
// ===============================
function nuevoPaquete() {
    document.getElementById('modalPaquete').style.display = 'block';
    document.getElementById('formPaquete').reset();
}

function guardarPaquete(e) {
    e.preventDefault();

    const formData = new FormData();
    formData.append('accion', 'nuevo_paquete');
    formData.append('remitente', document.getElementById('remitente').value);
    formData.append('destinatario', document.getElementById('destinatario').value);
    formData.append('direccion_origen', document.getElementById('direccionOrigen').value);
    formData.append('direccion_destino', document.getElementById('direccionDestino').value);
    formData.append('peso', document.getElementById('peso').value);
    formData.append('precio', document.getElementById('precio').value);

    fetch('php/dashboard.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                cerrarModal('modalPaquete');
                cargarPaquetes();
                cargarResumen();
                mostrarNotificacion('Paquete creado exitosamente', 'exito');
            } else {
                mostrarNotificacion(data.mensaje, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarNotificacion('Error al guardar el paquete', 'error');
        });
}

// ===============================
// GRÁFICOS
// ===============================
function crearGraficoEstados(estados) {
    const canvas = document.getElementById('graficoEstados');
    const ctx = canvas.getContext('2d');

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    const colores = {
        'pendiente': '#ffc107',
        'en_transito': '#17a2b8',
        'entregado': '#28a745',
        'devuelto': '#dc3545'
    };

    const total = Object.values(estados).reduce((sum, val) => sum + val, 0);
    let anguloInicial = 0;
    const centerX = canvas.width / 2;
    const centerY = canvas.height / 2;
    const radio = Math.min(centerX, centerY) - 20;

    Object.entries(estados).forEach(([estado, cantidad]) => {
        if (cantidad > 0) {
            const angulo = (cantidad / total) * 2 * Math.PI;
            ctx.beginPath();
            ctx.arc(centerX, centerY, radio, anguloInicial, anguloInicial + angulo);
            ctx.lineTo(centerX, centerY);
            ctx.fillStyle = colores[estado];
            ctx.fill();
            anguloInicial += angulo;
        }
    });

    let y = 20;
    Object.entries(estados).forEach(([estado, cantidad]) => {
        if (cantidad > 0) {
            ctx.fillStyle = colores[estado];
            ctx.fillRect(10, y, 15, 15);
            ctx.fillStyle = '#333';
            ctx.font = '12px Arial';
            ctx.fillText(`${formatearEstado(estado)}: ${cantidad}`, 30, y + 12);
            y += 20;
        }
    });
}

// ===============================
// FUNCIONES AUXILIARES
// ===============================
function formatearNumero(numero) {
    return new Intl.NumberFormat('es-CO').format(numero);
}

function formatearFecha(fecha) {
    return new Date(fecha).toLocaleDateString('es-CO');
}

function formatearEstado(estado) {
    const estados = {
        'pendiente': 'Pendiente',
        'en_transito': 'En Tránsito',
        'entregado': 'Entregado',
        'devuelto': 'Devuelto',
        'disponible': 'Disponible',
        'en_ruta': 'En Ruta',
        'mantenimiento': 'Mantenimiento'
    };
    return estados[estado] || estado;
}

// ===============================
// PLACEHOLDERS (por implementar)
// ===============================
function editarPaquete(id) { mostrarNotificacion('Función de editar paquete - Por implementar', 'info'); }
function verPaquete(id) { mostrarNotificacion('Función de ver paquete - Por implementar', 'info'); }
function nuevaRuta() { mostrarNotificacion('Función de nueva ruta - Por implementar', 'info'); }
function editarRuta(id) { mostrarNotificacion('Función de editar ruta - Por implementar', 'info'); }
function eliminarRuta(id) { mostrarNotificacion('Función de eliminar ruta - Por implementar', 'info'); }
function nuevoVehiculo() { mostrarNotificacion('Función de nuevo vehículo - Por implementar', 'info'); }
function editarVehiculo(id) { mostrarNotificacion('Función de editar vehículo - Por implementar', 'info'); }
function eliminarVehiculo(id) { mostrarNotificacion('Función de eliminar vehículo - Por implementar', 'info'); }
function generarReporte() { mostrarNotificacion('Función de generar reporte - Por implementar', 'info'); }

function actualizar() {
    cargarDatos();
    mostrarNotificacion('Datos actualizados', 'exito');
}
