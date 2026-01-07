@echo off
setlocal
cd /d "%~dp0"
set "LOG_PATH=%~dp0gateway-lab01-run.log"
echo [Gateway] Inicializando WhatsApp Web (lab01) em background. Log: %LOG_PATH%
start "wa-gateway-lab01" /min powershell -NoLogo -WindowStyle Hidden -Command "$env:GATEWAY_ENV_PATH='%~dp0.env'; Set-Location '%~dp0'; npm start *> '%LOG_PATH%' 2>&1"
