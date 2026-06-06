@echo off
echo Deteniendo servidor en puerto 8080...
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr /R ":8080 "') do (
    taskkill /F /PID %%a >nul 2>&1
)
echo Servidor detenido.
timeout /t 2 /nobreak >nul
