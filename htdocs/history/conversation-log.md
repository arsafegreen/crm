# Historico de Conversas

Este arquivo mantem um resumo continuo das sessoes do Codex para retomarmos o contexto rapidamente apos um reset. Ao iniciar uma nova sessao, leia a entrada mais recente (e acrescente outra quando encerrar o trabalho).

## Como usar
- Cada entrada possui um **Status**. Se estiver `concluido`, e seguro seguir em frente; se estiver `pendente`, leia com atencao e continue a partir dali.
- Acrescente uma entrada datada sempre que finalizarmos um bloco relevante de trabalho e deixe o `Status: pendente` ate alguem validar.
- Seja conciso, mas acionavel: descreva o que mudou, onde e quais sao os proximos passos. Quando tudo for resolvido, troque para `Status: concluido`.
- Registre tudo em portugues (sem misturar ingles) e mantenha o texto legivel (sem caracteres corrompidos).

## Entradas

### 2025-12-10 - Modulo de marketing + sincronizacao da caixa de entrada
**Status:** concluido  
- Snapshot do modulo de marketing salvo em `backups/marketing-module-20251210-091255/`.  
- `MarketingController` passou a usar `ListMaintenanceService` + view model `ListDashboard`; CSS/JS extraidos para `public/css/marketing-lists.css` e `public/js/marketing-lists.js`.  
- Cards de contas de e-mail simplificados com `public/css/marketing-email-accounts.css` e secao colapsavel de detalhes avancados.  
- Sincronizacao manual da inbox envia erros ao `AlertService`, o front sincroniza apenas a pasta ativa e o processo assincrono mantem a UI responsiva.  
- Script CLI `scripts/email/sync_mailboxes.php` aceita listas de pastas em base64 para evitar problemas de shell.  
- Dica: no inicio de cada sessao mencione "Check history/conversation-log.md" para recuperar o contexto.

### 2025-12-10 - Reforco da sincronizacao da inbox
**Status:** concluido  
- Arquivos modificados foram copiados para `backups/email-module-20251210-105056/` antes das mudancas.  
- `app/Kernel.php` agora deduplica rotas do sweep de bounces e evita `BadRouteException` do FastRoute.  
- `EmailController::syncAccount` envia jobs assincronos no Windows com aspas corretas e alerta de fallback; `scripts/email/sync_mailboxes.php` reporta falhas no `AlertService`.  
- O script CLI forca `set_time_limit(0)` e registra excecoes capturadas no feed de alertas.  
- Proximo passo (ja validado): pedir para o usuario clicar em "Enviar e receber" para confirmar que aparecem alertas em vez de erro 500; item marcado como concluido apos a validacao.

### 2025-12-10 - Workflow das filas de WhatsApp
**Status:** concluido  
- Backup dos controllers/servicos/views de WhatsApp em `backups/whatsapp-module-20251210-112110/` antes de editar.  
- Paineis da fila exibem badges dinamicos (filas, agendamentos, parceiro/responsavel) e o formulario mostra campos extras apenas quando necessario.  
- Front-end envia POST para `/whatsapp/thread-queue`, atualiza contadores/badges sem recarregar e reaproveita helpers de parceiro/responsavel.  
- Layout standalone (`?standalone=1`) remove o chrome do CRM; sandbox + simulador registram tudo em `storage/logs/whatsapp-sandbox.log`.  
- Script `scripts/whatsapp/queue_flow_check.php` automatiza o ciclo chegada -> agendado -> parceiro -> chegada usando contato sandbox; executado sem excecoes ou alertas 500.  
- Pre-triagem IA via `/whatsapp/pre-triage` continua preenchendo `intake_summary` e sugestoes direto no formulario (validado durante o fluxo automatico).

### 2025-12-10 - Importacao de logs de WhatsApp + manual admin
**Status:** pendente  
- `WhatsappService` ganhou `ingestLogEntry` e helpers para registrar historicos (entrada/saida), reaproveitando filas/contatos e com opcao de marcar mensagens como lidas.  
- Script CLI `scripts/whatsapp/import_logs.php` le NDJSON (1 JSON por linha), aceita `--mark-read=0`, exibe estatisticas em portugues e envia excecoes graves ao `AlertService`.  
- Pagina apenas para admins `config/manual/whatsapp` documenta o sandbox, formato do log, comando do importador e checklist para migrar para a API oficial; botao "Manual WhatsApp" so aparece para admins.  
- Proximos passos: gerar NDJSON de teste do gateway atual, rodar o script no sandbox e checar se as conversas aparecem no modulo; revisar o manual com a equipe antes de liberar.

### 2025-12-10 - Gateway WhatsApp Web alternativo
**Status:** pendente  
- Servico Node criado em `services/whatsapp-web-gateway/` (WPPConnect + MySQL/SQLite) com endpoints HTTP, README e `.env.example`.  
- CRM ganhou `WhatsappAltController` (rotas `/whatsapp/alt/...`) para webhooks do gateway, envio de mensagens e exibicao de QR/status no modulo (card visivel apenas para admins).  
- `.env` e `.env.example` agora expoem tokens `WHATSAPP_ALT_GATEWAY_*`; `WhatsappService::ingestLogEntry` usa os metadados extras enviados pelo gateway.  
- Manual admin (`config/manual/whatsapp`) inclui a secao "Gateway WhatsApp Web" com passos de instalacao e execucao.  
- Proximos passos: preencher tokens compartilhados, rodar `npm install && npm start`, escanear o QR no card do modulo e validar envio/recebimento real; marcar como concluido apos os testes.

