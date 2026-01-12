@echo off
setlocal
cd /d "%~dp0"
set "GATEWAY_ENV_PATH=%~dp0.env.wpp02"
set "LOG_PATH=%~dp0gateway-wpp02-run.log"
echo [Gateway] Inicializando WhatsApp Web (instancia WPP02) em background. Log: %LOG_PATH%
start "wa-gateway-wpp02" /min powershell -NoLogo -WindowStyle Hidden -Command "Set-Location '%~dp0'; $env:GATEWAY_ENV_PATH='%~dp0.env.wpp02'; npm start *> '%~dp0gateway-wpp02-run.log' 2>&1"
