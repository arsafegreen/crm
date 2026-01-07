@echo off
setlocal
cd /d "%~dp0"
set "GATEWAY_ENV_PATH=%~dp0.env.wpp01"
set "LOG_PATH=%~dp0gateway-wpp01-run.log"
echo [Gateway] Inicializando WhatsApp Web (instancia WPP01) em background. Log: %LOG_PATH%
start "wa-gateway-wpp01" /min powershell -NoLogo -WindowStyle Hidden -Command "Set-Location '%~dp0'; $env:GATEWAY_ENV_PATH='%~dp0.env.wpp01'; npm start *> '%~dp0gateway-wpp01-run.log' 2>&1"