### 2025-12-10 - Redesign do atendimento WhatsApp
**Status:** pendente  
- `resources/views/whatsapp/index.php` reescrito com layout em tres colunas (filas, conversa, painel lateral), filtros "Minhas conversas", lembretes e concluidos, painel de tags/notas e cards extras para admins.  
- `WhatsappService` ganhou consultas para filas pessoais, lembretes, concluidos, notas internas e atualizacao de tags; novas rotas no `WhatsappController`/`Kernel`.  
- Editor de notas internas com mentions, formulario de tags do contato, painel de fila redesenhado e JS unificado para IA, pre-triagem, sandbox e gateway alternativo.  
- Proximos passos: validar o fluxo (assumir/soltar/atualizar fila), testar o gateway Node exibindo status/QR e garantir que o novo log de notas internas aparece no historico.

### 2025-12-11 - IA Copilot com base treinavel
**Status:** pendente  
- Migracao `2025_12_10_174500_create_copilot_knowledge_tables` cria `copilot_manuals`, `copilot_manual_chunks` e `copilot_training_samples` para manuais e conversas de treinamento.  
- Novos repositorios `CopilotManualRepository` e `CopilotTrainingSampleRepository` + metodos no `WhatsappService` para importar/deletar manuais, registrar amostras ao encerrar threads e consultar estatisticas.  
- `generateSuggestion` busca manuais/amostras relevantes, chama a API GitHub Copilot (`gpt-4o-mini`) com contexto do thread e faz fallback elegante se a chave nao estiver configurada.  
- Painel admin (`whatsapp/config`) ganhou cartao "Base de conhecimento IA" com lista de manuais, upload (.txt/.md), resumo das ultimas amostras e botoes de download/exclusao; JS suporta multipart.  
- Novas rotas `/whatsapp/copilot-manuals*` e botoes no UI; historico registra amostras automaticamente.  
- Proximos passos: rodar `php scripts/migrate.php`, enviar o primeiro manual `.txt`, validar sugestao IA em atendimento real e checar se alertas aparecem quando a API falhar.

### 2025-12-11 - Gateway alternativo + historico
**Status:** pendente  
- `services/whatsapp-web-gateway/src/gateway.js` reescrito para deduplicar mensagens, cachear contatos e enriquecer o payload enviado ao CRM (direcao, legenda, tipo, metadados de sessao).  
- Sincronismo de historico: ao detectar status `connected`, o gateway lista os ultimos chats, busca mensagens dentro de `HISTORY_LOOKBACK_MINUTES` e envia ao CRM com `meta.history=true`.  
- README explica como ajustar `HISTORY_*` e orientar o time a diferenciar mensagens importadas de eventos em tempo real.  
- Painel admin ganhou botao "Reiniciar sessao" (rota `/whatsapp/alt/gateway-reset`) que chama `/reset-session`, limpa cache local e forca novo QR para capturar conversas antes da migracao.  
- Proximos passos: reiniciar o servico (`npm run start`), observar logs "Sincronizacao de historico", validar no CRM se as mensagens chegam com flag `history` e ajustar `HISTORY_MAX_*` conforme a carga.

### 2025-12-11 - WhatsApp Web alternativo (testes hibridos)
**Status:** pendente  
- Gateway Node ganhou heartbeat + auto-reconexao (`GATEWAY_HEARTBEAT_SECONDS`, `GATEWAY_RECONNECT_SECONDS`), cache seguro de QR (`GATEWAY_QR_MAX_AGE_SECONDS`) e expos metricas completas via `/health` (ready, historico, ultimos dispatches e motivo do proximo reset).  
- `/qr` agora retorna JSON com `generated_at/expires_at`; o painel Chatboot mostra sessao, heartbeat, historico e alerta de erro, alem de painel de QR que atualiza sozinho a cada 20s e se oculta quando a sessao esta ativa.  
- Card ganhou botao "Iniciar gateway", que dispara `start-gateway.bat` (ou caminho definido em `WHATSAPP_ALT_GATEWAY_START_COMMAND`) direto no servidor para subir o Node; formulario "Carregar historico" chama `POST /history-sync` e exibe o resumo importado.  
- Resumo superior passa a contar o gateway como linha conectada quando o health reporta `ready=true`.  
- Proximos passos: executar `npm install && npm start` em `services/whatsapp-web-gateway/`, validar `http://localhost:4010/health`, abrir o card "WhatsApp Web alternativo", escanear o QR com o numero de testes e usar o formulario de historico para importar o intervalo desejado antes de migrar o numero verificado.

### 2025-12-11 - Gateway alternativo alinhado ao atendimento
**Status:** pendente  
- Threads que usam canal `alt:*` agora disparam mensagens diretamente pelo gateway Node; envios manuais deixaram de cair no provider Meta por engano.  
- O botao "Reabrir" nos cartoes de concluidos move a conversa para status `open`, fila de chegada e ja abre o atendimento correspondente.  
- O bloco "Como usar" reforca que todo este log deve permanecer em portugues para facilitar retomadas.  
- Proximos passos: subir o `start-gateway.bat`, validar envio/recebimento em tempo real e confirmar que o painel soma a linha extra quando o `/health` retorna `ready=true`.

