// Variables globales para empleados
let datosEmpleado = {};
let misPaquetes = [];
let miVehiculo = {};
let miRuta = {};

// Configurar eventos específicos del panel de empleado
function configurarEventosEmpleado() {
    console.log('Configurando eventos del empleado...');
    
    // Configurar el botón de cerrar sesión
    const btnCerrarSesion = document.querySelector('.btn-salir');
    if (btnCerrarSesion) {
        btnCerrarSesion.addEventListener('click', cerrarSesion);
    }
    
    // Configurar el botón de menú móvil
    const btnMenuMovil = document.getElementById('btnMenuMovil');
    const barraLateral = document.getElementById('barraLateral');
    const contenidoPrincipal = document.querySelector('.contenido-principal');
    
    if (btnMenuMovil && barraLateral && contenidoPrincipal) {
        btnMenuMovil.addEventListener('click', function() {
            barraLateral.classList.toggle('activa');
            contenidoPrincipal.classList.toggle('desplazado');
        });
    }
    
    // Configurar el botón de notificaciones
    const btnNotificaciones = document.querySelector('.btn-notificaciones');
    if (btnNotificaciones) {
        btnNotificaciones.addEventListener('click', verNotificaciones);
    }
}

function cargarDatosPerfil() {
    fetch('php/empleado.php?accion=obtener_datos_empleado', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (!d || !d.exito || !d.datos) {
                mostrarNotificacion('Error al cargar datos del empleado', 'error');
                return;
            }
            const emp = d.datos;
            const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = String(val ?? ''); };
            setVal('perfilNombre', emp.nombre);
            setVal('perfilUsuario', emp.usuario);
            setVal('perfilEmail', emp.email);
        })
        .catch(() => mostrarNotificacion('Error al cargar datos del empleado', 'error'));
}

function cargarMisPaquetes() {
    const tbody = document.getElementById('cuerpoTablaMisPaquetes');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7">Cargando...</td></tr>';
    fetch('php/empleado.php?accion=mis_paquetes', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (!tbody) return;
            if (!d || !d.exito) {
                const msg = (d && d.mensaje) ? d.mensaje : 'No se pudieron cargar tus paquetes';
                tbody.innerHTML = `<tr><td colspan="7">${msg}</td></tr>`;
                return;
            }
            const rows = Array.isArray(d.datos) ? d.datos : [];
            if (rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7">No tienes paquetes asignados</td></tr>';
                return;
            }
            const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            tbody.innerHTML = rows.map(p => {
                const estado = esc(p.estado || 'pendiente');
                const fecha = p.fecha_envio ? new Date(p.fecha_envio).toLocaleDateString() : '';
                return `
                    <tr>
                        <td>${esc(p.codigo)}</td>
                        <td>${esc(p.destinatario)}</td>
                        <td>${esc(p.direccion || '')}</td>
                        <td>${estado}</td>
                        <td>${p.peso != null ? esc(p.peso) : ''}</td>
                        <td>${esc(fecha)}</td>
                        <td>
                            <button class="btn btn-pequeno">Ver</button>
                        </td>
                    </tr>
                `;
            }).join('');
        })
        .catch(err => {
            if (tbody) tbody.innerHTML = `<tr><td colspan="7">Error: ${err.message}</td></tr>`;
        });
}

function cargarResumenEmpleado() {
    fetch('php/empleado.php?accion=resumen', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (!d || !d.exito || !d.datos) return;
            const x = d.datos;
            const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = String(val ?? '0'); };
            set('misPaquetes', x.mis_paquetes);
            set('enRuta', x.en_ruta);
            set('entregadosHoy', x.entregados_hoy);
            set('pendientes', x.pendientes);
        })
        .catch(console.error);
}

