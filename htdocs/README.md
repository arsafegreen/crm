# Marketing Suite para Certificado Digital

Plataforma interna para centralizar CRM, importação de contatos, campanhas e automações do produto de certificado digital. O projeto já está em uso pelos times de marketing e operações e serve como base para novos módulos financeiros.

## Recursos disponíveis

- Stack PHP 8.2 com autoload PSR-4, FastRoute e Symfony HttpFoundation.
- UI responsiva com presets do tema futurista aplicado nos módulos de marketing e administração.
- Importador de contatos marketing com template oficial (`public/samples/marketing_contacts_template.csv`), detecção de duplicados e logs em `import_logs`.
- Perfis de permissão centralizados em `config/permissions.php`, aplicados automaticamente durante aprovações e quando nenhum módulo é selecionado manualmente.
- Worker de campanhas com fila (`mail_queue_jobs`) e monitoramento via `storage/logs/mail_worker.log`.
- Base CRM completa (clientes, certificados, parceiros, agenda AVP) compartilhada pelos serviços de marketing e finanças.

## Como executar

1. Instale as dependências com Composer:
   ```bash
   composer install
   ```
2. Copie `.env.example` para `.env` e ajuste conforme necessidade.
3. Execute as migrações para criar as tabelas necessárias:
   ```bash
   php scripts/migrate.php
   ```
4. Inicie um servidor local apontando para `public/index.php`:
   ```bash
   php -S localhost:8080 -t public
   ```
5. Acesse `http://localhost:8080` no navegador.

### Fila de campanhas e worker de e-mail

O disparo de campanhas agora ocorre em duas etapas:

1. **Geração das mensagens** – execute `php scripts/marketing/enqueue_campaign_messages.php --limit=200` para mover `campaign_messages` pendentes para `mail_queue_jobs`, garantindo que cada registro fique preso à versão correta do template.
2. **Entrega** – mantenha o worker ativo com:
   ```bash
   php scripts/marketing/deliver_mail_queue.php \
      --worker=mailer01 \
      --batch=25 \
      --sleep=10 \
      --account-id=1
   ```
   Flags disponíveis:
   - `--account-id=` força uma conta de envio específica (`email_accounts`). Caso omisso, a primeira conta ativa é usada.
   - `--batch=` define quantos jobs são processados por ciclo.
   - `--sleep=` intervalo (segundos) entre varreduras quando a fila está vazia.
   - `--once` processa apenas um lote (útil para execuções agendadas).
   - `--log=` caminho opcional para o arquivo de log (padrão `storage/logs/mail_worker.log`).

#### Instrumentação e auditoria

- Cada envio gera linhas no `storage/logs/mail_worker.log`, incluindo entradas por job (`[OK]` ou `[ERRO]`) e um resumo por ciclo (`[CICLO #N] Processados X jobs (ok=Y, erros=Z)`). Essas informações podem ser coletadas por agentes externos (ex.: Telegraf) para gráficos ou alertas.
- A tabela `mail_delivery_logs` recebe eventos estruturados (`sent`, `error`, `consent_*`) com metadata (destinatário, mensagem do provedor ou contexto da preferência). Utilize-a para dashboards ou auditorias LGPD e para exportar o histórico dos contatos.
- Em caso de falha crítica (ex.: sem contas de envio), o worker registra o erro no mesmo arquivo de log e encerra com código `1`, permitindo integração com sistemas de supervisão (systemd, PM2, cron, etc.).

## Importação de contatos

1. Acesse **Marketing > Listas** e clique em **Importar contatos** na lista desejada.
2. Baixe o template mais recente (link direto na tela) ou utilize `public/samples/marketing_contacts_template.csv`.
3. Preencha cabeçalhos padrão; colunas com prefixo `custom.` viram atributos dinâmicos.
4. Informe uma etiqueta de origem opcional e ative **Respeitar opt-out** se não quiser reativar contatos inativos.
5. Envie o CSV (até o limite exibido na tela). O resumo mostra linhas processadas, novos registros, atualizações, duplicidades e até 50 erros.
6. Cada importação gera entrada em `import_logs` e histórico localizado na própria visualização da lista.

## WhatsApp + IA Copilot

- Módulo dedicado em **Conversas inteligentes > WhatsApp** com painel 3 colunas (inbox, chat, IA/Configuração).
- Persistência segura em `whatsapp_contacts`, `whatsapp_threads` e `whatsapp_messages`, com relacionamento opcional com clientes do CRM.
- Integração nativa com a API oficial da Meta (token, phone number ID, business account ID e webhook token armazenados no `settings`).
- Copilot interno gera respostas contextualizadas e identifica sentimento com base no último turno da conversa; sugestões podem ser aplicadas direto no formulário de envio.

### Configuração rápida

1. Execute `php scripts/migrate.php` para aplicar as migrações `2025_12_02_110000+` (contatos, threads e mensagens do WhatsApp).
2. Acesse **WhatsApp** no menu e preencha o formulário **Configuração Meta API** com o `Access Token`, `Phone Number ID`, `Business Account ID` e o `Webhook Verify Token` gerados no Meta Business.
3. Ainda no painel Meta, registre a URL `https://SEU_DOMINIO/whatsapp/webhook` usando o mesmo verify token; valide o desafio `hub.challenge` (o endpoint GET já está exposto sem CSRF).
4. Opcional: informe a `Copilot API Key` (ou mantenha o motor interno) para habilitar as sugestões em tempo real.
5. Use o botão **Sugerir com IA** dentro da conversa para preencher automaticamente a resposta e ajuste conforme necessário antes de enviar.

> Sempre que as credenciais estiverem incompletas, o sistema marca o envio como `queued` para evitar falhas; o histórico da thread permanece salvo.