### 2025-12-11 - Multi instancias do gateway alternativo
**Status:** pendente  
- Arquivo `config/whatsapp_alt_gateways.php` criado com a declaracao de multiplas instancias (rotulos, URLs, tokens e comandos) para permitir gateways isolados por numero de teste.  
- `WhatsappService` passou a resolver o slug da instancia dentro do `channel_thread_id`, reaproveitando o mesmo helper para envio, ingestao de webhooks e status summary (somando apenas instancias prontas).  
- `WhatsappAltController` agora recebe o parametro `instance` em todas as rotas (status/QR/start/reset/history/webhook) e dispara o script certo ao iniciar/reiniciar um gateway.  
- Painel admin e o JS standalone renderizam um card por instancia, com botoes proprios (Iniciar, Atualizar, Mostrar QR, Reiniciar sessao e Carregar historico) e formularios que enviam o slug correto.  
- Proximos passos: preencher os `.env` separados de cada gateway Node, ajustar os scripts `start-gateway-*.bat` e validar os testes de envio/recebimento em cada instancia antes de apontar numeros oficiais.

### 2025-12-12 - Limitador por linha + identificação visual
**Status:** pendente  
- Cada linha oficial recebeu campos para habilitar/desabilitar o limitador, configurar janela (segundos) e máximo de mensagens; formulário `whatsapp/config` e ações JS salvam/recuperam os valores e exibem o estado atual do limitador na lista de linhas.  
- `WhatsappService::sendMessage` bloqueia disparos quando o limite configurado é atingido, com mensagens amigáveis e armazenamento do contador na tabela `settings`; sanitização de payloads cobre os novos campos.  
- `WhatsappThreadRepository` passou a expor `line_display_phone` e `line_provider` em todas as consultas, e a UI de atendimento mostra chips “Linha: Nome · Telefone” ou “Gateway: SLUG” em cards, filas e cabeçalho da conversa para garantir que os atendentes saibam por qual número ou instância o contato chegou.  
- O helper de origem no `whatsapp/index.php` também agrupa conversas duplicadas por contato mostrando as labels/telefones corretos e identifica instâncias do gateway alternativo mesmo sem `line_id`.  
- Próximos passos: validar em produção o comportamento do limitador (especialmente nos casos de erro do provedor) e combinar com a equipe quais limites serão adotados por padrão antes de habilitar o recurso em todas as linhas.

### 2025-12-12 - Identificador de agentes no chat
**Status:** pendente  
### 2025-12-12 - Identificador de agentes no chat
**Status:** pendente  
- Migracao `2025_12_12_120500_add_chat_identifier_to_users.php` adiciona o campo `chat_identifier` na tabela `users`; o admin pode editar ou remover o prefixo de cada colaborador no bloco de colaboradores ativos via novo formulario dedicado.  
- Autenticacao por sessao/certificado e o repositorio de usuarios carregam o identificador, que agora acompanha o `AuthenticatedUser` e a lista de agentes disponiveis no modulo WhatsApp.  
- `WhatsappService::sendMessage` registra `actor_name` e `actor_identifier` no metadata das mensagens; ao renderizar a conversa, o front exibe automaticamente "IDENT - Nome" acima dos baloes de saida e substitui o rodape "Voce" pelo mesmo identificador.  
- Proximos passos: definir os codigos (ex.: AGR, SUP, VND) para todos os usuarios e testar o fluxo completo (enviar mensagem, nota interna etc.) para confirmar que o prefixo aparece tanto no painel principal quanto nas abas standalone.

### 2025-12-12 - Config WhatsApp (linhas + gateways)
**Status:** pendente  
- Tela "Linhas registradas" exibe o gateway alternativo vinculado a cada linha, com botao "Iniciar gateway" e indicador de status/cores (verde online, vermelho offline) reutilizando os mesmos endpoints do painel admin.  
- Formulario de linha ganhou o seletor "Modelo de integracao" (Meta/Dialog360/Sandbox/Alt) com presets para a API base, campo condicional para escolher a instancia do gateway e validacao dinamica de obrigatoriedade (token/Business ID apenas quando necessario).  
- Cartao "WhatsApp Web alternativo" passou a oferecer o formulario de historico com modos Todas/Intervalo/Ultimas horas, radios que exibem apenas os campos relevantes e JS que converte horas em minutos antes de enviar.  
- JS global agora monitora os gateways ligados a linhas (polling independente do painel), liga botoes de start inline e ajusta automaticamente o formulario conforme o modelo escolhido.  
- Proximos passos: validar em staging o acionamento do gateway direto pela lista de linhas, testar a sincronizacao de historico com cada modo e combinar limites padrao de lookback antes de liberar para o time.