// Inicializar cuando carga la página
document.addEventListener('DOMContentLoaded', function() {
    verificarSesion();
    
    // Inicializar el mapa si existe el contenedor
    if (document.getElementById('map')) {
        initMap();
    }
    
    // Función para inicializar la aplicación cuando el mapa esté listo
    function initializeApp() {
        console.log('Mapa listo, continuando con la inicialización...');
        cargarDatosEmpleado();
        configurarEventosEmpleado();
        if (typeof cargarResumenEmpleado === 'function') { cargarResumenEmpleado(); }
        // Cargar y mostrar Mis Paquetes de inmediato
        if (typeof cargarMisPaquetes === 'function') { try { cargarMisPaquetes(); } catch(_){} }
        if (typeof mostrarSeccion === 'function') {
            const enlace = document.querySelector('.menu a[data-seccion="mis-paquetes"]');
            mostrarSeccion('mis-paquetes', enlace || null);
        }
        
        // Configurar el botón de iniciar ruta
        const iniciarRutaBtn = document.getElementById('iniciarRutaBtn');
        if (iniciarRutaBtn) {
            iniciarRutaBtn.addEventListener('click', function() {
                iniciarSeguimientoUbicacion();
                this.disabled = true;
                this.textContent = 'Ruta en curso';
            });
        }
    }
    
    // Si el mapa ya está inicializado, inicializar la aplicación
    if (window.mapInitialized) {
        initializeApp();
    } else {
        // Si no, esperar al evento de inicialización del mapa
        window.addEventListener('mapInitialized', initializeApp);
    }
});

// Verificar sesión del empleado
function verificarSesion() {
    // Esta función ahora está vacía ya que la verificación real se hace en el HTML
    // Los datos del empleado se cargarán desde el servidor
}

// Función para cerrar sesión
function cerrarSesion() {
    if (!confirm("¿Está seguro que desea cerrar sesión?")) {
        return; // Si el usuario cancela, no hace nada
    }

    fetch('php/cerrar_sesion.php')
        .then(response => {
            // Ojo: tu cerrar_sesion.php hace un redirect, no devuelve JSON
            if (response.redirected) {
                window.location.href = response.url;
                return;
            }
            return response.json();
        })
        .then(data => {
            if (data && data.exito) {
                window.location.href = 'login.html';
            } else if (data) {
                mostrarNotificacion('Error al cerrar sesión', 'error');
            }
        })
        .catch(error => {
            console.error('Error al cerrar sesión:', error);
            mostrarNotificacion('Error al cerrar sesión', 'error');
        });
}

// Función para mostrar notificaciones
function verNotificaciones() {
    // Aquí puedes implementar la lógica para mostrar notificaciones
    // Por ahora, mostramos un mensaje simple
    mostrarNotificacion('No hay notificaciones nuevas', 'info');
    
    // En una implementación real, podrías hacer una llamada al servidor
    // para obtener las notificaciones no leídas
    /*
    fetch('php/notificaciones.php?accion=obtener')
        .then(response => response.json())
        .then(data => {
            // Mostrar las notificaciones en un modal o dropdown
            console.log('Notificaciones:', data);
        })
        .catch(error => {
            console.error('Error al cargar notificaciones:', error);
            mostrarNotificacion('Error al cargar notificaciones', 'error');
        });
    */
}

// Cargar datos del empleado
function cargarDatosEmpleado() {
    fetch('php/empleado.php?accion=obtener_datos_empleado')
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                datosEmpleado = data.datos;
                actualizarUIEmpleado();
            } else {
                mostrarNotificacion('Error al cargar datos del empleado', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarNotificacion('Error de conexión', 'error');
        });
}

// Actualizar la interfaz con los datos del empleado
function actualizarUIEmpleado() {
    // Actualizar nombre de usuario
    const nombreUsuario = document.getElementById('nombreUsuario');
    if (nombreUsuario) {
        nombreUsuario.textContent = datosEmpleado.nombre || 'Empleado';
    }
    
    // Aquí puedes agregar más actualizaciones de UI según sea necesario
}

// Cargar la ruta del empleado
function cargarMiRuta() {
    const contenedorRuta = document.getElementById('detallesRuta');
    if (!contenedorRuta) return;
    
    contenedorRuta.innerHTML = '<p>Cargando ruta...</p>';
    
    fetch('php/empleado.php?accion=obtener_ruta')
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                miRuta = data.ruta;
                mostrarDetallesRuta();
            } else {
                contenedorRuta.innerHTML = `<p class="sin-ruta">${data.mensaje || 'No hay ruta asignada para hoy'}</p>`;
            }
        })
        .catch(error => {
            console.error('Error al cargar la ruta:', error);
            contenedorRuta.innerHTML = '<p class="error">Error al cargar la ruta. Intenta de nuevo más tarde.</p>';
        });
}

