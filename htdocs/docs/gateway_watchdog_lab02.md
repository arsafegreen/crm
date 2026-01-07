# Watchdog do gateway Movel-SafeGreen (lab02)

Guia rápido para manter o gateway alternativo (sessão `crm-sandbox-02`, porta 4020) estável e com QR disponível.

## Scripts por gateway
- lab01: `scripts/gateway_watchdog_lab01.ps1` (porta 4010) — log `storage/logs/gateway_watchdog_lab01.log`
- lab02: `scripts/gateway_watchdog_lab02.ps1` (porta 4020) — log `storage/logs/gateway_watchdog_lab02.log`
- lab03: `scripts/gateway_watchdog_lab03.ps1` (porta 4030) — log `storage/logs/gateway_watchdog_lab03.log`
- Estado/cooldown de cada um fica em `storage/logs/gateway_watchdog_<lab>.state.json`
- Comportamento: testa `/health` (timeout 10s). Após 2 falhas seguidas e respeitando cooldown de 180s, dispara o respectivo `start-gateway-<lab>.bat` e registra no log.

## Teste manual
```powershell
# lab01
pwsh -File scripts/gateway_watchdog_lab01.ps1 -VerboseLog
# lab02
pwsh -File scripts/gateway_watchdog_lab02.ps1 -VerboseLog
# lab03
pwsh -File scripts/gateway_watchdog_lab03.ps1 -VerboseLog
```
Saída 0 = OK; 1 = falha sem restart; 2 = restart disparado.

## Agendar como serviço/tarefa
### Opção 1: Agendador de Tarefas (simples)
- Criar tarefa “WA Watchdog lab02” para rodar a cada 1 minuto.
- Ação (exemplo lab02): `pwsh.exe -NoLogo -ExecutionPolicy Bypass -File "f:\SISTEMA - SAFEGREEN\XAMPP - PROD - A\htdocs\scripts\gateway_watchdog_lab02.ps1"`
- Executar mesmo se usuário não estiver logado; usar conta de serviço com permissão de leitura na pasta.
 - Repita para lab01 e lab03 trocando o nome do script.

### Opção 2: Serviço (NSSM)
- Instalar NSSM e criar serviço, ex.: `nssm install wa-gateway-lab02 "C:\Program Files\PowerShell\7\pwsh.exe" -NoLogo -ExecutionPolicy Bypass -File "f:\SISTEMA - SAFEGREEN\XAMPP - PROD - A\htdocs\scripts\gateway_watchdog_lab02.ps1"`
- Repita para `wa-gateway-lab01` e `wa-gateway-lab03` ajustando o script.
- Definir Start=Automatic e Log on As com usuário que tenha acesso aos arquivos.

## Diagnóstico
- Consultar `storage/logs/gateway_watchdog_lab02.log` para ver falhas de health e reinícios.
- Consultar `storage/logs/alerts.log` para detalhes de erros do endpoint `/whatsapp/alt/qr` (status/body em `body_snippet`).
- Se o log indicar muitas falhas consecutivas, validar rede/porta 4020 e cookies/sessão do WhatsApp Web.

## Limites
- Cooldown entre reinícios: 180s para evitar loop.
- Snippet de erro do gateway truncado em 800 chars para não poluir logs.

Aplique a mesma estratégia nos demais gateways clonando o script e ajustando porta/slug/comando de start.