### 2025-12-13 - Grupos no atendimento WhatsApp
**Status:** pendente  
- Gateway alternativo deixou de ignorar mensagens `isGroupMsg`: agora envia os grupos para o CRM com metadados (slug da instancia, nome do grupo e remetente do participante).  
- Migracao `2025_12_13_120000_add_group_columns_to_whatsapp_threads.php` criou os campos `chat_type`, `group_subject` e `group_metadata`; o repositorio/servico normalizam contatos `group:*`, filtram grupos das filas tradicionais e expõem um `groupThreadsForUser`.  
- A tela `whatsapp/index.php` ganhou o painel "Grupos" com badges dedicadas, chips "Grupo" nos cards/filas e agrupamento isolado (telefone interno `group:`), além do helper que evita misturar grupos e contatos comuns.  
- Ajustes auxiliares: `format_phone` retorna "Grupo WhatsApp" para prefixos `group:`, as listas de linhas deixam de contar grupos em `countByQueue` e o formulário admin continua igual.  
- Próximos passos: executar a nova migração, reiniciar o gateway Node (`npm start`) e validar se um grupo real aparece no painel, incluindo o chip "Grupo" e o contador específico.
### 2025-12-13 - Gateway alt (lab01) reiniciado
**Status:** em progresso  
- Finalizei processos zombie (Chrome + Node) que seguravam o `userDataDir`, removendo o `lockfile` e reabrindo o gateway lab01 em background via `cmd /c npm start`, o que voltou a disponibilizar QR/status pelo painel.  
- Validei que a view `resources/views/whatsapp/index.php` compila (`php -l`) e que o endpoint `/public/whatsapp?standalone=1` responde (302 para login), confirmando que o módulo volta a subir sem ParseError.  
- Pendência: autenticar como admin para revisar o novo painel de grupos e acompanhar os logs (`gateway-runtime.log`) enquanto um grupo real chega para confirmar chips/contadores.  
### 2025-12-13 - Gateway alt start script fallback
**Status:** concluído  
- Ajustei WhatsappAltController::gatewayStartScript para testar múltiplos caminhos (configurado, .env, diretórios dentro/fora do htdocs e nomes específicos como start-gateway-lab02.bat), além de detalhar na mensagem de erro quais caminhos foram tentados.  
- Adicionei o helper `resolveGatewayStartCandidate` que normaliza caminhos relativos e aceita tanto `C:\...` quanto shares UNC, evitando falsos negativos no `is_file`.  
- Com isso, o botão "Iniciar gateway" volta a acionar o .bat correto mesmo quando o app está rodando dentro do htdocs e os scripts ficam um nível acima.  
### 2025-12-13 - Linhas SafeGreen + limpeza inicial
**Status:** concluído  
- Conferi na tabela `whatsapp_lines` (database.sqlite) que “Fixo - SafeGreen” já está vinculado ao gateway `lab01` e “Movel- SafeGreen” ao gateway `lab02`, garantindo que cada botão de iniciar gateway acione a instância correta.  
- Zerei os cadastros de contatos, conversas e mensagens (`DELETE FROM whatsapp_contacts|threads|messages`) para começar os testes limpos antes de escanear novos QRs; as linhas permanecem intactas.  
- Agora basta iniciar cada gateway no painel, escanear o QR correspondente e os próximos logs/conversas começarão numa base vazia.  
### 2025-12-13 - Indicador de gateway ajustado
**Status:** concluído  
- Atualizei `resources/views/whatsapp/config.php` para distinguir entre "gateway inacessível" (offline), "gateway disponível aguardando QR" (amarelo) e "gateway pronto" (verde), usando os campos `ok`, `ready` e `status` retornados por `/health`.  
- O badge agora mostra textos amigáveis (ex.: “aguardando QR”, “pareando”, “telefone offline”) e só pinta em vermelho quando o serviço realmente não responde; quando o Node está de pé mas falta parear, o badge fica amarelo.  
- Acrescentei a classe `.is-warning` no CSS para esse estado intermediário, evitando falsos alertas enquanto o admin escaneia o QR.  
### 2025-12-13 - Contatos normalizados + manutencao dos gateways
**Status:** pendente  
- Resolver de contatos atualizado para testar variacoes com e sem DDI/nono digito antes de criar registros; rodei `php scripts/fix_contact_names.php` e alinhei 12 contatos que apareciam como "Safegreen..." para os nomes reais do CRM (ex.: Levi de Souza).  
- Matei processos zombie de Chrome/Node que bloqueavam o `userDataDir`, limpei `storage/whatsapp-web/*/sessions` travados e reiniciei `start-gateway-lab01.bat`/`start-gateway-lab02.bat`; cada instancia voltou a expor `/health` e `/qr`, com logs individuais em `storage/whatsapp-web/logs/gateway.log` e `.../lab02/logs/gateway.log`.  
- QR historico ainda falha apos o scan: precisamos repetir o teste capturando os logs do momento do pareamento e ajustar timeouts/cache de QR ou o modo headless para estabilizar.  
- Mesmo com o script de normalizacao, contatos novos continuam entrando com apelido generico; proximo passo e auditar quem esta inserindo registros sem passar pelo `ClientRepository::findByPhoneDigits` e garantir que a fila sempre tente conciliar com o cadastro de clientes.  
### 2025-12-13 - Scripts do gateway dentro do htdocs
**Status:** pendente  
- Copiei `services/whatsapp-web-gateway` do ambiente antigo (xampp-dwv) para `htdocs/services/` do novo XAMPP-DEV para manter tudo sob backup da nuvem.  
- Ajustei `config/whatsapp_alt_gateways.php` para definir `$projectRoot = dirname(__DIR__)`, garantindo que os `start-gateway*.bat` sejam resolvidos dentro do htdocs.  
- Proximo passo: clicar em "Iniciar gateway" para cada instancia e validar se o botao executa o .bat agora que os scripts estao no novo caminho; revisar os logs `storage/whatsapp-web/logs/*.log` para confirmar que o Node subiu corretamente.
### 2025-12-13 - Gateways ajustados no XAMPP-DEV
**Status:** pendente  
- Atualizei os `.env` (lab01/lab02/lab03) para usar `../../storage/...` agora que o serviço mora dentro de `htdocs/services`; o gateway voltou a abrir o SQLite no novo caminho.  
- Iniciei os scripts via `Start-Process` (`start-gateway.bat` porta 4010 e `start-gateway-lab02.bat` porta 4020); ambos respondem em `/health` (lab01 em estado `qr`, lab02 em `starting` aguardando QR).  
- Proximo passo: no painel admin clique em "Mostrar QR" para cada linha, escaneie com o aparelho correto e confirme que o status muda para `ready`; se precisar, acompanhe os logs em `storage/whatsapp-web/logs/` e `storage/whatsapp-web/lab02/logs/`.
### 2025-12-13 - QR alternativo estabilizado
**Status:** pendente  
- Ajustei o painel admin: o QR agora usa `image-rendering: pixelated`, fundo branco e botao “Abrir em nova aba” para escanear diretamente na tela cheia ou baixar o PNG (evita distorcao/espelhamento do modal).  
- O JS do gateway guarda o ultimo data URL e bloqueia o botao enquanto nao ha QR valido; tambem permite abrir/baixar o codigo mesmo que o iframe do CRM tenha algum filtro visual.  
- Copiei um exemplo real para `htdocs/qr-lab01.png` (gerado direto do gateway 4010) para validar que o arquivo de origem esta correto.  
- Proximo passo: testar no celular usando o novo botao “Abrir em nova aba”; se ainda nao ler, coletar um print do QR em tela cheia para comparar com `qr-lab01.png` e revisar a camera/iluminacao.

