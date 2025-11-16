// Variable para el modal
let usuarioModal;

// Asegurarse de que Bootstrap esté cargado
document.addEventListener('DOMContentLoaded', function() {
    // Verificar que Bootstrap esté disponible
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modalEl = document.getElementById('usuarioModal');
        if (modalEl) usuarioModal = new bootstrap.Modal(modalEl);
    }

    // Instancia global para poder recargar desde otros handlers
    window.usuariosDT = window.usuariosDT || null;

    function initTablaUsuarios() {
        const $tablaUsuarios = $('#usuariosTable');
        if ($tablaUsuarios.length === 0) return false;
        if ($.fn.dataTable.isDataTable('#usuariosTable')) {
            // Si ya existe, intenta referenciar la instancia global
            if (!window.usuariosDT) {
                window.usuariosDT = $('#usuariosTable').DataTable();
            }
            return true;
        }
        
        // Inicializar DataTable
        window.usuariosDT = $('#usuariosTable').DataTable({
            order: [[0, 'desc']],
            processing: true,
            serverSide: true,
            responsive: true,
            deferRender: false,
            language: {
                emptyTable: 'No hay datos disponibles',
                loadingRecords: 'Cargando...',
                processing: 'Procesando...',
                search: 'Buscar:',
                zeroRecords: 'No se encontraron registros coincidentes',
                lengthMenu: 'Mostrar _MENU_ registros por página',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                infoEmpty: 'No hay registros disponibles',
                infoFiltered: '(filtrado de _MAX_ registros en total)',
                paginate: {
                    first: 'Primero',
                    last: 'Último',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            },
            ajax: {
                url: '/HERMES.EXPRESS/php/usuarios.php',
                type: 'GET',
                data: { action: 'listar' },
                error: function(xhr, error, thrown) {
                    console.error('Error al cargar los datos:', error);
                    mostrarAlerta('danger', 'Error al cargar los datos de usuarios');
                }
            },
            columns: [
                { 
                    data: 'id',
                    className: 'text-center',
                    width: '5%'
                },
                { 
                    data: 'usuario',
                    render: function(data) {
                        return `<strong>${data}</strong>`;
                    }
                },
                { 
                    data: 'nombre',
                    render: function(data) {
                        return data || '<span class="text-muted">No especificado</span>';
                    }
                },
                { 
                    data: 'email',
                    render: function(data) {
                        return data || '-';
                    }
                },
                { 
                    data: 'tipo',
                    className: 'text-center',
                    render: function(data) {
                        const key = String(data||'').trim().toLowerCase();
                        const tipos = {
                            'admin': '<span class="badge bg-danger">Administrador</span>',
                            'asistente': '<span class="badge bg-primary">Asistente</span>',
                            'empleado': '<span class="badge bg-info text-dark">Empleado</span>'
                        };
                        return tipos[key] || `<span class="badge bg-secondary">${data||'-'}</span>`;
                    }
                },
                { 
                    data: 'activo',
                    className: 'text-center',
                    render: function(data) {
                        const val = (typeof data === 'number') ? data : String(data||'').trim().toLowerCase();
                        const isActive = (val === 1 || val === '1' || val === 'activo' || val === 'true' || val === 'sí' || val === 'si');
                        return isActive 
                            ? '<span class="badge bg-success">ACTIVO</span>' 
                            : '<span class="badge bg-secondary">INACTIVO</span>';
                    }
                },
                { 
                    data: 'fecha_creacion',
                    render: function(data) {
                        return data ? new Date(data).toLocaleDateString('es-ES') : '-';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    className: 'text-center',
                    render: function(data, type, row) {
                        if (row.id === 1 || row.usuario === 'admin') {
                            return '<span class="badge bg-secondary">Protegido</span>';
                        }
                        
                        return `
                            <button class="btn btn-sm btn-primary btn-editar me-1" data-id="${row.id}" title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger btn-eliminar" data-id="${row.id}" title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>`;
                    }
                }
            ],
            responsive: true,
            initComplete: function(){
                try { this.api().ajax.reload(null, false); } catch(e) { /* noop */ }
            }
        });

        console.debug('Usuarios DT inicializada v=20251030-3');
        return true;
    }

    // Exponer para llamados externos
    window.forceInitUsuarios = initTablaUsuarios;

    // Intento inmediato
    if (!initTablaUsuarios()) {
        // Fallback: observar hasta que aparezca la tabla en el DOM
        const obs = new MutationObserver(function() {
            if (initTablaUsuarios()) { obs.disconnect(); }
        });
        obs.observe(document.body, { childList: true, subtree: true });

        // Reintentos temporizados (por si DataTables tarda en cargar)
        let intentos = 0; const t = setInterval(function(){
            if (initTablaUsuarios()) { clearInterval(t); }
            if (++intentos >= 10) clearInterval(t);
        }, 700);
    }

    // Helper: mostrar/ocultar selector de zona según tipo
    function updateZonaVisibility() {
        const $form = $('#usuarioForm');
        const $tipo = $form.find('#tipo');
        const tipo = $tipo.val();
        const $grp = $form.find('#grupoZona');
        const $zona = $form.find('#zona');
        if ($grp.length === 0) return;
        if (tipo === 'empleado') {
            $grp.removeClass('d-none');
            $zona.prop('required', true);
        } else {
            $grp.addClass('d-none');
            $zona.prop('required', false).val('');
        }
    }
    // Cambio del select tipo dentro del formulario del modal
    $(document).on('change', '#usuarioForm #tipo', updateZonaVisibility);
    // Al mostrar el modal, recalcular visibilidad por si el DOM fue regenerado
    $('#usuarioModal').on('shown.bs.modal', function(){
        updateZonaVisibility();
    });

    // Mostrar modal para crear/editar usuario
    $('#btnNuevoUsuario').off('click').on('click', function() {
        const form = document.getElementById('usuarioForm');
        form.reset();
        // Limpiar ID residual para evitar que dispare 'actualizar'
        const hid = document.getElementById('usuario_id');
        if (hid) hid.value = '';
        document.getElementById('usuarioModalLabel').textContent = 'Nuevo Usuario';
        form.setAttribute('data-action', 'crear');
        
        // Mostrar el modal
        // Limpiar explícito para evitar autocompletado/states previos
        $('#usuario').val('');
        $('#nombre').val('');
        $('#email').val('');
        $('#clave').val('');
        $('#activo').prop('checked', true);
        // Tipo por defecto: empleado (para que aparezca zona si hace falta) o vacío si prefieres elegir
        $('#usuarioForm #tipo').val('empleado');
        $('#usuarioForm #zona').val('');
        updateZonaVisibility();
        if (usuarioModal) usuarioModal.show();
    });

    // Editar usuario
    $(document).on('click', '.btn-editar', function() {
        const id = $(this).data('id');
        
        // Prevenir edición del administrador (ID 1)
        if (id == 1) {
            mostrarAlerta('warning', 'No se puede editar el usuario administrador principal');
            return;
        }
        
        fetch(`/HERMES.EXPRESS/php/usuarios.php?action=obtener&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    mostrarAlerta('danger', data.error);
                    return;
                }
                
                // Si por alguna razón llegamos aquí con el admin, prevenir la edición
                if (data.id == 1) {
                    mostrarAlerta('warning', 'No se puede editar el usuario administrador principal');
                    return;
                }
                
                $('#usuario_id').val(data.id);
                $('#usuario').val(data.usuario);
                $('#nombre').val(data.nombre);
                $('#email').val(data.email);
                $('#usuarioForm #tipo').val(data.tipo);
                $('#usuarioForm #zona').val(data.zona || '');
                $('#activo').prop('checked', data.activo == 1);
                
                // Mostrar campo de contraseña vacío
                $('#clave').val('').attr('placeholder', 'Dejar en blanco para no cambiar');
                
                $('#usuarioModalLabel').text('Editar Usuario');
                $('#usuarioForm').attr('data-action', 'actualizar');
                $('#usuarioModal').modal('show');
                // Actualizar visibilidad de zona según tipo cargado
                updateZonaVisibility();
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarAlerta('danger', 'Error al cargar los datos del usuario');
            });
    });

    // Eliminar usuario
    $(document).on('click', '.btn-eliminar', function(e) {
        const id = $(this).data('id');
        
        // Prevenir eliminación del administrador (ID 1)
        if (id == 1) {
            e.preventDefault();
            e.stopPropagation();
            mostrarAlerta('warning', 'No se puede eliminar el usuario administrador principal');
            return false;
        }
        
        if (confirm('¿Está seguro de eliminar este usuario?')) {
            fetch(`/HERMES.EXPRESS/php/usuarios.php?action=eliminar&id=${id}`, {
                method: 'DELETE'
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    mostrarAlerta('danger', data.error);
                } else {
                    let msg = 'Usuario eliminado correctamente';
                    if (data.desasignados) {
                        const a = Number(data.desasignados.paquetes || 0);
                        const b = Number(data.desasignados.paquetes_json || 0);
                        const total = a + b;
                        msg += ` — Paquetes desasignados: ${total}` + (total ? ` (paquetes: ${a}, paquetes_json: ${b})` : '');
                    }
                    mostrarAlerta('success', msg);
                    try { window.usuariosDT && window.usuariosDT.ajax.reload(null, false); } catch(_){ }
                    // Refrescar vistas de asignación
                    try { if (typeof cargarPaquetesSinAsignar === 'function') cargarPaquetesSinAsignar(); } catch(_){ }
                    try { if (typeof cargarResumenAsignados === 'function') cargarResumenAsignados(); } catch(_){ }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarAlerta('danger', 'Error al eliminar el usuario');
            });
        }
        
        return false;
    });

    // Enviar formulario (asegurar único binding y evitar dobles envíos)
    let enviandoUsuario = false;
    $('#usuarioForm').off('submit').on('submit', function(e) {
        e.preventDefault();
        if (enviandoUsuario) return false;
        enviandoUsuario = true;
        const $submitBtn = $('#usuarioForm button[type="submit"], #usuarioForm .btn-guardar');
        const prevText = $submitBtn.text();
        $submitBtn.prop('disabled', true).text('Guardando...');
        
        const formEl = document.getElementById('usuarioForm');
        const id = (document.getElementById('usuario_id') || {}).value || '';
        
        // Prevenir modificación del administrador (ID 1)
        if (id == 1) {
            mostrarAlerta('warning', 'No se puede modificar el usuario administrador principal');
            $('#usuarioModal').modal('hide');
            return false;
        }
        
        // Validar campos requeridos
        const usuario = (formEl.querySelector('#usuario')?.value || '').trim();
        const nombre  = (formEl.querySelector('#nombre')?.value  || '').trim();
        const email   = (formEl.querySelector('#email')?.value   || '').trim();
        const clave   = (formEl.querySelector('#clave')?.value   || '');
        
        if (!usuario || !nombre || !email) {
            mostrarAlerta('warning', 'Por favor complete todos los campos requeridos');
            enviandoUsuario = false; $submitBtn.prop('disabled', false).text(prevText || 'Guardar');
            return false;
        }
        
        // Si es un nuevo usuario, validar la contraseña
        if (!id && !clave) {
            mostrarAlerta('warning', 'La contraseña es obligatoria para nuevos usuarios');
            enviandoUsuario = false; $submitBtn.prop('disabled', false).text(prevText || 'Guardar');
            return false;
        }
        
        const tipoSel = (formEl.querySelector('#tipo')?.value || '').trim();
        const zonaSel = (formEl.querySelector('#zona')?.value || '').trim();
        if (tipoSel === 'empleado' && !zonaSel) {
            mostrarAlerta('warning', 'Seleccione la zona para empleados');
            enviandoUsuario = false; $submitBtn.prop('disabled', false).text(prevText || 'Guardar');
            return false;
        }

        const formData = {
            usuario: usuario,
            nombre: nombre,
            email: email,
            tipo: tipoSel,
            activo: (formEl.querySelector('#activo')?.checked ? 1 : 0)
        };
        if (tipoSel === 'empleado') {
            formData.zona = zonaSel;
        }
        
        // Solo incluir la contraseña si se proporcionó
        if (clave) {
            if (clave.length < 6) {
                mostrarAlerta('warning', 'La contraseña debe tener al menos 6 caracteres');
                enviandoUsuario = false; $submitBtn.prop('disabled', false).text(prevText || 'Guardar');
                return false;
            }
            formData.clave = clave;
        }
        
        // Determinar la acción (usar data-action como fuente de verdad)
        const actionAttr = (formEl.getAttribute('data-action') || '').toLowerCase();
        const action = actionAttr === 'crear' ? 'crear' : (actionAttr === 'actualizar' ? 'actualizar' : (id ? 'actualizar' : 'crear'));
        let url = `/HERMES.EXPRESS/php/usuarios.php?action=${action}`;
        const method = action === 'crear' ? 'POST' : 'PUT';
        
        if (action === 'actualizar') {
            const id = $('#usuario_id').val();
            if (!id) {
                mostrarAlerta('danger', 'ID de usuario no válido');
                return;
            }
            url += `&id=${id}`;
        }
        
        console.debug('Enviando usuario (urlencoded):', formData);
        const bodyParams = new URLSearchParams();
        Object.keys(formData).forEach(k => bodyParams.append(k, formData[k]));
        fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: bodyParams.toString()
        })
        .then(async (response) => {
            const raw = await response.text();
            let data;
            try { data = JSON.parse(raw); } catch(_) { data = { error: raw || 'Error desconocido' }; }
            if (!response.ok) {
                const msg = (data && (data.error || data.mensaje)) || `Error ${response.status}`;
                throw new Error(msg);
            }
            return data;
        })
        .then(data => {
            if (data.error) {
                mostrarAlerta('danger', data.error);
            } else {
                mostrarAlerta('success', data.success || 'Operación realizada correctamente');
                $('#usuarioModal').modal('hide');
                try { window.usuariosDT && window.usuariosDT.ajax.reload(null, false); } catch(_){ }
            }
        })
        .catch(error => {
            console.error('Error crear/actualizar usuario:', error.message || error);
            mostrarAlerta('danger', error.message || 'Error al procesar la solicitud');
        })
        .finally(() => {
            enviandoUsuario = false;
            $submitBtn.prop('disabled', false).text(prevText || 'Guardar');
        });
    });

    // Función para mostrar alertas
    function mostrarAlerta(tipo, mensaje) {
        const alerta = `
            <div class="alert alert-${tipo} alert-dismissible fade show" role="alert">
                ${mensaje}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        $('#alertContainer').html(alerta);
        
        // Ocultar la alerta después de 5 segundos
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);
    }
});
