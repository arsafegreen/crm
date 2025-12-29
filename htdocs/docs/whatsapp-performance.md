# WhatsApp (standalone=1, channel=alt) – Guia completo de desempenho, regras e estado atual

## Visão geral e escopo
- Página/endpoint: `/public/whatsapp?standalone=1&channel=alt`
- Front-end principal: `resources/views/whatsapp/script.php` (lógica, cache, prefetch, polling) + `resources/views/whatsapp/index.php` e `resources/views/whatsapp/partials/thread_helpers.php` (markup e CSS dos cards/painéis)
- Backend: `app/Controllers/WhatsappController.php` (panelRefresh, pollThread) e helpers em `resources/views/whatsapp/partials/thread_helpers.php`
- Objetivo: abrir threads rapidamente (pintura imediata), minimizar TTFB e payloads, evitar requisições redundantes; histórico completo permanece no servidor.

## Funcionalidades implementadas
- **Ordenação de threads**: não lidas primeiro; mais recentes depois; grupos sinalizados.
- **Timestamp visível**: chegada antes de “Assumir”, formato `dd/mm/aa hh:mm`, largura fixa, sem quebra de linha.
- **Ações em linha**: botões Cliente / Assumir / Reabrir alinhados em uma única linha; gap reduzido.
- **Refresh de painéis**: panelRefresh troca HTML da lista, atualiza contagens/unread e registra meta para notificações.
- **SWR de painéis e cache local**: snapshot do painel fica em sessionStorage e é pintado antes do fetch; a resposta fresca revalida e persiste.
- **Prefetch assistido por visibilidade**: IntersectionObserver pré-carrega threads que entram no viewport e aborta prefetch ao trocar de painel.
- **Suspensão de polls em aba oculta**: loops de painel/poll são pausados via `visibilitychange` e retomam ao voltar o foco.
- **Payload estruturado nos painéis**: `panel-refresh` envia apenas itens estruturados (id, fila, unread, timestamps, rótulos); HTML do cartão não é mais renderizado pelo backend.
- **Prefetch de threads visíveis**: `THREAD_PREFETCH_LIMIT` threads pré-carregadas (poll com `prefetch=1`) antes do clique.
- **Cache de mensagens no cliente (memória + sessionStorage)**:
  - Prefixos/chaves: `MESSAGE_CACHE_PREFIX`, `MESSAGE_CACHE_INDEX_KEY`.
  - Limites: `MESSAGE_CACHE_LIMIT=20` threads; `MESSAGE_CACHE_TTL_MS=5 min`; `MESSAGE_CACHE_MAX_MESSAGES=120` msgs/thread.
  - **Hydrate**: ao abrir thread, renderiza do cache (se existir) e ajusta `last_message_id` antes do primeiro poll.
  - **Polling delta-only**: busca apenas novas mensagens a partir de `last_message_id`; mensagens recebidas vão para o cache.
  - **Prefetch -> cache**: prefetch grava no mesmo cache, permitindo abertura instantânea.
- **Paginação de histórico (“Carregar mais”)**: botão no topo usa `before_id/limit` para buscar mensagens antigas, faz prepend mantendo o scroll estável e esconde o botão quando não há mais páginas.
- **Controle de unread**: badges limpos ao abrir e ao receber delta; `threadSnapshots` sincronizado.
- **Cancelamentos**: `AbortController` no poll; `panelRefresh` aborta em `visibilitychange`; timers limpos quando a aba fica oculta.
- **Auto-arquivo**: threads inativas há 30+ dias são marcadas `closed`, movidas para fila `concluidos`, limpam atribuição e recebem `closed_at` (executado na entrada de `queueThreadsForUser`).

## Constantes e parâmetros (front)
- `THREAD_POLL_INTERVAL = 5000` ms
- `PANEL_REFRESH_INTERVAL = 12000` ms
- `THREAD_PREFETCH_LIMIT = 4`
- `THREAD_HISTORY_PAGE_SIZE = 40` (páginas do “Carregar mais”)
- `MESSAGE_CACHE_TTL_MS = 5 * 60 * 1000` (5 min)
- `MESSAGE_CACHE_LIMIT = 20` threads em cache
- `MESSAGE_CACHE_MAX_MESSAGES = 120` mensagens por thread

