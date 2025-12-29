# Plataforma de E-mail — Visão Ampliada

Última atualização: 2025-12-04

## 1. Objetivos

- Unificar contas SMTP/IMAP para uso interno (cliente estilo Outlook) e externo (disparos em massa).
- Reutilizar cadastros existentes (CRM, listas de Marketing, base RFB) sem duplicar módulos.
- Garantir compliance (SPF/DKIM/DMARC, opt-in, LGPD), rastreabilidade e métricas.

## 2. Componentes principais

1. **Conectores de Conta**
   - Ampliação de `email_accounts`: incluir parâmetros IMAP, preferências e cofres de segredo.
   - Health-check automático (último teste de envio/recebimento, status DNS).

2. **Cliente de E-mail (estilo Outlook)**
   - Sincronização IMAP → tabelas `email_threads`, `email_messages`, `email_folders`, `email_attachments`.
   - UI de caixa de entrada com filtros, busca, etiquetas e leitura/resposta.
   - Registro cruzado com CRM (timeline do contato) e RFB (histórico jurídico/fiscal).

3. **Orquestrador de Campanhas**
   - Fila de envios massivos com seleção inteligente de conta (limites horário/diário/burst, warm-up, reputação).
   - Execução assíncrona (jobs) + monitoramento de status (enviado, entregue, bounce, spam, opt-out).
   - Templates dinâmicos (MJML/HTML) e personalização com dados de CRM/RFB.

4. **Métricas & Observabilidade**
   - Painel com entregas, taxas de abertura, bounces, fila, reputação DNS.
   - Webhooks/ingest de eventos (feedback loops, unsubscribes) para atualizar listas e histórico.

5. **Compliance & Segurança**
   - Gestão de consentimento centralizada (opt-in, opt-out, double opt-in).
   - Criptografia de credenciais, auditoria de ações, alertas automáticos.

## 3. Modelo de dados (inicial)

| Tabela | Campos chave | Observações |
| --- | --- | --- |
| `email_accounts` | IMAP host/porta, flags, `last_health_check_at` | expandir a tabela existente |
| `email_threads` | `subject`, `account_id`, `primary_contact_id`, `last_message_at` | agrupa conversas |
| `email_messages` | `thread_id`, `direction`, `sender`, `recipients`, `status`, `payload` | referencia anexos |
| `email_attachments` | `message_id`, `path`, `size`, `hash` | arquivos salvos em storage |
| `email_sends` | `campaign_id`, `account_id`, `contact_id`, `status`, `metrics_json` | log de envios massivos |
| `email_events` | `send_id`, `type`, `metadata` | delivered/open/bounce/spam |
| `email_jobs` | `type`, `payload`, `status`, `priority`, `attempts` | fila interna curta (opcional) |

## 4. Fluxos

### 4.1 Sincronização IMAP

1. Job agenda (`MailboxSyncJob`) por conta ativa.
2. Conecta ao IMAP (IDLE ou incremental), traz novos UIDLs.
3. Normaliza mensagens → salva em `email_messages` e cria threads.
4. Atualiza indicadores (contador não lidos, alertas CRM, notificações realtime).

### 4.2 Envio individual

1. Usuário escolhe conta + contato, compõe com editor (HTML/MJML/Texto).
2. Serviço `EmailSendService` seleciona credenciais (SMTP ou API) e envia.
3. Registra `email_messages` (direction=outbound) + `email_sends` (tipo "manual").
4. Atualiza timeline do contato e dispara webhooks internos.

### 4.3 Orquestração de campanhas

1. Campanha define: lista/segmento (CRM, RFB), template, linha do assunto, critérios de throttling.
2. Scheduler gera jobs em `email_jobs` com lote de destinatários.
3. Worker `CampaignDispatchJob` seleciona conta disponível (balanceamento round-robin ponderado) e envia via SMTP/API.
4. Eventos de entrega/bounce alimentam `email_events` e atualizam saúde das listas (opt-out, supressão).
5. Painel mostra progresso por campanha e por conta.

### 4.4 Health-check e compliance

- Job diário executa testes de login SMTP/IMAP, envio para caixa de teste, verificação de DNS (via APIs ou DNS lookup).
- Atualiza `email_accounts.last_health_check_at`, `dns_status`, anexa relatório.
- Notifica se alguma conta cair para `paused/disabled` automaticamente.

