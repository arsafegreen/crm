# Manual do Administrador – Marketing Suite para Certificado Digital

> Versão 0.3 · Atualizado em 02/12/2025

## 1. Papel do administrador
O administrador garante a disponibilidade, segurança e governança do sistema. Responsabilidades principais:
- Provisionar e revisar usuários/permissões.
- Configurar integrações (e‑mail, social, agenda AVP, base RFB).
- Executar rotinas de manutenção (backups, migrações, monitoramento de logs).

## 2. Infraestrutura mínima
- PHP 8.2+ com extensões `pdo_sqlite`, `openssl`, `mbstring`, `zip`, `fileinfo` e `intl`.
- Composer 2.x.
- Servidor web apontando para `htdocs/public` (Apache, Nginx ou `php -S`).
- Diretório `storage/` com permissão de escrita e protegido por `.htaccess`.
- Opcional: serviço Caddy (arquivo `Caddyfile`) para ambientes Windows XAMPP.

## 3. Implantação inicial
1. Clone ou copie o projeto para `htdocs`.
2. Execute `composer install` para baixar dependências.
3. Copie `.env.example` para `.env` e configure variáveis:
   - `APP_NAME`, `APP_URL`, `APP_FORCE_HTTPS`, `TIMEZONE`.
   - `DB_CONNECTION=sqlite` (padrão) ou outro driver futuro.
   - `DB_DATABASE`, `DB_ENCRYPTION_ENABLED`, `DB_ENCRYPTION_KEY`/`DB_ENCRYPTION_KEY_FILE`.
4. Rode `php scripts/migrate.php` para criar o banco (`storage/database.sqlite`).
5. (Opcional) Gere `storage/database.key` com 32+ caracteres e aponte em `.env`.
6. Inicie o servidor (ex.: `php -S localhost:8080 -t public`).

## 4. Segurança e autenticação
### 4.1 Políticas internas
- Bloqueio automático após 5 tentativas incorretas por 120 minutos.
- Sessão expira após 20 minutos de inatividade (constante `INACTIVITY_LIMIT`).
- Senhas expiram em 30 dias (`PASSWORD_MAX_AGE`).
- TOTP disponível para usuários com `totp_enabled`.

### 4.2 Fluxo de aprovação
- Novos cadastros ficam em `Admin > Solicitações de Acesso`.
- Cada registro exibe dados básicos, motivo e certificado digital (se usado).
- Botões disponíveis: **Aprovar**, **Negar**, **Atualizar permissões**, **Definir janela de acesso**, **Forçar logout**, **Resetar senha**, **Desativar/Ativar**.
- Para revisar segurança de dispositivos, acesse as ações “política de dispositivos” e “sincronizar acesso AVP”.
- Todos os botões críticos exibem feedback visual (spinner + rótulo de carregamento) e ficam bloqueados durante o envio para evitar cliques duplicados.

### 4.3 Perfis e permissões
- As permissões continuam salvas em JSON por usuário, porém agora são orquestradas pelo arquivo `config/permissions.php`.
- Perfis padrão (`operational`, `marketing`, `readonly`, `admin`) definem conjuntos prontos com as novas chaves de finanças (`finance.*`) além dos módulos de CRM e marketing.
- O campo `defaults.new_user_profile` especifica qual perfil aplicar automaticamente quando o admin aprova um usuário sem selecionar manualmente os módulos.
- Painel **Admin > Solicitações de acesso** aplica o perfil correspondente e, caso todas as caixas sejam desmarcadas, volta para o perfil padrão para evitar aprovações vazias.

Principais chaves disponíveis: `dashboard.overview`, `crm.*`, `rfb.base`, `marketing.lists`, `marketing.segments`, `campaigns.email`, `social_accounts.manage`, `templates.library`, `finance.overview`, `finance.calendar`, `finance.accounts`, `finance.accounts.manage`, `finance.cost_centers`, `finance.transactions`, `config.manage`, `admin.*`.

> Ajustes finos ainda podem ser feitos usuário a usuário, mas sempre atualize `config/permissions.php` ao criar um novo perfil para manter auditoria e consistência entre times.

## 5. Configurações do sistema (`/config`)
A página foi reorganizada em blocos com menu lateral fixo. Cada seção exibe feedback contextual e destaca erros/campos afetados.

- **Políticas de acesso**: defina janela de horário (HH:MM) para logins e, opcionalmente, obrigue dispositivos já reconhecidos para perfis administrativos.
- **Base RFB e importações**:
   - Upload exclusivo para arquivos CSV oficiais (quebra automática em partes de 50 MB).
   - Preferência “Ignorar certificados mais antigos” grava em `/config/import-settings` e afeta o serviço de importação manual/automática.
   - Histórico das últimas 10 importações com contadores processados/novos/atualizados.
- **Tema visual**: escolha presets de fundo (gradientes SafeGreen). Alterações aplicam imediatamente a todos os usuários.
- **Conta de e-mail**: formulário SMTP completo (host, porta, criptografia, credenciais, remetente, reply-to) com validações e mensagens específicas.
- **Templates**: lista resumida dos modelos com link direto para editar no módulo dedicado.
- **WhatsApp rápido**: editor dos três textos usados na ficha do cliente (felicitações, renovação e resgate). Aceita variáveis como `{{nome}}`, `{{empresa}}`, `{{documento}}`, `{{vencimento}}` e `{{status}}`, aplicadas automaticamente nos botões de WhatsApp.
- **Backup e manutenção** *(somente administradores)*:
   - Exportar backup ZIP completo.
   - Gerar planilha compatível com a tela de importação.
   - Importar planilha (XLS/XLSX/CSV) diretamente do painel.
   - Restaurar padrão de fábrica mediante confirmação “REDEFINIR”.
