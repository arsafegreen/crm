# Chat Interno — Arquitetura Proposta

## Objetivos
- Mensagens em tempo real entre usuários ativos (1:1 inicialmente).
- Persistência completa para auditoria; apenas administradores podem limpar históricos.
- Infraestrutura extensível para salas/grupos no futuro.

## Modelagem de Dados

### Tabela `chat_threads`
| Campo | Tipo | Descrição |
| --- | --- | --- |
| id | INTEGER PK | Identificador da conversa |
| type | TEXT | `direct` (1:1) ou `group` |
| subject | TEXT NULL | Nome da sala (para grupos) |
| created_by | INTEGER | Usuário que iniciou |
| last_message_id | INTEGER NULL | FK para última mensagem |
| last_message_at | INTEGER NULL | Timestamp da última atividade |
| created_at / updated_at | INTEGER | Timestamps padrão |

### Tabela `chat_participants`
| Campo | Tipo |
| --- | --- |
| id | INTEGER PK |
| thread_id | INTEGER FK -> chat_threads |
| user_id | INTEGER FK -> users |
| role | TEXT (`member`, `owner`, `admin`) |
| last_read_message_id | INTEGER NULL |
| last_read_at | INTEGER NULL |
| created_at / updated_at | INTEGER |

### Tabela `chat_messages`
| Campo | Tipo |
| --- | --- |
| id | INTEGER PK |
| thread_id | INTEGER FK -> chat_threads |
| author_id | INTEGER FK -> users |
| body | TEXT | Conteúdo (Markdown simples) |
| attachment_path | TEXT NULL |
| attachment_name | TEXT NULL |
| is_system | INTEGER(0/1) |
| created_at | INTEGER |
| updated_at | INTEGER |
| deleted_at | INTEGER NULL |

### Tabela `chat_message_purges`
| Campo | Tipo |
| --- | --- |
| id | INTEGER PK |
| admin_id | INTEGER FK -> users |
| executed_at | INTEGER |
| cutoff_timestamp | INTEGER | Limite aplicado |
| rows_deleted | INTEGER |

## Fluxos Principais

1. **Criar/Iniciar conversa:**
   - Usuário A seleciona “Novo chat”, escolhe Usuário B.
   - Backend procura thread `direct` existente com ambos; se não houver, cria `chat_threads` + 2 entradas em `chat_participants`.

2. **Enviar mensagem:**
   - POST `/chat/threads/{id}/messages` com `body` (e opcionalmente arquivo).
   - Serviço grava em `chat_messages`, atualiza `chat_threads.last_message_*`, envia evento em tempo real.

3. **Listar conversas:**
   - GET `/chat/threads` retorna lista ordenada por `last_message_at`, incluindo preview e contagem não-lida baseado em `last_read_message_id` de cada participante.

4. **Ler mensagens:**
   - GET `/chat/threads/{id}/messages?before|after` paginado.
   - Ao abrir thread, serviço atualiza `chat_participants.last_read_*` e emite evento de leitura.

5. **Tempo real:**
   - WebSocket dedicado `/ws/chat`. Autenticação via token de sessão (JWT/nonce CSRF).
   - Eventos: `message.sent`, `message.read`, `thread.updated`, `typing`, `presence`.
   - Infra: Ratchet/Swoole + Redis pub/sub para broadcast; fallback SSE/polling se WS indisponível.

6. **Retenção/Admin:**
   - Painel admin com data picker para “Excluir mensagens antes de X”.
   - Endpoint POST `/admin/chat/purge` exige permissão. Serviço move registros para log (`chat_message_purges`) e aplica `DELETE` ou `UPDATE deleted_at`.
   - Opcional: CRON diário que alerta admins sobre threads antigas.

## Considerações de Segurança
- Middleware garante que usuário só acesse threads onde participa.
- Mensagens excluídas logicamente (`deleted_at`), com opção de remoção permanente apenas via admin.
- Uploads verificados (mime/size) e armazenados em `storage/chat_uploads` com links assinados.
- Rate limiting por usuário para envio de mensagens.
- Logs de auditoria para purges e eventos administrativos.

## Etapas de Implementação
1. Migrations + models/repositórios para tabelas acima.
2. Serviços/chat controller REST + autenticação WS.
3. Worker WebSocket (Ratchet) integrado com Redis para broadcast.
4. UI frontend (Painel lateral + janela de conversa) usando JS modular (Livewire/Vue/React ou vanilla com fetch + WS).
5. Painel admin para retenção/limpeza + logs.

Com essa fundação, podemos evoluir depois para grupos, busca global, notificações push, etc.
