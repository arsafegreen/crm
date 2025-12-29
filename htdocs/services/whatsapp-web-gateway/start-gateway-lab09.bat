@echo off
setlocal
cd /d "%~dp0"
set "GATEWAY_ENV_PATH=%~dp0.env.lab09"
echo [Gateway] Inicializando WhatsApp Web (instancia Lab 09) com arquivo %GATEWAY_ENV_PATH%...
npm start
