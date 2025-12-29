# Agendamento da Rotina Noturna (Windows Task Scheduler)

Siga os passos abaixo para executar `nightly_crm_maintenance.ps1` automaticamente todos os dias entre 20h e 06h.

## 1. Pré-requisitos
1. Atualize `config/performance/mysql_maintenance.cnf` com usuário e senha do MySQL.
2. Garanta que o serviço `cloudflared` (Cloudflare Tunnel) esteja instalado como serviço do Windows.
3. Teste o script manualmente:
   ```powershell
   PowerShell -ExecutionPolicy Bypass -File F:\sistemas\xampp-dwv\scripts\performance\nightly_crm_maintenance.ps1
   ```
4. Verifique o log em `F:\sistemas\xampp-dwv\logs\nightly_crm_maintenance.log`.

## 2. Criar tarefa agendada
1. Abra **Agendador de Tarefas** ➜ **Criar Tarefa...**
2. Guia **Geral**:
   - Nome: `CRM Nightly Maintenance`
   - Marque "Executar com privilégios mais altos".
   - Configure para executar mesmo sem usuário conectado.
3. Guia **Disparadores**:
   - Novo... ➜ Iniciar tarefa: **Diariamente** às **20:00**.
   - Marque "Repetir a tarefa a cada 4 horas" por uma duração de **10 horas** (isso cobre o intervalo até 06:00). Assim, se o servidor reiniciar, a rotina será reexecutada dentro da janela.
4. Guia **Ações**:
   - Ação: **Iniciar um programa**
   - Programa/script: `powershell.exe`
   - Adicionar argumentos: `-ExecutionPolicy Bypass -File "F:\sistemas\xampp-dwv\scripts\performance\nightly_crm_maintenance.ps1"`
5. Guia **Condições**:
   - Opcional: habilite "Iniciar a tarefa somente se o computador estiver ligado à rede".
6. Guia **Configurações**:
   - Marque "Executar a tarefa o mais cedo possível após uma inicialização agendada perdida".
   - Marque "Interromper a tarefa se estiver em execução por mais de 2 horas".

## 3. Monitoramento
- Logs completos: `F:\sistemas\xampp-dwv\logs\nightly_crm_maintenance.log`
- Slow query log MySQL: `C:\ProgramData\MySQL\MySQL Server\slow-crm.log` (ajuste conforme seu `mysql.cnf`).
- Eventos do Agendador: `Event Viewer ➜ Applications and Services Logs ➜ Microsoft ➜ Windows ➜ TaskScheduler`.

Com isso o Windows cuidará da limpeza de cache, otimização e reinício rápido do XAMPP/Cloudflare Tunnel todas as noites automaticamente.
