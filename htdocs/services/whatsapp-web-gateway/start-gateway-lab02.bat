@echo off
setlocal
cd /d "%~dp0"
set "GATEWAY_ENV_PATH=%~dp0.env.lab02"
echo [Gateway] Inicializando WhatsApp Web (instancia Lab 02) com arquivo %%GATEWAY_ENV_PATH%%...
npm start
