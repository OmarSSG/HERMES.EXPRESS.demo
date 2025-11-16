<?php
/**
 * ver_archivo.php
 * 
 * Este archivo muestra el contenido de un archivo Excel en formato de tabla.
 * Se accede a través de la URL: ver_archivo.php?archivo=nombre_archivo.xlsx
 */

// Iniciar sesión para verificar permisos
session_start();

// Verificar si el usuario está autenticado y tiene permisos de administrador o asistente
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'] ?? '', ['admin', 'asistente'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('HTTP/1.1 403 Forbidden');
    echo 'Acceso no autorizado';
    exit;
}

// Obtener la ruta del archivo desde el parámetro GET
$archivo = $_GET['archivo'] ?? '';

// Validar que se haya proporcionado un archivo
if (empty($archivo)) {
    header('Content-Type: text/plain; charset=utf-8');
    header('HTTP/1.1 400 Bad Request');
    echo 'No se ha especificado ningún archivo';
    exit;
}

// Configurar la ruta base del proyecto
$rutaBase = __DIR__; // Directorio actual del script (php)
$rutaDescargas = $rutaBase . '/downloads'; // Carpeta downloads dentro del proyecto

// Validar que el archivo sea solo el nombre del archivo (sin rutas)
if (strpos($archivo, '/') !== false || strpos($archivo, '\\') !== false) {
    header('Content-Type: text/plain; charset=utf-8');
    header('HTTP/1.1 400 Bad Request');
    echo 'Error: Nombre de archivo no válido';
    exit;
}

// Construir la ruta completa al archivo de manera segura
$rutaArchivo = rtrim($rutaDescargas, '/\\') . DIRECTORY_SEPARATOR . $archivo;
$rutaArchivo = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rutaArchivo);

// Obtener la ruta canónica (resuelve .. y .)
$rutaCanonica = realpath($rutaArchivo);
if ($rutaCanonica === false) {
    header('Content-Type: text/plain; charset=utf-8');
    header('HTTP/1.1 404 Not Found');
    echo 'Archivo no encontrado: ' . htmlspecialchars($archivo, ENT_QUOTES, 'UTF-8') . '\n';
    echo 'Ruta intentada: ' . htmlspecialchars($rutaArchivo, ENT_QUOTES, 'UTF-8') . '\n';
    echo 'Directorio actual: ' . __DIR__ . '\n';
    exit;
}

$rutaArchivo = $rutaCanonica;

// Verificar que el archivo exista y sea legible
if (!file_exists($rutaArchivo) || !is_readable($rutaArchivo)) {
    header('Content-Type: text/plain; charset=utf-8');
    header('HTTP/1.1 404 Not Found');
    echo 'Archivo no encontrado o no se puede leer: ' . htmlspecialchars($archivo, ENT_QUOTES, 'UTF-8') . '\n';
    echo 'Ruta intentada: ' . htmlspecialchars($rutaArchivo, ENT_QUOTES, 'UTF-8') . '\n';
    echo 'Directorio actual: ' . __DIR__ . '\n';
    exit;
}