// Mostrar detalles de la ruta en la interfaz
function mostrarDetallesRuta() {
    const contenedorRuta = document.getElementById('detallesRuta');
    if (!contenedorRuta) return;
    
    if (miRuta && miRuta.paradas && miRuta.paradas.length > 0) {
        let html = `
            <div class="resumen-ruta">
                <div class="estado-ruta">
                    <span class="etiqueta">Estado:</span>
                    <span class="valor ${miRuta.estado || 'pendiente'}">${formatearEstado(miRuta.estado || 'pendiente')}</span>
                </div>
                <div class="total-paquetes">
                    <span class="etiqueta">Total paquetes:</span>
                    <span class="valor">${miRuta.paradas.length}</span>
                </div>
            </div>
            <div class="lista-paradas">
                <h4>Paradas de la ruta:</h4>
                <ul>
        `;
        
        miRuta.paradas.forEach((parada, index) => {
            html += `
                <li class="parada ${parada.estado || 'pendiente'}">
                    <span class="numero">${index + 1}.</span>
                    <div class="info-parada">
                        <span class="direccion">${parada.direccion || 'Dirección no disponible'}</span>
                        <span class="estado">${formatearEstado(parada.estado || 'pendiente')}</span>
                    </div>
                </li>
            `;
        });
        
        html += `
                </ul>
            </div>
            <button id="iniciarRutaBtn" class="btn btn-primario">
                <i class="fas fa-route"></i> Iniciar Ruta
            </button>
        `;
        
        contenedorRuta.innerHTML = html;
        
        // Configurar evento del botón de iniciar ruta
        const iniciarRutaBtn = document.getElementById('iniciarRutaBtn');
        if (iniciarRutaBtn) {
            iniciarRutaBtn.addEventListener('click', function() {
                iniciarSeguimientoUbicacion();
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Ruta en curso';
                mostrarNotificacion('Ruta iniciada. Se está rastreando tu ubicación.', 'exito');
            });
        }
    } else {
        contenedorRuta.innerHTML = '<p class="sin-ruta">No hay paradas asignadas para hoy.</p>';
    }
}

// Función para formatear el estado
function formatearEstado(estado) {
    const estados = {
        'pendiente': 'Pendiente',
        'en_camino': 'En Camino',
        'en_espera': 'En Espera',
        'entregado': 'Entregado',
        'fallido': 'Fallido'
    };
    return estados[estado] || estado;
}

// Variables para el mapa y seguimiento de ubicación
window.map = null;
window.watchId = window.watchId || null;
window.currentLocationMarker = window.currentLocationMarker || null;
window.routePolyline = window.routePolyline || null;
window.pathCoordinates = window.pathCoordinates || [];

// Función para inicializar el mapa
function initMap() {
    console.log('Inicializando mapa...');
    
    // Coordenadas de Lima por defecto
    const lima = { lat: -12.0464, lng: -77.0428 };
    
    // Opciones del mapa
    const mapOptions = {
        zoom: 12,
        center: lima,
        mapTypeId: 'roadmap',
        styles: [
            {
                featureType: 'poi',
                elementType: 'labels',
                stylers: [{ visibility: 'off' }]
            }
        ]
    };
    
    // Crear el mapa
    window.map = new google.maps.Map(document.getElementById('map'), mapOptions);
    
    // Agregar puntos fijos solicitados
    const puntos = [
        { lat: -12.0464, lng: -77.0428, titulo: 'Cliente 1' },
        { lat: -12.0500, lng: -77.0300, titulo: 'Cliente 2' },
        { lat: -12.0600, lng: -77.0550, titulo: 'Cliente 3' },
        { lat: -12.0430, lng: -77.0700, titulo: 'Cliente 4' }
    ];

    try {
        const bounds = new google.maps.LatLngBounds();
        puntos.forEach(p => {
            const position = { lat: p.lat, lng: p.lng };
            const marker = new google.maps.Marker({
                position,
                map: window.map,
                title: p.titulo,
                icon: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
            });
            const info = new google.maps.InfoWindow({
                content: `<strong>${p.titulo}</strong><br>(${p.lat}, ${p.lng})`
            });
            marker.addListener('click', () => info.open(window.map, marker));
            bounds.extend(position);
        });
        if (!bounds.isEmpty()) {
            window.map.fitBounds(bounds);
        }
        console.log('✅ Puntos agregados al mapa.');
        // Marcar que ya se agregaron para evitar duplicados si se usa el listener
        window.fixedPointsAdded = true;
    } catch (e) {
        console.error('No se pudieron agregar los puntos al mapa:', e);
    }

    // Marcar que el mapa está listo
    window.mapInitialized = true;
    console.log('Mapa inicializado correctamente');
    
    // Disparar evento personalizado cuando el mapa está listo
    const event = new Event('mapInitialized');
    window.dispatchEvent(event);
}

