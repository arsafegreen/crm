
## Sincronizacao manual de historico

O CRM tambem pode acionar `POST /history-sync` (com `X-Gateway-Token`) definindo `since`/`until`, `lookback_minutes`, `max_chats` e `max_messages`.
O painel "WhatsApp Web alternativo" expoe um formulario para isso e mostra quantas conversas/mensagens foram reenviadas ao CRM.
