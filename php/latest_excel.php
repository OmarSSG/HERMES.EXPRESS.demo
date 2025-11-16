<?php
// php/latest_excel.php
// Devuelve el último archivo Excel generado en downloads/
header('Content-Type: application/json; charset=utf-8');

try {
    $root = realpath(__DIR__ . '/..');
    if ($root === false) {
        throw new Exception('No se pudo resolver la ruta del proyecto');
    }
    $downloads = $root . DIRECTORY_SEPARATOR . 'downloads';
    if (!is_dir($downloads)) {
        throw new Exception('No existe la carpeta downloads');
    }

    $pattern = $downloads . DIRECTORY_SEPARATOR . '*.xls*';
    $files = glob($pattern, GLOB_NOSORT);
    if (!$files) {
        echo json_encode(['exito' => false, 'mensaje' => 'No hay archivos Excel en downloads']);
        exit;
    }

    // Ordenar por fecha de modificación descendente
    usort($files, function($a, $b) { return filemtime($b) <=> filemtime($a); });
    $latest = $files[0];

    // Construir ruta relativa para servir por HTTP
    $rel = 'downloads/' . basename($latest);

    echo json_encode([
        'exito' => true,
        'nombre' => basename($latest),
        'ruta_relativa' => $rel,
        'mtime' => filemtime($latest)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
