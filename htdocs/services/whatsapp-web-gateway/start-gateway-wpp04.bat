@echo off
setlocal
cd /d "%~dp0"
set "GATEWAY_ENV_PATH=%~dp0.env.wpp04"
set "LOG_PATH=%~dp0gateway-wpp04-run.log"
echo [Gateway] Inicializando WhatsApp Web (instancia WPP04) em background. Log: %LOG_PATH%
start "wa-gateway-wpp04" /min powershell -NoLogo -WindowStyle Hidden -Command "Set-Location '%~dp0'; $env:GATEWAY_ENV_PATH='%~dp0.env.wpp04'; npm start *> '%~dp0gateway-wpp04-run.log' 2>&1"
