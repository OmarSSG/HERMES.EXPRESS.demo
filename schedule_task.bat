@echo off
REM Script para programar la tarea de monitoreo de Excel en Windows

REM Verificar si se está ejecutando como administrador
net session >nul 2>&1
if %errorLevel% == 0 (
    echo Ejecutando como administrador...
) else (
    echo Por favor, ejecuta este script como administrador.
    pause
    exit /b 1
)

REM Configuración
set "TASK_NAME=HermesExpress_ExcelMonitor"
set "SCRIPT_PATH=%~dp0monitor_excel.php"
set "PHP_PATH=C:\xampp\php\php.exe"
set "SCHEDULE=HOURLY"  
set "INTERVAL=1"  

REM Crear la tarea programada
schtasks /create /tn "%TASK_NAME%" ^
         /tr "\"%PHP_PATH%\" -f \"%SCRIPT_PATH%\"" ^
         /sc %SCHEDULE% ^
         /mo %INTERVAL% ^
         /ru "SYSTEM" ^
         /f

if %errorlevel% equ 0 (
    echo Tarea programada "%TASK_NAME%" creada exitosamente.
    echo Se ejecutará cada %INTERVAL% hora(s).
) else (
    echo Error al crear la tarea programada.
    pause
    exit /b 1
)

pause