## Perfis de permissões

- Configure os perfis no arquivo `config/permissions.php`. Perfis padrão: `operational`, `marketing`, `readonly` e `admin`.
- A chave `defaults.new_user_profile` define o perfil aplicado a cadastros sem seleção manual.
- O painel **Admin > Solicitações de acesso** aplica automaticamente o perfil quando nenhum módulo é marcado, evitando aprovações vazias.
- Ajustes finos continuam disponíveis na interface, porém recomenda-se manter os perfis sincronizados para auditoria.

## Testes

- O importador possui cenário automatizado em `tests/Marketing/MarketingContactImportServiceTest.php`.
- Execute-o diretamente com PHP:
   ```bash
   php tests/Marketing/MarketingContactImportServiceTest.php
   ```
   O script usa SQLite em memória e valida criação/atualização de contatos, atributos customizados e logs de importação.

### Validando o módulo WhatsApp

1. Garanta que as novas migrações foram executadas e que o menu **WhatsApp** está visível para usuários com a permissão `whatsapp.access`.
2. Preencha o formulário de credenciais no painel e cadastre o webhook no Meta.
3. Para testar sem aguardar mensagens reais, envie um POST local para o webhook simulando a notificação da Meta:
   ```bash
   curl -X POST http://localhost:8080/whatsapp/webhook \
      -H "Content-Type: application/json" \
      -d '{
         "entry": [{
            "changes": [{
               "value": {
                  "contacts": [{"profile": {"name": "Teste"}}],
                  "messages": [{"from": "5511999999999", "id": "wamid.GB..", "timestamp": "1701532800", "text": {"body": "Olá"}}]
               }
            }]
         }]
      }'
   ```
4. Atualize a tela do módulo para visualizar a nova conversa e use o formulário de envio; caso ainda não exista token válido, o status retornará `queued`, comprovando que o armazenamento funcionou.

## Próximos passos sugeridos

- Documentar processos operacionais e checklists (manual do administrador/usuário).
- Padronizar o visual do módulo financeiro para o tema futurista.
- Integrar APIs sociais usando os tokens cadastrados (Meta, LinkedIn, WhatsApp, etc.).
- Evoluir a fila interna/agenda de execuções recorrentes.

## Centro de preferências e consentimento

- Cada contato marketing possui um `preferences_token` único (gerado automaticamente) e pode acessar o endereço público `/preferences/{token}` para revisar categorias LGPD (campanhas, cases, eventos e alertas). O layout é responsivo e segue o branding escuro do painel.
- Alterações de preferências alimentam `marketing_contact_attributes` e ajustam `marketing_contacts.consent_status` automaticamente. Se todas as categorias forem desligadas o contato é marcado como *opted_out* e removido de todas as listas (`audience_list_contacts`).
- O botão **Baixar registros** exporta um JSON assinado pelo backend com todos eventos `consent_*` gravados em `mail_delivery_logs`, permitindo auditoria completa para o jurídico/compliance.
- Links de confirmação (double opt-in) podem anexar `?confirm=1` ao endereço `/preferences/{token}`; ao acessar, o contato é promovido para `consent_status=confirmed` e o evento fica disponível no mesmo registro histórico.
- As categorias e descrições do centro de preferências ficam em `config/marketing.php`, facilitando ajustes por mercado/campanha sem alterar o código.

## Fluxo de parceiros e indicações

- Acesse `CRM > Parceiros` para localizar ou cadastrar contadores/parceiros.
- Use o campo **Nome do parceiro** para buscar o cadastro; o sistema lista até 50 resultados por termo e revela, logo abaixo, todos os clientes (apenas CPF) com o mesmo nome para conferência manual.
- Envie o formulário em branco para revisar rapidamente os primeiros 200 parceiros cadastrados; um aviso amarelo sinaliza quando o limite da listagem for atingido — refine a busca pelo nome/documento para reduzir o conjunto.
- Cada cartão exibe um semáforo baseado na última indicação válida (descontando certificados emitidos para o próprio CPF do parceiro):
   - **Verde**: indicou nos últimos 30 dias.
   - **Amarelo**: está há mais de 30 dias sem indicar, porém menos de 366 dias.
   - **Vermelho**: passou 366 dias (ou nunca registrou indicação).
- Dentro do cartão, utilize o botão **Marcar como parceiro** para converter rapidamente um cliente homônimo; caso já exista vínculo, o botão muda para **Atualizar cadastro** mantendo o relacionamento sincronizado.

## Boas práticas de produção

- No arquivo `.env`, mantenha `APP_DEBUG=false` e defina `APP_FORCE_HTTPS=true` para forçar redirecionamento seguro.
- Certifique-se de apontar o servidor web para a pasta `public/`; o diretório `storage/` já inclui `.htaccess` para bloquear acesso direto.
- Restrinja permissões do `.env` e dos arquivos de chave (`storage/database.key`), e mantenha backups criptografados fora do servidor público.

## Geração manual de alertas de sincronização

Para registrar um alerta manual do resumo de sincronização de caixas de email:

1. Execute normalmente o script de sincronização:
   ```bash
   php scripts/email/sync_mailboxes.php --account_id=4 --limit=200 --force-resync=1 > scripts/email/sync_mailboxes.log
   ```
   > O log será salvo em `scripts/email/sync_mailboxes.log`.

2. Gere o alerta manualmente:
   ```bash
   php scripts/email/generate_mailbox_sync_alert.php
   ```
   > Isso irá ler o log, resumir os dados e registrar um alerta no painel de Configurações.

O alerta incluirá o total de contas, pastas sincronizadas, mensagens novas, ignoradas e erros.

Você pode adaptar o script para outros logs, bastando ajustar o parser conforme o formato desejado.