// Listener para agregar puntos cuando el mapa esté listo (según solicitud)
window.addEventListener('mapInitialized', () => {
    console.log('Mapa detectado. Agregando puntos...');

    const puntos = [
        { lat: -12.0464, lng: -77.0428, titulo: 'Oficina Central' },
        { lat: -12.0500, lng: -77.0300, titulo: 'Cliente 1' },
        { lat: -12.0600, lng: -77.0550, titulo: 'Cliente 2' },
        { lat: -12.0430, lng: -77.0700, titulo: 'Entrega Especial' }
    ];

    if (!window.map) {
        console.error('❌ El mapa aún no está disponible.');
        return;
    }

    // Evitar duplicados si ya fueron agregados dentro de initMap
    if (window.fixedPointsAdded) {
        console.log('Puntos fijos ya agregados. Se omite para evitar duplicados.');
        return;
    }

    const bounds = new google.maps.LatLngBounds();
    puntos.forEach(punto => {
        const marker = new google.maps.Marker({
            position: { lat: punto.lat, lng: punto.lng },
            map: window.map,
            title: punto.titulo,
            icon: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
        });

        const info = new google.maps.InfoWindow({
            content: `<strong>${punto.titulo}</strong><br>(${punto.lat}, ${punto.lng})`
        });

        marker.addListener('click', () => info.open(window.map, marker));
        bounds.extend({ lat: punto.lat, lng: punto.lng });
    });

    if (!bounds.isEmpty()) {
        window.map.fitBounds(bounds);
    }

    window.fixedPointsAdded = true;
    console.log('✅ Puntos agregados correctamente.');
});

// Función para iniciar el seguimiento de ruta
function iniciarRuta() {
    console.log('Iniciando seguimiento de ruta...');
    
    // Verificar si el mapa está inicializado
    if (!window.map) {
        console.error('El mapa no está inicializado');
        mostrarNotificacion('Error: El mapa no se ha cargado correctamente', 'error');
        
        // Intentar inicializar el mapa si no está listo
        if (typeof google !== 'undefined' && google.maps) {
            console.log('Inicializando mapa...');
            const lima = { lat: -12.0464, lng: -77.0428 };
            window.map = new google.maps.Map(document.getElementById('map'), {
                zoom: 12,
                center: lima,
                mapTypeId: 'roadmap'
            });
        } else {
            return;
        }
    }
    
    // Iniciar el seguimiento de ubicación
    iniciarSeguimientoUbicacion();
    
    // Actualizar los botones
    const botonesIniciar = [
        document.getElementById('iniciarRutaBtn'),
        document.getElementById('btnIniciarRutaVehiculo')
    ];
    
    botonesIniciar.forEach(boton => {
        if (boton) {
            boton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Siguiendo ruta...';
            boton.onclick = detenerSeguimientoUbicacion;
            boton.className = 'btn btn-secundario';
            
            // Cambiar a botón de detener después de 1 segundo
            setTimeout(() => {
                boton.innerHTML = '<i class="fas fa-stop"></i> Detener Seguimiento';
                boton.title = 'Haz clic para detener el seguimiento';
            }, 1000);
        }
    });
    
    mostrarNotificacion('Seguimiento de ruta iniciado. Moviéndote a tu ubicación actual...', 'exito');
}

