@echo off
title Migrador AC — PHP 8.5 — localhost:8080
cd /d "%~dp0"

echo ============================================
echo   Migrador de Personajes — AzerothCore
echo   http://localhost:8080
echo ============================================
echo.

:: Matar instancia previa en el puerto 8080
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr /R ":8080 "') do (
    taskkill /F /PID %%a >nul 2>&1
)

:: Abrir navegador tras 2 segundos (en paralelo)
start "" cmd /c "timeout /t 2 /nobreak >nul && start http://localhost:8080"

:: Correr PHP desde su carpeta para que extension_dir="ext" resuelva bien
echo Servidor iniciado. Cierra esta ventana para detenerlo.
echo.
cd /d "%~dp0php"
php.exe -S localhost:8080 -t "%~dp0"