### 2025-12-13 - QR oficial + historico sincronizado
**Status:** pendente  
- `services/whatsapp-web-gateway/src/gateway.js` agora usa a biblioteca `qrcode` para redesenhar o QR em preto/branco com correção H, escala 8 e fundo branco, igual ao `web.whatsapp.com`; o callback `catchQR` passa o `urlCode` real e registra tentativas para debug.  
- Mantivemos o fallback para o PNG original do WPPConnect caso o `urlCode` não venha, então o painel sempre terá um data URL válido (inclusive para o botão "Abrir em nova aba").  
- Copiei `history/conversation-log.md` para `htdocs/history/` do novo XAMPP-DEV, garantindo que o resumo fique no mesmo diretório que o restante do projeto e continue servindo como checkpoint diário.  
- Próximos passos: reiniciar `start-gateway-lab01.bat`/`lab02.bat`, abrir "Mostrar QR" em cada linha e validar o scan com o celular; se ler sem erro, marcar a entrada como concluída.

### 2025-12-13 - Importação de histórico (timeout CRM)
**Status:** pendente  
- Detectei que o gateway já estava em `ready`, mas o health mostrava `chats_scanned=0` porque o auto-sync usa o limite padrão de 24h (`HISTORY_LOOKBACK_MINUTES=1440`).  
- Disparei `/history-sync` com 100 conversas/500 mensagens desde 2021, porém o CRM levou mais de 5s para gravar cada lote e o webhook estourou timeout.  
- Ajustei `config.js`/`crmClient.js` para ler `CRM_WEBHOOK_TIMEOUT_MS` (padrão 15s) e atualizei `.env`/`.env.example` para usar 20s no ambiente atual.  
- Próximos passos: reiniciar os scripts `start-gateway*.bat` para aplicar o novo timeout, voltar à tela “Carregar histórico” e repetir o sync (de preferência em blocos menores, ex.: 30 conversas x 200 msgs) até preencher o backlog.

### 2025-12-13 - Lote extra de histórico WhatsApp
**Status:** pendente  
- Reiniciei os gateways lab01 (porta 4010) e lab02 (porta 4020) para aplicar o novo `CRM_WEBHOOK_TIMEOUT_MS=20000` nos arquivos `.env`, `.env.lab02` e `.env.example`.  
- Para a linha Fixo (lab01) rodei 4 ciclos grandes via `/history-sync` (`max_chats` 60/100, `max_messages` 400/500, intervalo desde 01/01/2021). Somando os logs: 18 + 60 + 99 + 15 + 99 = **291 mensagens** encaminhadas em 189 conversas.  
- Para a linha Móvel (lab02) rodei 3 ciclos (15×40 por segurança e 100×500). O log mostra 59 + 131 + 1 + 45 + 134 = **370 mensagens** importadas; o último lote (99 chats) levou ~62s, mas completou sem timeout.  
- Ainda há chats antigos que o WPP não expõe de primeira; precisa repetir o `/history-sync` (ou usar o botão "Carregar histórico") periodicamente, variando os parâmetros por grupo/intervalo até zerar o backlog.  
- Próximos passos: acompanhar as filas no CRM para confirmar se as conversas importadas apareceram e, se necessário, repetir o processo focando nos grupos (lab02) que retornaram "WAPI is not defined".

### 2025-12-13 - Figurinhas rápidas no atendimento WhatsApp
**Status:** pendente  
- Adicionado `config/whatsapp_templates.php` para listar figurinhas/anexos corporativos; o serviço resolve caminhos relativos dentro de `public/` e só exibe arquivos existentes.  
- `WhatsappService::sendMessage` agora aceita `template_kind/template_key` e injeta a mídia direto no gateway alternativo; o controller e a tela standalone enviam esses campos.  
- Na UI, o botão “Enviar figurinha” abre um modal com as artes cadastradas, aplica pré-visualização e limpa anexos conflitantes; o recurso fica habilitado apenas para conversas ligadas ao gateway alternativo.  
- Próximos passos: criar o painel de comunicados rápidos usando o mesmo conceito de templates e adicionar o botão de chamada/registro de áudio conforme combinado.

