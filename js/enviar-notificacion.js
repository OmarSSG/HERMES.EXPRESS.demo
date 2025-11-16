/**
 * Maneja el envío de notificaciones por WhatsApp
 * 
 * Uso:
 * 1. Incluir este script en tu página
 * 2. Agregar botón con clase 'btn-enviar-whatsapp' y data-atributos:
 *    <button class="btn-enviar-whatsapp" 
 *            data-id="ID_PAQUETE" 
 *            data-telefono="NUMERO"
 *            data-cliente="NOMBRE_CLIENTE"
 *            data-estado="ESTADO_ACTUAL">
 *        Enviar WhatsApp
 *    </button>
 */

document.addEventListener('DOMContentLoaded', function() {
    // Delegación de eventos para manejar clics en botones de envío
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-enviar-whatsapp');
        if (!btn) return;
        
        e.preventDefault();
        
        // Obtener datos del botón
        const idPaquete = btn.dataset.id;
        const telefono = btn.dataset.telefono;
        const cliente = btn.dataset.cliente;
        const estado = btn.dataset.estado;
        
        // Mostrar modal o diálogo para confirmar/enviar
        mostrarModalNotificacion({
            idPaquete,
            telefono,
            cliente,
            estado,
            onEnviar: enviarNotificacion
        });
    });
    
    // Función para mostrar el modal de notificación
    function mostrarModalNotificacion({ idPaquete, telefono, cliente, estado, onEnviar }) {
        // Crear el modal si no existe
        let modal = document.getElementById('modal-notificacion-whatsapp');
        
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'modal-notificacion-whatsapp';
            modal.className = 'modal';
            modal.style.display = 'none';
            modal.style.position = 'fixed';
            modal.style.zIndex = '1000';
            modal.style.left = '0';
            modal.style.top = '0';
            modal.style.width = '100%';
            modal.style.height = '100%';
            modal.style.backgroundColor = 'rgba(0,0,0,0.5)';
            modal.style.overflow = 'auto';
            
            modal.innerHTML = `
                <div class="modal-content" style="background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 8px;">
                    <h2>Enviar notificación por WhatsApp</h2>
                    <div class="form-group">
                        <label>Para:</label>
                        <p><strong id="modal-cliente"></strong> (<span id="modal-telefono"></span>)</p>
                    </div>
                    <div class="form-group">
                        <label>ID de Paquete:</label>
                        <p id="modal-id-paquete"></p>
                    </div>
                    <div class="form-group">
                        <label for="mensaje-whatsapp">Mensaje:</label>
                        <textarea id="mensaje-whatsapp" rows="8" style="width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                    </div>
                    <div class="modal-buttons" style="margin-top: 20px; text-align: right;">
                        <button id="btn-cancelar" style="padding: 8px 16px; margin-right: 10px; background: #f1f1f1; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">Cancelar</button>
                        <button id="btn-enviar" style="padding: 8px 16px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            <i class="fas fa-paper-plane"></i> Enviar
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Manejar clic en cancelar
            modal.querySelector('#btn-cancelar').addEventListener('click', function() {
                modal.style.display = 'none';
            });
            
            // Manejar clic en enviar
            modal.querySelector('#btn-enviar').addEventListener('click', function() {
                const mensaje = document.getElementById('mensaje-whatsapp').value;
                onEnviar({
                    idPaquete,
                    telefono,
                    cliente,
                    estado,
                    mensajePersonalizado: mensaje
                }, modal);
            });
        }
        
        // Actualizar contenido del modal
        document.getElementById('modal-cliente').textContent = cliente;
        document.getElementById('modal-telefono').textContent = telefono;
        document.getElementById('modal-id-paquete').textContent = idPaquete;
        
        // Generar mensaje predeterminado
        const mensajePredeterminado = `*HERMES EXPRESS - ACTUALIZACIÓN DE PAQUETE*\n\n` +
        `Hola ${cliente},\n` +
        `El estado de tu paquete ha sido actualizado.\n\n` +
        `📦 *ID de Paquete:* ${idPaquete}\n` +
        `🔄 *Nuevo Estado:* ${estado}\n\n` +
        `Gracias por confiar en nosotros.\n` +
        `Para más información, contáctanos.\n\n` +
        `_Este es un mensaje automático, por favor no responder._`;
        
        document.getElementById('mensaje-whatsapp').value = mensajePredeterminado;
        
        // Mostrar el modal
        modal.style.display = 'block';
    }
    
    // Función para enviar la notificación al servidor
    function enviarNotificacion(datos, modal) {
        const btnEnviar = document.querySelector('#btn-enviar');
        const btnCancelar = document.querySelector('#btn-cancelar');
        const originalBtnText = btnEnviar.innerHTML;
        
        // Deshabilitar botones
        btnEnviar.disabled = true;
        btnCancelar.disabled = true;
        btnEnviar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
        
        // Enviar datos al servidor
        fetch('../php/enviar_notificacion.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                id_paquete: datos.idPaquete,
                telefono: datos.telefono,
                cliente: datos.cliente,
                estado: datos.estado,
                mensaje_personalizado: datos.mensajePersonalizado
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                // Mostrar mensaje de éxito
                alert('✅ ' + (data.mensaje || 'Notificación enviada correctamente'));
                if (modal) modal.style.display = 'none';
                
                // Opcional: Actualizar la interfaz de usuario
                const event = new CustomEvent('notificacionEnviada', { 
                    detail: { 
                        idPaquete: datos.idPaquete,
                        telefono: datos.telefono,
                        mensaje: data.mensaje,
                        messageId: data.message_id
                    } 
                });
                document.dispatchEvent(event);
            } else {
                throw new Error(data.mensaje || 'Error al enviar la notificación');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('❌ ' + (error.message || 'Error al enviar la notificación'));
        })
        .finally(() => {
            // Restaurar botones
            btnEnviar.disabled = false;
            btnCancelar.disabled = false;
            btnEnviar.innerHTML = originalBtnText;
        });
    }
});
