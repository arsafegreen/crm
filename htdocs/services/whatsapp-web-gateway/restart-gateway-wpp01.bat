@echo off
setlocal
cd /d "%~dp0"
set PORT=4100
set LOG=%~dp0gateway-wpp01-run.log

rem Mata qualquer processo escutando na porta 4100
powershell -NoLogo -Command "Get-NetTCPConnection -LocalPort %PORT% -State Listen -ErrorAction SilentlyContinue | Select-Object -Expand OwningProcess -Unique | ForEach-Object { if ($_ -ne $null) { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue } }"

rem Opcional: mata processos node remanescentes se ainda estiverem presos
powershell -NoLogo -Command "Get-Process node -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue"

rem Sobe o gateway em background (mesmo comando do start)
start "wa-gateway-wpp01" /min powershell -NoLogo -WindowStyle Hidden -Command "Set-Location '%~dp0'; $env:GATEWAY_ENV_PATH='%~dp0.env.wpp01'; npm start *> '%LOG%' 2>&1"

echo Reiniciado. Veja o log em %LOG%.
endlocal