### 2025-12-13 - Comunicados rápidos no WhatsApp
**Status:** concluído  
- `WhatsappService` agora decide automaticamente entre envio imediato ou assíncrono, salvando os `thread_ids`, respeitando o limitador anti-banimento por linha e oferecendo os métodos `processBroadcast(s)` para reprocessar jobs.  
- Implementado worker CLI (`scripts/whatsapp/process_broadcast.php`) disparado pelo painel ou manualmente (`--broadcast` / `--limit`) para processar filas em background.  
- Quando o envio fica em fila o painel mostra o aviso adequado e atualiza o histórico em tempo real; o feedback exibe estatísticas ou informa que o job seguirá em background.  
- Histórico/JS ajustados para carregar os últimos comunicados ao abrir o painel e reutilizar os mesmos dados após cada disparo.

### 2025-12-13 - Sugestões imediatas para o módulo de WhatsApp
**Status:** pendente  
- Novos requisitos priorizados: botões de "Enviar figurinha" usando a biblioteca de templates existente, painel de "Comunicados rápidos" com seleção de filas e respeito ao limitador e um botão "Chamar" que dispara `adb shell am start ... CALL` (ou `callto:` no desktop) registrando a ligação no CRM.  
- Pesquisa global deve evoluir para buscar também no conteúdo das últimas 200 mensagens carregadas no front, além de nome/telefone.  
- Dependência: concluir a importação em massa do histórico para liberar o time a validar os recursos acima; os testes de comandos ADB e do primeiro lote de sincronização começaram e aguardam confirmação no CRM.