- **Releases do sistema** *(somente administradores)*:
   - **Gerar** pacote `.zip` diretamente pelo painel (define versão, notas e opção “pular vendor”). O arquivo é salvo em `storage/releases/` para download manual.
   - **Importar** pacote emitido pelo script ou pelo próprio painel e deixar várias versões prontas para uso.
   - Visualizar checklist antes da aplicação, tabela com status (disponível/aplicada/falhou), tamanho, origem e notas.
   - Botões para baixar ou aplicar; ao aplicar, o painel dispara `scripts/apply_update.php` utilizando o arquivo armazenado em `storage/releases/imported` e registra o log (STDOUT/STDERR) exibido no feedback.
- **Canais sociais**: mesmo fluxo do módulo Social, porém com visão consolidada das contas e expiração de tokens.

## 6. Importações operacionais
### 6.1 Base RFB / CRM
- Forneça aos operadores o template mais recente via `Config > Exportar modelo de importação`.
- Após grandes importações, confira `storage/logs/laravel.log` (se habilitado) ou o painel de feedback no CRM.
- A base RFB é alimentada via upload em `/config` e sincronizada com clientes importados; ao registrar um CNPJ, o serviço remove automaticamente o registro correspondente da fila de prospecção.

### 6.2 Contatos de marketing
- O módulo **Marketing > Listas > Importar contatos** usa o serviço `MarketingContactImportService` e aceita apenas CSV com cabeçalho padrão.
- O template oficial fica em `public/samples/marketing_contacts_template.csv` e também pode ser baixado direto da tela.
- Colunas com prefixo `custom.` viram atributos dinâmicos (`marketing_contact_attributes`); campos vazios são ignorados automaticamente.
- Ative **Respeitar opt-out** para impedir que contatos `opted_out` sejam reativados; os demais status são atualizados conforme o CSV.
- Os resultados ficam visíveis na própria tela (processados, criados, atualizados, duplicados) e os 50 primeiros erros são exibidos para correção rápida.
- Cada execução grava `import_logs.source = marketing_contacts` com resumo (arquivo, usuário, totals) para auditoria. Use a tabela para relatórios ou scripts de reconciliação.
- Para validar alterações no importador, rode `php tests/Marketing/MarketingContactImportServiceTest.php` (SQLite em memória).

## 7. Agenda & AVP
- Configure horários padrão no repositório `avp_schedule_configs` via tela de agenda (quando disponível) ou script `scripts/update_security_window.php` (para ajustes automatizados).
- Controle de acesso AVP (quais usuários enxergam determinados AVPs) fica nas tabelas `client_avp_access` e `user_avp_filters`. Use o painel de administração para sincronizar “Escopo de clientes” ou “Sincronizar acesso histórico”.

## 8. Manutenção de dados
### 8.1 Backups
- Ative a criptografia do SQLite (`DB_ENCRYPTION_ENABLED=true`) para gerar arquivo `.enc` e chave separada.
- Agende cópia diária de `storage/database.sqlite` (ou `.enc`) e da pasta `storage/backups` para repositório seguro.
- Sempre copie também `.env` e `storage/database.key` para recuperação completa.

### 8.2 Logs e monitoramento
- Monitorar diretório `storage/logs`. Configure rota externa (ELK, Splunk) se necessário.
- Verificar `storage/uploads` para arquivos de importação pendentes; use `scripts/inspect_upload.php` para depurar.

### 8.3 Scripts úteis (`scripts/`)
- `migrate.php`: executa migrations pendentes.
- `reset_password.php`: força nova senha para um usuário.
- `promote_user.php`: eleva permissões/admin.
- `inspect_user.php`: mostra detalhes/flags de um usuário.
- `recalculate_client_status.php`: recalcula status baseado em certificados.
- `update_security_window.php`: ajusta janelas de acesso automatizadas.
- `backfill_client_names.php`: reprocessa os clientes usando o histórico dos certificados e acerta `Razão social` (CNPJ) e `Nome do titular` (CPF/CNPJ) sem precisar importar novamente. Execute em modo de simulação (`php scripts/backfill_client_names.php --verbose`) e só confirme após revisar o relatório (`php scripts/backfill_client_names.php --commit`).

## 9. Atualizações de código
1. Faça backup e coloque o site em modo manutenção (se aplicável).
2. Faça pull/merge e execute novamente `composer install --no-dev --optimize-autoloader`.
3. Rode `php scripts/migrate.php` para aplicar novas migrations.
4. Limpe caches (se implementados) e reinicie o serviço web.
5. Execute testes manuais básicos: login, CRM, importação, agenda.

## 10. Resolução de problemas
- **Login bloqueado**: use `reset_password.php` ou painel admin para desbloquear/limpar tentativas.
- **Sessões expirando cedo**: revise `APP_FORCE_HTTPS`, proxies e cabeçalhos `X-Forwarded-Proto`.
- **Importação falhou**: checar formato da planilha, permissões de `storage/uploads` e logs.
- **Banco corrompido**: restaurar do backup mais recente; se usa criptografia, confirmar chave correta.

## 11. Checklist periódico
- [Semanal] Revisar solicitações de acesso pendentes e limpar usuários inativos.
- [Semanal] Conferir logs de falhas de login e tentativas TOTP.
- [Mensal] Validar expiração de certificados dos usuários administradores.
- [Mensal] Testar procedimento de restauração de backup.
- [Trimestral] Revisar políticas de permissão e necessidades dos times.

## 12. Contato com TI
Mantenha canal direto (ex.: Teams/Slack) para incidentes críticos. Documente quaisquer alterações no repositório utilizando pull requests com descrição de impacto e procedimentos de rollback.
