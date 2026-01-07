@echo off
setlocal
cd /d "%~dp0"
set "GATEWAY_ENV_PATH=%~dp0.env.lab02"
set "LOG_PATH=%~dp0gateway-lab02-run.log"
echo [Gateway] Inicializando WhatsApp Web (instancia Lab 02) em background. Log: %LOG_PATH%
start "wa-gateway-lab02" /min powershell -NoLogo -WindowStyle Hidden -Command "Set-Location '%~dp0'; $env:GATEWAY_ENV_PATH='%~dp0.env.lab02'; npm start *> '%~dp0gateway-lab02-run.log' 2>&1"