// Iniciar seguimiento de ubicación
function iniciarSeguimientoUbicacion() {
    console.log('Iniciando seguimiento de ubicación...');
    
    // Verificar si el navegador soporta geolocalización
    if (!navigator.geolocation) {
        const mensaje = 'Tu navegador no soporta geolocalización';
        console.error(mensaje);
        mostrarNotificacion(mensaje, 'error');
        return;
    }

    // Opciones para la geolocalización
    const opciones = {
        enableHighAccuracy: true,  // Alta precisión
        maximumAge: 0,           // No usar caché
        timeout: 20000           // 20 segundos de espera
    };

    // Función para manejar el éxito de la geolocalización
    const exito = (posicion) => {
        console.log('Ubicación obtenida:', posicion);
        
        const pos = {
            lat: posicion.coords.latitude,
            lng: posicion.coords.longitude,
            accuracy: posicion.coords.accuracy
        };
        
        console.log('Coordenadas:', pos);
        console.log('Precisión: ±' + pos.accuracy + ' metros');

        // Crear o actualizar el marcador de ubicación
        if (!window.currentLocationMarker && window.google && window.google.maps) {
            console.log('Creando nuevo marcador...');
            window.currentLocationMarker = new google.maps.Marker({
                position: pos,
                map: window.map,
                title: 'Tu ubicación actual',
                icon: {
                    url: 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png',
                    scaledSize: new google.maps.Size(40, 40)
                },
                animation: google.maps.Animation.DROP
            });
            
            // Crear círculo de precisión
            window.accuracyCircle = new google.maps.Circle({
                strokeColor: '#1E88E5',
                strokeOpacity: 0.3,
                strokeWeight: 1,
                fillColor: '#1E88E5',
                fillOpacity: 0.15,
                map: window.map,
                center: pos,
                radius: pos.accuracy
            });
            
            // Centrar el mapa en la ubicación actual
            if (window.map) {
                window.map.setCenter(pos);
                window.map.setZoom(17); // Zoom más cercano para mejor visualización
            }
        } else if (window.currentLocationMarker) {
            console.log('Actualizando marcador existente...');
            window.currentLocationMarker.setPosition(pos);
            
            // Actualizar círculo de precisión
            if (window.accuracyCircle) {
                window.accuracyCircle.setCenter(pos);
                window.accuracyCircle.setRadius(pos.accuracy);
            }
            
            // Mover el mapa suavemente a la nueva posición
            if (window.map) {
                window.map.panTo(pos);
            }
        }

        // Agregar la posición al array de coordenadas
        window.pathCoordinates = window.pathCoordinates || [];
        window.pathCoordinates.push(pos);

        // Actualizar la línea de ruta
        if (!window.routePolyline && window.google && window.google.maps) {
            console.log('Creando nueva línea de ruta...');
            window.routePolyline = new google.maps.Polyline({
                path: window.pathCoordinates,
                geodesic: true,
                strokeColor: '#1E88E5',
                strokeOpacity: 1.0,
                strokeWeight: 4,
                map: window.map
            });
        } else if (window.routePolyline) {
            window.routePolyline.setPath(window.pathCoordinates);
        }
    };

    // Función para manejar errores de geolocalización
    const error = (error) => {
        let mensaje = 'Error al obtener la ubicación: ';
        
        switch(error.code) {
            case error.PERMISSION_DENIED:
                mensaje += 'Permiso denegado por el usuario';
                break;
            case error.POSITION_UNAVAILABLE:
                mensaje += 'La información de ubicación no está disponible';
                break;
            case error.TIMEOUT:
                mensaje += 'Tiempo de espera agotado';
                break;
            case error.UNKNOWN_ERROR:
                mensaje += 'Error desconocido';
                break;
        }
        
        console.error(mensaje, error);
        mostrarNotificacion(mensaje, 'error');
    };

    // Si ya hay un seguimiento en curso, detenerlo primero
    if (window.watchId !== null) {
        navigator.geolocation.clearWatch(window.watchId);
    }

    // Iniciar el seguimiento de ubicación
    console.log('Solicitando ubicación...');
    navigator.geolocation.getCurrentPosition(
        (posicion) => {
            exito(posicion);
            
            // Iniciar seguimiento continuo
            window.watchId = navigator.geolocation.watchPosition(
                exito,
                error,
                opciones
            );
            
            console.log('Seguimiento de ubicación iniciado con ID:', window.watchId);
            mostrarNotificacion('Seguimiento de ubicación activo', 'exito');
        },
        error,
        opciones
    );
}

