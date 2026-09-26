@echo off
echo Deteniendo servidor en puerto 8080...
rem Solo el proceso que escucha, y nunca el PID 0: antes bastaba con tener
rem una conexion hacia cualquier :8080 para que taskkill se lo llevara.
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr /R /C:":8080 .*LISTENING"') do (
    if not "%%a"=="0" taskkill /F /PID %%a >nul 2>&1
)
echo Servidor detenido.
timeout /t 2 /nobreak >nul