// Validar que el archivo tenga una extensión permitida
$extension = strtolower(pathinfo($rutaArchivo, PATHINFO_EXTENSION));
if (!in_array($extension, ['xls', 'xlsx', 'csv'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('HTTP/1.1 400 Bad Request');
    echo 'Tipo de archivo no soportado. Se admiten solo archivos Excel (.xls, .xlsx) y CSV.';
    exit;
}

// Cargar la biblioteca PHPSpreadsheet
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// Función para escapar datos HTML
function escape($data) {
    if (is_array($data)) {
        return array_map('htmlspecialchars', $data, array_fill(0, count($data), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5));
    }
    return htmlspecialchars((string)$data, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

// Función para formatear celdas
function formatearCelda($cell) {
    if ($cell === null || $cell === '') {
        return '';
    }
    
    // Si es un objeto DateTime, formatear como fecha
    if (is_object($cell) && method_exists($cell, 'format')) {
        return $cell->format('Y-m-d H:i:s');
    }
    
    // Si es un valor numérico, formatear con separadores de miles
    if (is_numeric($cell)) {
        return number_format((float)$cell, 2, '.', ',');
    }
    
    // Devolver el valor como texto
    return (string)$cell;
}

try {
    // Cargar el archivo Excel
    $spreadsheet = IOFactory::load($rutaArchivo);
    $worksheet = $spreadsheet->getActiveSheet();
    
    // Obtener todos los datos de la hoja
    $rows = $worksheet->toArray();
    
    // Si no hay filas, devolver un array vacío
    if (empty($rows)) {
        $rows = [];
    } else {
        // Asegurarse de que todas las filas tengan el mismo número de columnas
        $maxColumns = 0;
        foreach ($rows as $row) {
            $maxColumns = max($maxColumns, count($row));
        }
        
        // Rellenar filas con celdas vacías si es necesario
        foreach ($rows as &$row) {
            while (count($row) < $maxColumns) {
                $row[] = '';
            }
        }
    }
    
    // Obtener el nombre del archivo sin la ruta
    $nombreArchivo = basename($rutaArchivo);
    
    // Configurar la cabecera HTTP
    header('Content-Type: text/html; charset=utf-8');
    
    // Mostrar el contenido del archivo
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vista previa: <?php echo escape($nombreArchivo); ?> - HERMES EXPRESS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { padding: 20px; background-color: #f8f9fa; }
        .container { max-width: 100%; }
        .header { margin-bottom: 20px; }
        .table-responsive { 
            background: white; 
            border-radius: 8px; 
            box-shadow: 0 0 10px rgba(0,0,0,0.1); 
            overflow-x: auto;
            max-width: 100%;
        }
        .table { 
            margin-bottom: 0; 
            width: 100%;
            border-collapse: collapse;
        }
        .table th { 
            background-color: #f8f9fa; 
            font-weight: 600; 
            white-space: nowrap; 
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table td { 
            vertical-align: middle; 
            white-space: nowrap;
        }
        .table-striped tbody tr:nth-of-type(odd) { 
            background-color: rgba(0,0,0,.02); 
        }
        .table-hover tbody tr:hover { 
            background-color: rgba(0,0,0,.05); 
        }
        .badge { 
            font-size: 0.8em; 
        }
        .text-truncate-2 { 
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .back-button { 
            margin-bottom: 20px; 
        }
        .file-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .file-info-item {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
            <h1 class="h4 mb-3 mb-md-0">
                <i class="fas fa-file-excel text-success me-2"></i>
                Vista previa: <?php echo escape($nombreArchivo); ?>
            </h1>
            <div class="d-flex flex-column flex-sm-row gap-2">
                <a href="paneladmin.html" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Volver al panel
                </a>
                <a href="downloads/<?php echo urlencode($archivo); ?>" class="btn btn-primary" download>
                    <i class="fas fa-download me-1"></i> Descargar
                </a>
            </div>
        </div>
        
        <div class="file-info mb-4">
            <div class="row">
                <div class="col-md-4">
                    <div class="file-info-item">
                        <i class="fas fa-file me-2 text-muted"></i>
                        <span class="text-muted">Nombre:</span>
                        <span class="ms-2 fw-bold"><?php echo escape($nombreArchivo); ?></span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="file-info-item">
                        <i class="fas fa-hdd me-2 text-muted"></i>
                        <span class="text-muted">Tamaño:</span>
                        <span class="ms-2 fw-bold">
                            <?php 
                            $size = filesize($rutaArchivo);
                            if ($size < 1024) {
                                echo $size . ' bytes';
                            } elseif ($size < 1048576) {
                                echo number_format($size / 1024, 2) . ' KB';
                            } else {
                                echo number_format($size / 1048576, 2) . ' MB';
                            }
                            ?>
                        </span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="file-info-item">
                        <i class="far fa-calendar-alt me-2 text-muted"></i>
                        <span class="text-muted">Modificado:</span>
                        <span class="ms-2 fw-bold"><?php echo date('Y-m-d H:i:s', filemtime($rutaArchivo)); ?></span>
                    </div>
                </div>
                <div class="col-12 mt-2">
                    <div class="file-info-item">
                        <i class="fas fa-table me-2 text-muted"></i>
                        <span class="text-muted">Contenido:</span>
                        <span class="ms-2 fw-bold">
                            <?php 
                            $totalRows = count($rows);
                            $totalCols = $totalRows > 0 ? count($rows[0]) : 0;
                            echo ($totalRows - 1) . ' filas' . ($totalRows - 1 !== 1 ? 's' : '') . 
                                 ' y ' . $totalCols . ' columna' . ($totalCols !== 1 ? 's' : '') . 
                                 ' (excluyendo encabezados)';
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (count($rows) > 1): ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered">
                    <thead class="table-light">
                        <tr>
                            <?php foreach ($rows[0] as $index => $header): ?>
                                <th><?php echo !empty($header) ? escape($header) : 'Columna ' . ($index + 1); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 1; $i < count($rows); $i++): ?>
                            <tr>
                                <?php foreach ($rows[$i] as $cell): ?>
                                    <td class="text-truncate-2" title="<?php echo escape($cell); ?>">
                                        <?php echo escape(formatearCelda($cell)); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-4 text-center text-muted">
                <p>Mostrando <?php echo count($rows) - 1; ?> filas de datos</p>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                El archivo está vacío o no contiene datos.
            </div>
        <?php endif; ?>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    // Mostrar mensaje de error
    header('HTTP/1.1 500 Internal Server Error');
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Error al cargar el archivo</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card shadow">
                        <div class="card-header bg-danger text-white">
                            <h4 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i> Error al cargar el archivo</h4>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-danger">
                                <p class="mb-2"><strong>Error:</strong> ' . escape($e->getMessage()) . '</p>
                                <p class="mb-0">Por favor, verifica que el archivo no esté dañado y que sea un archivo Excel válido.</p>
                            </div>
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="paneladmin.html" class="btn btn-primary">
                                    <i class="fas fa-arrow-left me-1"></i> Volver al panel
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>';
}
?>