// Detener el seguimiento de ubicación
function detenerSeguimientoUbicacion() {
    console.log('Deteniendo seguimiento de ubicación...');
    
    // Detener el seguimiento de ubicación si está activo
    if (window.watchId !== null) {
        navigator.geolocation.clearWatch(window.watchId);
        window.watchId = null;
        console.log('Seguimiento de ubicación detenido');
    }
    
    // Actualizar los botones
    const botonesIniciar = [
        document.getElementById('iniciarRutaBtn'),
        document.getElementById('btnIniciarRutaVehiculo')
    ];
    
    botonesIniciar.forEach(boton => {
        if (boton) {
            boton.innerHTML = '<i class="fas fa-route"></i> Iniciar Ruta';
            boton.onclick = iniciarRuta;
            boton.className = 'btn btn-primario';
            boton.title = 'Haz clic para iniciar el seguimiento de ubicación';
        }
    });
    
    // Limpiar el marcador de ubicación actual
    if (window.currentLocationMarker) {
        window.currentLocationMarker.setMap(null);
        window.currentLocationMarker = null;
    }
    
    // Limpiar el círculo de precisión
    if (window.accuracyCircle) {
        window.accuracyCircle.setMap(null);
        window.accuracyCircle = null;
    }
    
    // No limpiar la ruta trazada para mantener el historial
    
    console.log('Seguimiento de ubicación detenido correctamente');
    mostrarNotificacion('Seguimiento de ubicación detenido', 'info');
}

// Detener el seguimiento cuando se cierre la página
window.addEventListener('beforeunload', () => {
    if (window.watchId !== null) {
        navigator.geolocation.clearWatch(window.watchId);
    }
});

// Función para inicializar la navegación
function inicializarNavegacion() {
    console.log('Inicializando navegación...');
    
    // Configurar manejadores de clic para los enlaces del menú
    const enlacesMenu = document.querySelectorAll('.menu a');
    console.log(`Se encontraron ${enlacesMenu.length} enlaces de menú`);
    
    enlacesMenu.forEach((enlace, index) => {
        console.log(`Configurando enlace #${index + 1}:`, enlace);
        
        enlace.addEventListener('click', function(e) {
            e.preventDefault();
            const seccionId = this.getAttribute('data-seccion');
            console.log('Menú clickeado - Sección:', seccionId);
            
            if (!seccionId) {
                console.error('El enlace no tiene atributo data-seccion:', this);
                return;
            }
            
            mostrarSeccion(seccionId, this);
        });
    });

    // Mostrar la sección de inicio por defecto
    console.log('Mostrando sección de inicio por defecto');
    const seccionInicio = document.getElementById('seccion-inicio');
    if (seccionInicio) {
        console.log('Sección de inicio encontrada, mostrando...');
        seccionInicio.style.display = 'block';
        // Asegurarse de que solo el enlace de inicio esté activo
        document.querySelector('.menu a.activo')?.classList.remove('activo');
        document.querySelector('.menu a[data-seccion="inicio"]')?.classList.add('activo');
    } else {
        console.error('No se pudo encontrar la sección de inicio');
    }
}

// Inicializar la navegación cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', inicializarNavegacion);

