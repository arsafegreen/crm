@echo off
setlocal
cd /d "%~dp0"
set "GATEWAY_ENV_PATH=%~dp0.env.lab03"
set "LOG_PATH=%~dp0gateway-lab03-run.log"
echo [Gateway] Inicializando WhatsApp Web (instancia Lab 03) em background. Log: %LOG_PATH%
start "wa-gateway-lab03" /min powershell -NoLogo -WindowStyle Hidden -Command "$env:GATEWAY_ENV_PATH='%GATEWAY_ENV_PATH%'; Set-Location '%~dp0'; npm start *> '%LOG_PATH%' 2>&1"
