@echo off
setlocal
cd /d "%~dp0"
set "GATEWAY_ENV_PATH=%~dp0.env.wpp03"
set "LOG_PATH=%~dp0gateway-wpp03-run.log"
echo [Gateway] Inicializando WhatsApp Web (instancia WPP03) em background. Log: %LOG_PATH%
start "wa-gateway-wpp03" /min powershell -NoLogo -WindowStyle Hidden -Command "Set-Location '%~dp0'; $env:GATEWAY_ENV_PATH='%~dp0.env.wpp03'; npm start *> '%~dp0gateway-wpp03-run.log' 2>&1"