### 2025-12-13 - Config + atendimento alinhados ao hub
**Status:** pendente  
- `resources/views/whatsapp/config.php` foi reescrita no mesmo layout do `/config` (menu lateral, painéis e textos legíveis), mantendo todas as ações/JS existentes e eliminando o erro `null + array` ao carregar os rótulos de filas.  
- As abas do atendimento (`resources/views/whatsapp/index.php`) agora exibem o contador em uma segunda linha e o badge `+N` logo abaixo, além do cabeçalho do contato mostrar apenas Nome › `+novas` › Telefone, com o botão de CRM reaproveitando o `client_id`.  
- `WhatsappAltController::callGateway` aplica um timeout dedicado para `/history-sync` (mínimo 30s ou `WHATSAPP_ALT_GATEWAY_HISTORY_TIMEOUT`), evitando os `Operation timed out after 5s`.  
- Próximos passos: validar o visual do `whatsapp/config?standalone=1` comparando com `/config`, conferir o ajuste das abas no front real e repetir um `Carregar histórico` longo para garantir que o novo timeout absorve a fila de mensagens.
### 2025-12-13 - Atualiza‡Æo autom tica das filas WhatsApp
**Status:** pendente  
- Endpoint `GET /whatsapp/panel-refresh` monta o snapshot das filas com agrupamento por contato/grupo e reutiliza o novo helper `resources/views/whatsapp/partials/thread_helpers.php` para renderizar os cards, mantendo o mesmo HTML do atendimento.  
- O front inicia um loop a cada 12s (pausando quando a aba fica oculta) que busca os pain‚is, atualiza abas, contadores e listas sem recarregar a p gina e reaplica o filtro da busca local.  
- Estrutura da lista ganhou `data-panel-count`, `data-panel-list` e `data-panel-empty`, o que permite substituir apenas o conte£do relevante (mensagens vazias e scroll) sem quebrar o layout.  
- BotÄes "Assumir" e "Reabrir" agora usam delega‡Æo de eventos, garantindo que as a‡äes continuem funcionais nos cards inseridos pelo auto-refresh.  
- Pr¢ximos passos: validar em LAB02 se as novas mensagens aparecem nas filas em at‚ 2 ciclos e medir o impacto do pooling para decidir se podemos reduzir o intervalo para 8s.
### 2025-12-13 - Correções de mensagens editadas
**Status:** pendente  
- `WhatsappMessageRepository` ganhou `updateIncomingMessage`, permitindo regravar conteúdo/meta de mensagens já registradas.  
- `WhatsappService::registerIncomingMessage` agora identifica quando o provedor reenviou um `meta_message_id` existente: eventos vindos do histórico são ignorados, e edições reais atualizam o texto, mídia e preview do thread sem duplicar registros.  
- As conversas abertas passam a exibir o texto corrigido assim que o atendimento é recarregado (ou quando um novo evento chega).  
- Próximos passos: propagar uma indicação visual de “editado” no front e avaliar se conseguimos atualizar o balão em tempo real sem recarregar o thread.
### 2025-12-14 - Colisões de channel_thread_id no gateway alternativo
**Status:** pendente  
- `WhatsappService::registerIncomingMessage` agora envolve a atualização do `channel_thread_id` em um try/catch; se o valor já estiver em uso, buscamos o thread existente com o mesmo canal e reaproveitamos esses dados em vez de explodir com erro SQL.  
- Quando ocorre a colisão também sincronizamos o contato do thread recuperado antes de tocar `last_interaction_at`, mantendo os ACKs e as mensagens de grupos consistentes.  
- Com isso os webhooks do gateway (especialmente do lab02) deixam de responder 422/500 por conta da `UNIQUE constraint failed: whatsapp_threads.channel_thread_id`.  
- Próximos passos: reler um lote de histórico/ACKs no lab02 e acompanhar `storage/logs/alerts.log` para garantir que os eventos voltam a ser aceitos em tempo real.
### 2025-12-14 - Indicador visual de clientes
**Status:** pendente  
- Reativei o ícone de cliente nas listas de filas adicionando a classe `.wa-client-icon` e exibindo o selo sempre que o thread possui `contact_client_id`.  
- O helper usado pelo auto-refresh e o `renderThreadCard` principal agora injetam o mesmo SVG e adicionam "cliente" ao índice de busca para permitir filtrar por esse termo.  
- CSS incorporado no `whatsapp/index.php` mantém o visual verde original sem depender de bibliotecas externas.  
- Pr7ximos passos: validar no front que os contatos vinculados ao CRM voltaram a mostrar o selo e ajustar o layout caso haja telas adicionais que reutilizem as cartas (ex.: monitor standalone).
### 2025-12-14 - Painel compact chaves respeta opcoes
**Status:** pendente  
- Ajustei `resources/views/whatsapp/partials/thread_helpers.php` para só renderizar a prévia quando `show_preview` estiver habilitado, alinhando o HTML retornado pelo `/whatsapp/panel-refresh` com o layout compacto usado no carregamento inicial.  
- Como o auto-refresh agora respeita os mesmos flags (`show_preview`, `show_queue`, etc.), as filas não trocam de formato após alguns segundos e permanecem naquele bloco simples (nome, contador, telefone e linha).  
- Mantive o selo verde de clientes no helper para que o comportamento fique idêntico ao template principal.  
- Próximos passos: recarregar o atendimento, deixar o auto-refresh rodar e confirmar que as cartas não voltam a exibir o trecho de prévia/rodapé automaticamente.
### 2025-12-14 - Botão "Cliente" fixado
**Status:** pendente  
- Removi o clique direto no link e passei a usar um popover dedicado: qualquer `.wa-client-button` (inclusive os inseridos via auto-refresh) agora dispara `openClientPopover` por delegação de eventos e respeita `data-client-preview`.  
- Fechar o cartão voltou a ser intuitivo (overlay, botão ou tecla ESC) e a ação não altera mais o layout das colunas/abas do atendimento.  
- O helper `parseClientButtonPayload` trata erros de JSON e evita que cliques sem payload derrubem o app.  
- Próximos passos: validar no ambiente real que o botão abre o painel com nome/documento/status e que o link interno “Ver ficha no CRM” continua funcionando.
### 2025-12-14 - Layout único das filas
**Status:** pendente  
- `resources/views/whatsapp/index.php` agora respeita as mesmas flags (`show_preview`, `show_queue`, `show_line`, etc.) usadas no snapshot automático, evitando que a coluna "Entrada" mude de formato após o auto-refresh.  
- O bloco de prévia só aparece quando o painel habilitar `show_preview`, os chips de fila/agendamento entraram no mesmo esquema de metas e o selo da linha só é exibido quando `show_line` for verdadeiro.  
- Com isso, o HTML inicial bate com o gerado em `/whatsapp/panel-refresh`, eliminando o efeito visual de "duas telas" sobrepostas.  
- Próximos passos: atualizar o atendimento com `?panel=entrada&thread=...` e confirmar que, após alguns ciclos, a lista continua no layout compacto esperado (nome, contador, telefone e linha).
### 2025-12-14 - Painel Entrada estável
**Status:** pendente  
- `resources/views/whatsapp/partials/thread_helpers.php` agora renderiza o mesmo markup compacto (`.wa-mini-thread`) usado no carregamento inicial, incluindo o selo de cliente e botões “Assumir”/“Reabrir”. Assim, tanto o primeiro load quanto os ciclos do `/whatsapp/panel-refresh` usam a mesma estrutura e CSS.  
- Adicionei as classes `.wa-mini-line`, `.wa-mini-preview` e `.wa-mini-meta` no CSS principal (`resources/views/whatsapp/index.php`) para manter o visual consistente sem saltos quando o auto-refresh entra em ação.  
- Com essa unificação, os botões continuam funcionando (delegação global) e a coluna "Entrada" não alterna mais para o card antigo com prévia longa.  
- Próximos passos: atualizar a tela `?panel=entrada` e validar após alguns ciclos que o layout se mantém uniforme e os botões operam normalmente.

### 2025-12-14 - Entrada compacta sem chips duplicados
**Status:** pendente  
- `resources/views/whatsapp/index.php` passou a mostrar a identificação da linha logo abaixo do nome (mesmo markup do helper), removendo o chip verde que abria o próprio thread e evitando que o cartão mude de formato após o auto-refresh.  
- O CSS antigo (`.wa-mini-lines`/`.wa-line-chip`) foi eliminado para impedir estilos órfãos e garantir que só exista um layout possível para a coluna de Entrada.  
- Próximos passos: recarregar `whatsapp?panel=entrada&standalone=1` e confirmar que os botões “Assumir/Cliente” seguem operacionais e que não surgem mais botões verdes apontando para o mesmo thread.

### 2025-12-14 - Notificações de novas mensagens
**Status:** pendente  
- O painel ganhou a barra "Notificações" com botões para habilitar/desabilitar som e pop-up e um seletor de pausa (0/1/2/5 min). As preferências são persistidas em `localStorage`, e o layout standalone exibe o novo bloco logo abaixo da busca.  
- Script principal monitora `/whatsapp/panel-refresh` via `meta` retornada pelo controller; um snapshot guarda o último unread e dispara som/pop apenas quando o contador aumenta. Para o thread ativo, o alerta só toca após o tempo configurado sem respostas e usa os dados reais do balão (`pollThreadMessages`).  
- O som usa `speechSynthesis` ("Temos uma nova mensagem...") com fallback em Web Audio, e o pop-up é um toast clicável que abre o atendimento correspondente. O toast mostra linha, nome e trecho da mensagem com sanitização em `escapeHtml`.  
- `registerIncomingMessage` agora limpa `assigned_user_id` ao reabrir um contato que estava em "Concluídos", garantindo que o cliente volte para a fila de chegada automaticamente.  
- Próximos passos: validar no LAB02 se o som/pop disparam apenas para mensagens novas, testar o tempo de silêncio configurável e confirmar que toasts/toggles continuam funcionando após recarregar a página.