// Función para mostrar/ocultar secciones en el panel del empleado
function mostrarSeccion(seccionId, elemento) {
    console.clear(); // Limpiar la consola para mejor depuración
    console.log('=== INICIO mostrarSeccion ===');
    console.log('Sección solicitada:', seccionId);
    console.log('Elemento que disparó el evento:', elemento);
    
    try {
        // 1. Ocultar todas las secciones
        const secciones = document.querySelectorAll('.seccion');
        console.log(`Se encontraron ${secciones.length} secciones en total`);
        
        if (secciones.length === 0) {
            console.error('No se encontraron elementos con la clase "seccion"');
            return;
        }
        
        secciones.forEach((seccion, index) => {
            console.log(`Sección #${index + 1}:`, seccion.id, '- Clases:', seccion.className);
            seccion.style.display = 'none';
        });
        
        // 2. Mostrar la sección seleccionada
        // Primero intentar con guión bajo, luego con guión medio
        let idSeccion = 'seccion_' + seccionId.replace(/-/g, '_');
        console.log('Buscando elemento con ID (con guión bajo):', idSeccion);
        let seccion = document.getElementById(idSeccion);
        
        // Si no se encuentra con guión bajo, intentar con guión medio
        if (!seccion) {
            idSeccion = 'seccion-' + seccionId;
            console.log('No se encontró con guión bajo. Buscando con ID:', idSeccion);
            seccion = document.getElementById(idSeccion);
        }
        
        // Si aún no se encuentra, mostrar error
        if (!seccion) {
            console.error('No se pudo encontrar la sección con ID:', idSeccion);
            console.log('IDs de secciones disponibles:');
            document.querySelectorAll('.seccion').forEach(s => console.log('-', s.id));
            return;
        }
        
        if (seccion) {
            console.log('Sección encontrada, mostrando...');
            seccion.style.display = 'block';
            console.log('Sección mostrada. Display actual:', seccion.style.display);
            
            // Actualizar el título de la sección
            const tituloSeccion = document.getElementById('tituloSeccion');
            if (tituloSeccion) {
                const nuevoTitulo = elemento ? elemento.textContent.trim() : 'Panel de Empleado';
                console.log('Actualizando título a:', nuevoTitulo);
                tituloSeccion.textContent = nuevoTitulo;
            }
            
            // Cargar datos específicos de la sección
            console.log('Cargando datos para la sección:', seccionId);
            switch(seccionId) {
                case 'inicio':
                    console.log('Iniciando carga de datos del empleado...');
                    if (typeof cargarDatosEmpleado === 'function') {
                        cargarDatosEmpleado();
                    } else {
                        console.error('La función cargarDatosEmpleado no está definida');
                    }
                    if (typeof cargarResumenEmpleado === 'function') {
                        cargarResumenEmpleado();
                    }
                    break;
                    
                case 'mis-paquetes':
                    console.log('Iniciando carga de paquetes...');
                    if (typeof cargarMisPaquetes === 'function') {
                        cargarMisPaquetes();
                    } else {
                        console.error('La función cargarMisPaquetes no está definida');
                    }
                    break;
                    
                case 'ruta':
                    console.log('Iniciando carga de ruta...');
                    if (typeof cargarMiRuta === 'function') {
                        cargarMiRuta();
                    } else {
                        console.error('La función cargarMiRuta no está definida');
                    }
                    break;
                    
                case 'perfil':
                    console.log('Iniciando carga de perfil...');
                    if (typeof cargarDatosPerfil === 'function') {
                        cargarDatosPerfil();
                    } else {
                        console.error('La función cargarDatosPerfil no está definida');
                    }
                    break;
                    
                default:
                    console.log('No hay carga de datos específica para esta sección');
            }
        } else {
            console.error('❌ No se encontró la sección con ID:', idSeccion);
            console.log('Elementos con clase "sección":', document.getElementsByClassName('seccion'));
        }
        
        // 3. Actualizar la clase activa en los enlaces del menú
        console.log('Actualizando clases activas del menú...');
        const enlacesMenu = document.querySelectorAll('.menu a');
        console.log(`Se encontraron ${enlacesMenu.length} enlaces en el menú`);
        
        let enlacesActualizados = 0;
        enlacesMenu.forEach((enlace, index) => {
            console.log(`Enlace #${index + 1}:`, enlace.textContent.trim());
            if (enlace.classList.contains('activo')) {
                enlace.classList.remove('activo');
                console.log(`  ➖ Removida clase 'activo' de:`, enlace.textContent.trim());
                enlacesActualizados++;
            }
        });
        
        if (elemento) {
            console.log('Añadiendo clase activa a:', elemento.textContent.trim());
            elemento.classList.add('activo');
            console.log('Clases actuales del elemento:', elemento.className);
        } else {
            console.warn('No se recibió el elemento del menú para marcar como activo');
        }
        
        // 4. Desplazarse al inicio de la página
        console.log('Desplazando al inicio de la página...');
        window.scrollTo(0, 0);
        
        console.log('=== FIN mostrarSeccion ===');
        
    } catch (error) {
        console.error('❌ ERROR en mostrarSeccion:', error);
        console.error('Stack trace:', error.stack);
        mostrarNotificacion('Error al cambiar de sección: ' + error.message, 'error');
    }
}

// Función auxiliar para mostrar notificaciones
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
        background: ${tipo === 'exito' ? '#28a745' : tipo === 'error' ? '#dc3545' : '#17a2b8'};
    `;
    
    document.body.appendChild(notificacion);
    
    setTimeout(() => {
        notificacion.remove();
    }, 3000);

    
}
