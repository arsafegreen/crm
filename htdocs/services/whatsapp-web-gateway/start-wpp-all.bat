@echo off
setlocal
cd /d "%~dp0"

echo Iniciando WPP 01...
call "%~dp0start-gateway-wpp01.bat"

echo Iniciando WPP 02...
call "%~dp0start-gateway-wpp02.bat"

echo Iniciando WPP 03...
call "%~dp0start-gateway-wpp03.bat"

echo Iniciando WPP 04...
call "%~dp0start-gateway-wpp04.bat"

echo Todos os gateways WPP foram acionados.
endlocal