## Fluxo rápido ao abrir uma thread
1) **Hydrate do cache**: lê sessionStorage/memória; render imediato das mensagens recentes; ajusta `last_message_id` local.
2) **Poll delta**: inicia polling usando `last_message_id` para buscar só o delta.
3) **Prefetch assistido**: se thread foi pré-carregada, o cache já está quente.
4) **Merge**: mensagens novas são renderizadas, o scroll desce e o cache é atualizado.

## Regras para manter desempenho
1) Sempre reutilizar cache ao abrir; não limpar sem necessidade.
2) Delta-only: polls/prefetch devem respeitar `last_message_id`; evitar trazer histórico completo.
3) Payload enxuto: não enviar mídia/blobs no primeiro fetch; carregar anexos de forma lazy após a pintura.
4) Cards leves: manter apenas campos essenciais (nome, telefone, timestamp, unread, Cliente, Assumir/Reabrir, chips curtos).
5) Histórico antigo via paginação: usar `before_id/limit` em vez de full history (UI “Carregar mais” pendente).
6) Cancelar requisições ao trocar de thread ou ao ocultar a aba; evitar polls paralelos.
7) Panel refresh enxuto: meta + HTML simples; evitar campos volumosos repetidos.
8) Servidor com gzip/br e keep-alive/HTTP2 para JSON/HTML.
9) Banco com índices em `thread_id`, `sent_at/created_at`, `unread`; evitar `SELECT *` e cargas de blobs.

## Pendências e próximos passos
- Instrumentar métricas: TTFB, tamanho de payload, tempo de render, taxa de cache hit/miss.
- Consolidar helpers de data/ordenação para evitar duplicidade entre `index.php` e `thread_helpers.php`.
- Avaliar SSE/WebSocket para reduzir polling (avaliar custo/complexidade vs. ganho).

## Pendências novas (melhorias propostas)
- Mídia sempre lazy: poll/prefetch traz só metadados/URL; fetch da mídia só quando necessário.
- Batching de ações de fila: acumular moves rápidos e enviar em lote para reduzir writes concorrentes.
- Beacon ao sair/ocultar: enviar `last_message_id`/painel ativo via `navigator.sendBeacon` para calibrar deltas e contagens.
- Métricas no cliente: coletar TTFB/render/payload/cache hit-miss e enviar via endpoint leve.
- HTTP: garantir gzip/br e cache-control curto para JS/CSS e respostas estáticas do WhatsApp.
- Banco: revisar índices (`whatsapp_messages(thread_id, id)`, `(thread_id, sent_at)`, `whatsapp_threads(queue, status, last_message_at)`, `assigned_user_id`) e evitar `SELECT *`.
- Notificação de nova mensagem via SSE (opcional): usar SSE só para eventos, mantendo polling para conteúdo.

## Pontos de alteração
- **Cache/prefetch/poll/hydrate**: `resources/views/whatsapp/script.php` (helpers `MESSAGE_CACHE_*`, `pollThreadMessages`, `prefetchThreadMessages`, `hydrateThreadFromCache`).
- **Layout de cards**: `resources/views/whatsapp/index.php`; `resources/views/whatsapp/partials/thread_helpers.php` (timestamp, linha única Cliente/Assumir, gaps, chips).
- **Backend refresh/poll**: `app/Controllers/WhatsappController.php` + helpers em `resources/views/whatsapp/partials/thread_helpers.php` (meta, ordenação, campos enviados).

## Checklist antes de mudar qualquer coisa
- Novo campo? Precisa ir para meta (panel refresh) ou só render? Evitar duplicar em HTML + JSON se não for necessário.
- Busca de mensagens? Respeitar `last_message_id`; manter limites/TTL do cache; não trazer histórico inteiro.
- CSS/layout? Preservar linha única timestamp + Cliente + Assumir; manter gap/width do horário.
- Queries? Garantir índices (thread_id, sent_at/created_at, unread) e evitar full scan/blobs.
- Prefetch/cache? Manter gravação no cache; não aumentar TTL/limites sem motivo para não inflar memória.

## Observações finais
- Histórico completo continua no servidor; o cache local é apenas um trecho recente para acelerar reabertura.
- SessionStorage é por aba (isolado); expira por TTL e LRU (10 threads, 10 min).
- AbortControllers já evitam requisições penduradas; visibilidade da aba pausa refresh/poll.
