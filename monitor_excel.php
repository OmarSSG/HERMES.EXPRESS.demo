<?php
/**
 * Script para monitorear la carpeta de descargas y procesar archivos Excel
 * Debe ejecutarse periódicamente mediante una tarea programada (cron job)
 */

require_once __DIR__ . '/php/config.php';
require_once __DIR__ . '/php/whatsapp_notifier.php';

class ExcelMonitor {
    private $pdo;
    private $downloadsDir;
    private $processedDir;
    private $notifier;
    private $logFile;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->downloadsDir = realpath(__DIR__ . '/downloads');
        $this->processedDir = realpath(__DIR__ . '/processed');
        $this->logFile = __DIR__ . '/logs/excel_monitor.log';
        $this->notifier = new WhatsAppNotifier($pdo);
        
        // Crear directorios si no existen
        $this->ensureDirectories();
    }
    
    /**
     * Asegura que los directorios necesarios existan
     */
    private function ensureDirectories() {
        $dirs = [
            $this->downloadsDir,
            $this->processedDir,
            dirname($this->logFile)
        ];
        
        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Escribe un mensaje en el log
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;
    }
    
    /**
     * Busca archivos Excel en la carpeta de descargas
     */
    private function findExcelFiles() {
        $files = [];
        $dir = new DirectoryIterator($this->downloadsDir);
        
        foreach ($dir as $file) {
            if ($file->isFile() && preg_match('/\.xlsx?$/i', $file->getFilename())) {
                $files[] = [
                    'path' => $file->getPathname(),
                    'filename' => $file->getFilename(),
                    'modified' => $file->getMTime()
                ];
            }
        }
        
        // Ordenar por fecha de modificación (más reciente primero)
        usort($files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        return $files;
    }
    
    /**
     * Procesa un archivo Excel
     */
    private function processExcelFile($filePath) {
        $this->log("Procesando archivo: " . basename($filePath));
        
        try {
            // Cargar el archivo Excel
            require_once __DIR__ . '/vendor/autoload.php';
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (empty($rows) || count($rows) < 2) {
                $this->log("Archivo vacío o sin datos: " . basename($filePath));
                return false;
            }
            
            // Obtener encabezados (primera fila)
            $headers = array_map('trim', array_shift($rows));
            
            // Buscar índices de columnas necesarias
            $idIndex = array_search('codigo', array_map('strtolower', $headers));
            $phoneIndex = array_search('telefono', array_map('strtolower', $headers));
            $nameIndex = array_search('consignado', array_map('strtolower', $headers));
            $statusIndex = array_search('estado', array_map('strtolower', $headers));
            
            if ($idIndex === false || $phoneIndex === false || $nameIndex === false || $statusIndex === false) {
                throw new Exception("El archivo no contiene todas las columnas requeridas (codigo, telefono, consignado, estado)");
            }
            
            $processed = 0;
            $newPackages = 0;
            $statusUpdates = 0;
            $errors = 0;
            
            // Procesar filas
            foreach ($rows as $row) {
                if (empty($row[$idIndex])) {
                    continue; // Saltar filas vacías
                }
                
                $packageData = [
                    'id_paquete' => trim($row[$idIndex]),
                    'telefono' => trim($row[$phoneIndex]),
                    'nombre_cliente' => trim($row[$nameIndex]),
                    'estado' => strtolower(trim($row[$statusIndex]))
                ];
                
                // Depuración: Mostrar datos del paquete
                $this->log(\"Procesando paquete: \" . print_r($packageData, true));
                
                try {
                    // Verificar si el paquete es nuevo o actualizado
                    $stmt = $this->pdo->prepare(
                        "SELECT status FROM whatsapp_notifications 
                         WHERE package_id = ? 
                         ORDER BY sent_at DESC 
                         LIMIT 1"
                    );
                    $stmt->execute([$packageData['id_paquete']]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$existing) {
                        // Paquete nuevo
                        $this->notifier->processNewPackage($packageData);
                        $newPackages++;
                    } else {
                        // Verificar si el estado ha cambiado
                        if ($existing['status'] !== $packageData['estado']) {
                            $this->notifier->updatePackageStatus(
                                $packageData['id_paquete'], 
                                $packageData['estado']
                            );
                            $statusUpdates++;
                        }
                    }
                    
                    $processed++;
                    
                } catch (Exception $e) {
                    $this->log("Error al procesar paquete {$packageData['id_paquete']}: " . $e->getMessage());
                    $errors++;
                }
            }
            
            $this->log("Procesamiento completado. Paquetes: {$processed}, Nuevos: {$newPackages}, Actualizaciones: {$statusUpdates}, Errores: {$errors}");
            
            // Mover el archivo a la carpeta de procesados
            $this->moveToProcessed($filePath);
            
            return true;
            
        } catch (Exception $e) {
            $this->log("Error al procesar el archivo " . basename($filePath) . ": " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mueve un archivo procesado a la carpeta de procesados
     */
    private function moveToProcessed($filePath) {
        $filename = basename($filePath);
        $targetPath = $this->processedDir . '/' . date('Y-m-d_His_') . $filename;
        
        if (rename($filePath, $targetPath)) {
            $this->log("Archivo movido a: " . $targetPath);
            return true;
        } else {
            $this->log("No se pudo mover el archivo: " . $filePath);
            return false;
        }
    }
    
    /**
     * Reintenta notificaciones fallidas
     */
    public function retryFailedNotifications() {
        $this->log("Reintentando notificaciones fallidas...");
        $results = $this->notifier->retryFailedNotifications();
        
        if (empty($results)) {
            $this->log("No hay notificaciones fallidas para reintentar.");
            return;
        }
        
        $success = 0;
        $failed = 0;
        
        foreach ($results as $result) {
            if ($result['success']) {
                $success++;
            } else {
                $failed++;
                $this->log("Error al reintentar notificación ID {$result['id']}: {$result['message']}");
            }
        }
        
        $this->log("Reintento completado. Éxitos: {$success}, Fallos: {$failed}");
    }
    
    /**
     * Genera un informe diario
     */
    public function generateDailyReport() {
        $this->log("Generando informe diario...");
        $report = $this->notifier->generateDailyReport();
        
        // Guardar informe en archivo
        $reportFile = __DIR__ . '/reports/daily_report_' . date('Y-m-d') . '.json';
        if (!file_exists(dirname($reportFile))) {
            mkdir(dirname($reportFile), 0755, true);
        }
        
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        $this->log("Informe diario guardado en: " . $reportFile);
        
        // Aquí podrías agregar el envío del informe por correo electrónico
        
        return $report;
    }
    
    /**
     * Ejecuta el monitoreo
     */
    public function run() {
        $this->log("Iniciando monitoreo de archivos Excel...");
        
        // 1. Buscar archivos Excel en la carpeta de descargas
        $files = $this->findExcelFiles();
        
        if (empty($files)) {
            $this->log("No se encontraron archivos Excel para procesar.");
        } else {
            // 2. Procesar cada archivo
            foreach ($files as $file) {
                $this->processExcelFile($file['path']);
            }
        }
        
        // 3. Reintentar notificaciones fallidas
        $this->retryFailedNotifications();
        
        // 4. Generar informe diario (solo una vez al día, por ejemplo a las 6 PM)
        if (date('H') >= 18) { // 6 PM
            $this->generateDailyReport();
        }
        
        $this->log("Monitoreo completado.");
    }
}

// Ejecutar el monitor
try {
    $pdo = new PDO("mysql:host=localhost;dbname=hermes_express", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $monitor = new ExcelMonitor($pdo);
    $monitor->run();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (isset($monitor)) {
        $monitor->log("Error: " . $e->getMessage());
    }
    exit(1);
}
?>
