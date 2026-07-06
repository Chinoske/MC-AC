@echo off
setlocal

chcp 65001 >nul
title Migrador AC - PHP - 127.0.0.1:8080

set "APP_DIR=%~dp0"
if "%APP_DIR:~-1%"=="\" set "APP_DIR=%APP_DIR:~0,-1%"
set "PHP_DIR=%APP_DIR%\php"
set "PHP_EXE=%PHP_DIR%\php.exe"
set "ROUTER=%APP_DIR%\router.php"
set "HOST=127.0.0.1"
set "PORT=8080"
set "URL=http://%HOST%:%PORT%/"

cd /d "%APP_DIR%"

echo ============================================
echo   Migrador de Personajes - AzerothCore
echo   %URL%
echo ============================================
echo.

if not exist "%PHP_EXE%" (
    echo ERROR: No se encontro PHP en:
    echo %PHP_EXE%
    echo.
    pause
    exit /b 1
)

if not exist "%ROUTER%" (
    echo ERROR: No se encontro router.php en:
    echo %ROUTER%
    echo.
    pause
    exit /b 1
)

echo Cerrando cualquier proceso previo en el puerto %PORT%...
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr /R /C:":%PORT% .*LISTENING"') do (
    if not "%%a"=="0" taskkill /F /PID %%a >nul 2>&1
)

echo Abriendo navegador...
start "" powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 2; Start-Process '%URL%'"

echo Servidor iniciado. Cierra esta ventana para detenerlo.
echo.
cd /d "%PHP_DIR%"
"%PHP_EXE%" -S %HOST%:%PORT% -t "%APP_DIR%" "%ROUTER%"

set "EXITCODE=%ERRORLEVEL%"
echo.
echo El servidor PHP se detuvo con codigo %EXITCODE%.
pause
exit /b %EXITCODE%