## 5. Integração com CRM e base RFB

- Todos os `email_messages` devem referenciar o contato CRM (quando e-mail é conhecido) ou gerar lead.
- Campanhas podem usar filtros combinados (segmentos + CNAE, porte, status fiscal/RFB).
- Opt-outs recebidos (header List-Unsubscribe, links) atualizam cadastro central.

## 6. Roadmap sugerido

1. **Fase 1** — Orquestrador + health-check básico
   - Estender `email_accounts` com campos necessários.
   - Criar tabelas `email_sends`, `email_events`, `email_jobs`.
   - Implementar serviço de limitação + jobs de disparo massivo.
   - Health-check (SMTP send + atualização status).

2. **Fase 2** — Inbox & sincronização
   - Modelar threads/mensagens, importar e-mails via IMAP.
   - UI inicial de leitura e envio individual.
   - Notificações e integração com CRM.

3. **Fase 3** — Métricas, automações e experiência avançada
   - Dashboards, alertas, rotinas de limpeza/list cleaning.
   - Automação baseada em eventos (ex.: follow-up após bounce suave).
   - Recursos avançados (delegação de caixa, assinaturas múltiplas, AI assistente de e-mail).

## 7. Próximos passos imediatos

- Validar expansão do schema: definir migrations para novas tabelas.
- Especificar interface do orquestrador (serviço, jobs, eventos) e health-check.
- Priorizar UI/UX necessária no curto prazo (painel de campanhas vs inbox).
- Definir políticas de segurança/armazenamento de credenciais.

## 8. Orquestrador de campanhas — plano detalhado

### 8.1 Objetivos

- Controlar disparos em massa respeitando limites por conta e garantindo alta entregabilidade.
- Permitir múltiplas campanhas simultâneas sem conflito.
- Registrar métricas completas (envio → evento) e realimentar listas/CRM.

### 8.2 Tabelas/migrations necessárias

- `email_campaigns` (se ainda não existir): nome, lista/segmento associado, template, status, programação.
- `email_campaign_batches`: referência à campanha, intervalo de destinatários, status, timestamps.
- `email_sends`: já listado; armazenar `batch_id`, `contact_id`, `account_id`, `status`, `attempts`, `last_error`, `metadata`.
- `email_events`: tipo (`delivered`, `open`, `click`, `bounce_soft`, `bounce_hard`, `spam`, `unsubscribe`), payload bruto.
- `email_rate_limits`: snapshot por conta (hora atual, enviados, limites) para facilitar cálculo.

### 8.3 Serviços / classes

1. `CampaignSchedulerService`
   - Recebe a campanha, fatiamento da lista (1k/5k registros por batch).
   - Cria registros em `email_campaign_batches` + jobs correspondentes.
2. `CampaignDispatchJob`
   - Input: `batch_id`.
   - Passos: buscar destinatários -> selecionar conta -> enviar -> registrar `email_sends`.
3. `AccountSelectionService`
   - Estratégia round-robin ponderada (considera limites, warm-up, reputação, prioridades).
4. `EmailDeliveryProvider`
   - Abstração: SMTP nativo, Amazon SES, SendGrid, Mailtrap etc.
5. `DeliveryEventConsumer`
   - Webhook/worker que transforma eventos externos em `email_events` e atualiza listas (opt-out, supressão).

### 8.4 Rate limiting / warm-up

- Cada conta mantém contadores `sent_last_hour` e `sent_last_24h` (em `email_rate_limits` ou cache Redis).
- `AccountSelectionService` verifica se enviar X mensagens extrapola o limite. Se sim, passa para próxima conta ou aguarda (job re-enfileirado).
- Warm-up: `warmup_status` + `warmup_plan.target_volume` definem o teto dinâmico. Ex.: semana 1 = 500/dia; semana 2 = 1k/dia.
- Burst control: se `burst_limit` > 0, job fraciona lote em micro lotes (100/200) com `sleep` ou re-enfileiramento.

### 8.5 Fluxo de execução

