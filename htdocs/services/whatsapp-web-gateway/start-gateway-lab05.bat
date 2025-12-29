@echo off
setlocal
cd /d "%~dp0"
set "GATEWAY_ENV_PATH=%~dp0.env.lab05"
echo [Gateway] Inicializando WhatsApp Web (instancia Lab 05) com arquivo %GATEWAY_ENV_PATH%...
npm start
