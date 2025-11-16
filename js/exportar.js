// Función para exportar a PDF
function exportarAPDF(seccion) {
    try {
        // Obtener la tabla correspondiente
        let tabla;
        let nombreArchivo;
        
        switch(seccion) {
            case 'paquetes':
                tabla = document.getElementById('tablaPaquetes');
                nombreArchivo = 'reporte_paquetes';
                break;
            case 'vehiculos':
                tabla = document.getElementById('tablaVehiculos');
                nombreArchivo = 'reporte_vehiculos';
                break;
            case 'rutas':
                tabla = document.getElementById('tablaRutas');
                nombreArchivo = 'reporte_rutas';
                break;
            case 'usuarios':
                tabla = document.getElementById('usuariosTable');
                nombreArchivo = 'reporte_usuarios';
                break;
            case 'pagos':
                tabla = document.getElementById('tablaPagos');
                nombreArchivo = 'reporte_pagos';
                break;
            default:
                mostrarAlerta('error', `No se encontró la tabla para la sección: ${seccion}`);
                return;
        }

        if (!tabla) {
            mostrarAlerta('error', `No se pudo encontrar la tabla para exportar (${seccion})`);
            return;
        }

        // Configuración de jsPDF
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        
        // Título del documento
        const titulo = `Reporte de ${seccion.charAt(0).toUpperCase() + seccion.slice(1)}`;
        const fecha = new Date().toLocaleDateString('es-ES');
        
        doc.setFontSize(18);
        doc.text(titulo, 14, 22);
        doc.setFontSize(11);
        doc.setTextColor(100);
        doc.text(`Generado el: ${fecha}`, 14, 30);
        
        // Agregar la tabla al PDF
        doc.autoTable({
            html: '#' + tabla.id,
            startY: 40,
            theme: 'grid',
            headStyles: {
                fillColor: [41, 128, 185],
                textColor: 255,
                fontStyle: 'bold'
            },
            alternateRowStyles: {
                fillColor: [245, 245, 245]
            },
            styles: {
                cellPadding: 3,
                fontSize: 10,
                valign: 'middle'
            },
            columnStyles: {
                0: { cellWidth: 'auto' },
                1: { cellWidth: 'auto' },
                2: { cellWidth: 'auto' },
                3: { cellWidth: 'auto' },
                4: { cellWidth: 'auto' }
            },
            margin: { top: 40 },
            didDrawPage: function(data) {
                // Footer con número de página
                const pageSize = doc.internal.pageSize;
                const pageHeight = pageSize.height ? pageSize.height : pageSize.getHeight();
                doc.text(`Página ${data.pageNumber}`, data.settings.margin.left, pageHeight - 10);
            }
        });
        
        // Guardar el PDF
        doc.save(`${nombreArchivo}_${new Date().toISOString().split('T')[0]}.pdf`);
        
    } catch (error) {
        console.error('Error al exportar a PDF:', error);
        mostrarAlerta('error', 'Ocurrió un error al generar el PDF');
    }
}

// Función para exportar a Excel
function exportarAExcel(seccion) {
    try {
        // Obtener la tabla correspondiente
        let tabla;
        let nombreArchivo;
        
        switch(seccion) {
            case 'paquetes':
                tabla = document.getElementById('tablaPaquetes');
                nombreArchivo = 'reporte_paquetes';
                break;
            case 'vehiculos':
                tabla = document.getElementById('tablaVehiculos');
                nombreArchivo = 'reporte_vehiculos';
                break;
            case 'rutas':
                tabla = document.getElementById('tablaRutas');
                nombreArchivo = 'reporte_rutas';
                break;
            case 'usuarios':
                tabla = document.getElementById('usuariosTable');
                nombreArchivo = 'reporte_usuarios';
                break;
            case 'pagos':
                tabla = document.getElementById('tablaPagos');
                nombreArchivo = 'reporte_pagos';
                break;
            default:
                mostrarAlerta('error', `No se encontró la tabla para la sección: ${seccion}`);
                return;
        }

        if (!tabla) {
            mostrarAlerta('error', `No se pudo encontrar la tabla para exportar (${seccion})`);
            return;
        }
        
        // Verificar que la biblioteca XLSX esté disponible
        if (typeof XLSX === 'undefined') {
            mostrarAlerta('error', 'No se pudo cargar la biblioteca de exportación a Excel');
            return;
        }
        
        // Crear un nuevo libro de Excel
        const wb = XLSX.utils.book_new();
        
        // Convertir la tabla a una hoja de cálculo
        const ws = XLSX.utils.table_to_sheet(tabla);
        
        // Ajustar el ancho de las columnas
        const colWidths = [];
        const range = XLSX.utils.decode_range(ws['!ref']);
        
        for (let C = range.s.c; C <= range.e.c; ++C) {
            let maxLength = 0;
            for (let R = range.s.r; R <= range.e.r; ++R) {
                const cell = ws[XLSX.utils.encode_cell({r: R, c: C})];
                if (cell && cell.v) {
                    const cellLength = cell.v.toString().length;
                    if (cellLength > maxLength) {
                        maxLength = cellLength;
                    }
                }
            }
            // Ajustar el ancho de la columna (mínimo 10, máximo 50)
            colWidths.push({ wch: Math.min(Math.max(maxLength + 2, 10), 50) });
        }
        
        ws['!cols'] = colWidths;
        
        // Agregar la hoja al libro
        XLSX.utils.book_append_sheet(wb, ws, 'Datos');
        
        // Generar el archivo Excel
        XLSX.writeFile(wb, `${nombreArchivo}_${new Date().toISOString().split('T')[0]}.xlsx`);
        
    } catch (error) {
        console.error('Error al exportar a Excel:', error);
        mostrarAlerta('error', 'Ocurrió un error al generar el archivo Excel');
    }
}

// Función auxiliar para mostrar alertas
function mostrarAlerta(tipo, mensaje) {
    // Usar SweetAlert2 si está disponible, de lo contrario usar alert nativo
    if (typeof Swal !== 'undefined') {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });
        
        Toast.fire({
            icon: tipo,
            title: mensaje
        });
    } else {
        alert(`${tipo.toUpperCase()}: ${mensaje}`);
    }
}

// Función para exportar pagos
function exportarPagos() {
    // Obtener la tabla de pagos (usando el ID correcto del thead de la tabla)
    const tabla = document.querySelector('.pagos-empleados table');
    if (!tabla) {
        console.error('No se encontró la tabla de pagos');
        mostrarMensaje('error', 'No se pudo encontrar la tabla de pagos para exportar');
        return;
    }
    
    try {
        // Usar SheetJS para exportar a Excel
        const wb = XLSX.utils.table_to_book(tabla, {sheet: 'Pagos'});
        XLSX.writeFile(wb, `reporte_pagos_${new Date().toISOString().split('T')[0]}.xlsx`);
        mostrarMensaje('éxito', 'Los pagos se exportaron correctamente');
    } catch (error) {
        console.error('Error al exportar pagos:', error);
        mostrarMensaje('error', 'Ocurrió un error al exportar los pagos');
    }
}

// Asegurarse de que las funciones estén disponibles globalmente
if (typeof window !== 'undefined') {
    window.exportarAPDF = exportarAPDF;
    window.exportarAExcel = exportarAExcel;
    window.exportarPagos = exportarPagos;
}