### 2025-12-14 - Notificações globais unificadas
**Status:** pendente  
- Usuário reportou que os botões Som/Popup não executam nenhuma ação (mesmo logado como admin); precisamos checar delegação de eventos, classes `.is-active` e persistência imediata.  
- Configuração deve ser única para todas as filas (Entrada, Atendimento, Parceiros, Lembrete, Agendamento); atualmente só a coluna Entrada respeita o estado.  
- É necessário permitir a escolha de pelo menos um arquivo de áudio (ex.: upload ou seleção da biblioteca interna) e tocar esse som a cada mensagem recebida, em vez de usar apenas síntese de voz.  
- Toggles devem funcionar independente do painel; também foi solicitado que o mesmo conjunto de preferências controle notificações sonoras/popup em qualquer aba e que exista indicação clara de ativo/inativo.  
- Próximos passos: revisar `wa-notify-bar` no `whatsapp/index.php`, garantir que `resources/views/whatsapp/script.php` aplique o novo estado global, adicionar seletor de som + player de teste e revalidar recebimento em LAB02 após as correções.  
- Barra de notificações agora fica oculta atrás de um botão (engrenagem) e abre sob demanda; resumo rápido mostra se Som/Popup estão ativos.  
- Usuário consegue escolher entre voz, beep ou áudio personalizado (upload até 1 MB salvo em `localStorage`), testar o som e limpar o arquivo; controles funcionam para todas as filas e persistem localmente.  
- JS ajustado para remover variáveis duplicadas, religar os toggles, tocar o arquivo customizado e exibir mensagem de feedback quando não houver áudio selecionado; `php -l` confirma ausência de erros.  
- Ainda falta validar no navegador real se o áudio personalizado toca com o dispositivo LAB02 recebendo mensagens e, se necessário, ajustar limites/tipos aceitos para áudios maiores.

### 2025-12-14 - Backup completo do WhatsApp
**Status:** pendente  
- Painel `whatsapp/config` ganhou a aba “Backups” com dois cartões: um POST simples gera o ZIP (`whatsapp-backup-AAAAMMDD-HHMMSS.zip`) e outro formulário envia o arquivo para restauração.  
- `WhatsappService` implementa `generateWhatsappBackup()` e `restoreWhatsappBackup()` serializando tabelas `whatsapp_*` (linhas, contatos, threads, mensagens, permissões e comunicados) e incluindo `storage/whatsapp-media/` no ZIP; restauração desliga FKs, limpa as tabelas e copia a pasta de mídia antes de reativar.  
- Novos endpoints `/whatsapp/backup/export` e `/whatsapp/backup/import` na controller; exporta via `BinaryFileResponse` e a importação responde JSON (usado pelo JS que mostra progresso/estatísticas).  
- JS (`resources/views/whatsapp/script.php`) trata o envio do backup e exibe feedback inline; é preciso validar em produção o download/restauração em um ambiente limpo antes de marcar como concluído.

### 2025-12-14 - Layout standalone desalinhado
**Status:** pendente  
- Pagina `whatsapp?panel=entrada&channel=alt` quebrou após a última alteração porque havia um `</div>` sobrando logo depois do bloco `.wa-notify-bar`, fechando o `wa-layout` antes da hora e jogando `wa-panels` para fora da coluna.  
- Removi o fechamento extra (linha ~1759 de `resources/views/whatsapp/index.php`) e validei com `php -l` para garantir que o arquivo compila.  
- Próximo passo: recarregar a tela standalone e confirmar que a coluna de Entrada volta a ocupar o primeiro terço da grade e que os botões reassumem/cliente funcionam normalmente; se ainda houver desalinhamento, revisar CSS `.wa-layout` em conjunto com o navegador.

### 2025-12-17 - Telefones e concluidos no WhatsApp
**Status:** pendente  
- Cartões e helper de threads agora exibem sempre o número de origem (incoming) e o vínculo de cliente passou a ser apenas por telefone, eliminando colisões por nome.  
- `WhatsappService::applyContactDisplayMetadata` e `selectIncomingPhone` priorizam o número recebido, aceitam 10-15 dígitos e usam o canal alternativo como fallback; criação de contatos normaliza o telefone.  
- Rodamos `scripts/move_all_inbox_to_concluidos.php` para mover 94 threads da entrada para Concluídos (status closed) e limpamos assign/schedule; para enxergar todos aumentamos o limite de carga de concluidos de 25 para 200.  
- Auto-arquivo de threads inativas >30 dias ativado em `archiveInactiveThreads()` dentro do fluxo de `queueThreadsForUser`, enviando para `concluidos` com status `closed`.  
- Próximos passos: validar na UI que Concluídos lista o lote completo (subir mais o limite ou paginar se faltar), garantir que novas mensagens reabram threads arquivados automaticamente e monitorar a exibição do telefone recebido nas próximas conversas.