1. Usuário aprova campanha → `CampaignSchedulerService` gera batches (ex.: 50k contatos → 50 batches de 1k).
2. Worker consome `CampaignDispatchJob(batch_id)`:
   - Carrega lote de contatos + personalização necessária.
   - Para cada mensagem, escolhe conta via `AccountSelectionService` e envia usando provider correto.
   - Atualiza `email_sends` (`status=sent` ou `failed` com motivo). Reintenta falhas temporárias (até 3x) com backoff.
3. Quando provider envia eventos (webhooks, logs), `DeliveryEventConsumer` atualiza `email_events` + `email_sends.status`.
4. Métricas agregadas alimentam painel (envios totais, progresso % por campanha, entregabilidade por conta).

### 8.6 Monitoramento e alertas

- Job periódico revisa `email_sends` pendentes há muito tempo e reprocessa.
- Alertas se taxa de bounce superar limite (ex.: >5% = pausar campanha/conta).
- Audit trail: cada job registra duração, conta utilizada, erros (Elastic/Logstash ou DB simples).

### 8.7 APIs / Extensões futuras

- API REST para criar campanhas programaticamente.
- Suporte a A/B test (dividir batches, comparar KPIs e promover vencedor automaticamente).
- Conector para SMS/WhatsApp usando a mesma estrutura de orquestração (reuso de jobs e monitoramento).

## 9. Inbox / sincronização IMAP — plano detalhado

### 9.1 Campos adicionais em `email_accounts`

- `imap_host`, `imap_port`, `imap_encryption` (`ssl`, `tls`, `none`).
- `imap_username`, `imap_password` (armazenados criptografados/cofre).
- Flags de sincronização: `imap_sync_enabled`, `imap_last_uid`, `imap_last_sync_at`.
- Preferências de usuário: pastas favoritas, assinaturas, regras automáticas (JSON em `settings`).

### 9.2 Novas tabelas

| Tabela | Campos chave | Observações |
| --- | --- | --- |
| `email_folders` | `account_id`, `remote_name`, `type`, `sync_state` | Mapear Inbox/Sent/Custom |
| `email_threads` | já descrita; incluir `folder_id`, `unread_count` |
| `email_messages` | `external_uid`, `message_id`, `folder_id`, `direction`, `flags`, `snippet`, `body_path` |
| `email_message_participants` | `message_id`, `type` (to/cc/bcc), `email`, `contact_id` |
| `email_attachments` | idem seção 3 |

### 9.3 Serviços / Jobs

1. `MailboxSyncJob`
   - Consumido via scheduler (ex.: a cada 5min por conta ativa ou via webhook IMAP IDLE).
   - Passos: conectar, buscar novos UIDs > `imap_last_uid`, fazer FETCH (headers+body), persistir.
   - Atualiza `imap_last_uid` e contadores.
2. `MailboxSyncService`
   - Lógica reutilizável (testável) para mapear mensagens → threads, resolver contatos, salvar anexos.
3. `MailboxRulesService`
   - Aplica regras (mover para pasta, marcar como importante, criar tarefa CRM) após ingestão.
4. `EmailComposeService`
   - Reutilizado tanto para respostas quanto envios novos; grava `email_messages` antes/depois do envio.

### 9.4 UI/UX proposta

- Layout com colunas: lista de pastas → lista de threads → leitura da mensagem.
- Filtros: não lidos, marcados, datas, contatos, campanhas associadas.
- Ações rápidas: responder, responder a todos, encaminhar, arquivar, mover para lista/etiqueta.
- Integração CRM: painel lateral com dados do contato, negócios, notas, tarefas.

### 9.5 Performance e limites

- Paginação incremental por pasta (ex.: carregar 50 mensagens por vez).
- Armazenar apenas corpo renderizado + versão raw comprimida (para reprocessar se necessário).
- Limitar anexos grandes (guardar em storage e stream no download).

### 9.6 Segurança

- Credenciais IMAP criptografadas usando chave do servidor ou serviço de segredos.
- Permissões finas: quem pode visualizar determinada conta (usuário owner, equipe, delegação).
- Logs de auditoria para acesso/abertura de mensagens.

### 9.7 Futuras extensões

- Suporte a Exchange/Graph API além de IMAP puro.
- Classificação automática (AI) para priorizar e sugerir respostas.
- Tarefas automáticas (ex.: criar follow-up se mensagem ficar sem resposta > X dias).
