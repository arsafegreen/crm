# Marketing – Importador de Contatos com Dedupe

## 1. Objetivo
Disponibilizar um fluxo confiável para subir contatos em massa para as listas de audiência, com deduplicação automática por e-mail, aplicação de consentimento e registro em `audience_list_contacts`. O entregável cobre a meta da Semana 2 do cronograma: "Cadastro/listas/segmentos + importador com dedupe".

## 2. Escopo
- Upload CSV direto pela tela de listas (`/marketing/lists`).
- Cada importação é ligada a uma lista de destino obrigatória (para segmentação inicial).
- Validação linha a linha com feedback agregado: processados, novos contatos, contatos atualizados, erros e duplicidades dentro do arquivo.
- Dedupe global por e-mail (case insensitive) aproveitando `marketing_contacts.email` (índice único) e registro incremental de atributos.
- Reuso dos repositórios existentes (`MarketingContactRepository`, `AudienceListRepository`, `ContactAttributeRepository`).
- Registro opcional de tags, atributos livres e status de consentimento.

## 3. Formato do CSV
| Coluna | Obrigatório? | Descrição |
| ------ | ------------- | ---------- |
| `email` | **Sim** | Identificador único, usado para dedupe. Formato validado por `filter_var`. |
| `first_name` | Não | Nome de exibição. |
| `last_name` | Não | Sobrenome. |
| `phone` | Não | Normalizado via `digits_only`. |
| `tags` | Não | Lista separada por `;`. Mesclada ao campo `tags` existente (sem duplicar). |
| `consent_status` | Não | `confirmed`, `pending` ou `opted_out`. Se vazio, assume `pending`.
| `consent_source` | Não | Texto livre (ex.: "evento_xyz").
| `consent_at` | Não | Data no padrão `YYYY-MM-DD`. Se vazio, usa `now()` quando status `confirmed`.
| `custom.*` | Não | Quaisquer colunas com prefixo `custom.` viram atributos dinâmicos (`marketing_contact_attributes`). Ex.: `custom.cnae`, `custom.lead_score`.

> Linhas com e-mail vazio ou inválido são descartadas e registradas nos erros do lote.

## 4. Fluxo de processamento
1. **Upload**: novo formulário em `marketing/lists/{id}/import` (GET) com explicação do formato + input file. POST envia `list_id`, `source_label` (opcional) e arquivo.
2. **Parser**: service `MarketingContactImportService` lê CSV utilizando `SplFileObject`, respeitando cabeçalho e suportando arquivos até ~5MB (mesmo chunking da Base RFB, se necessário).
3. **Normalização**: cada registro gera payload
   ```php
   [
       'email' => strtolower(trim($row['email'])),
       'first_name' => trim($row['first_name'] ?? ''),
       'last_name' => trim($row['last_name'] ?? ''),
       'phone' => digits_only($row['phone'] ?? ''),
       'tags' => $this->mergeTags($existingTags, $row['tags'] ?? ''),
       'metadata' => json_encode([...])
   ]
   ```
4. **Deduplicação**:
   - Busca contato existente por e-mail via `MarketingContactRepository::findByEmail`.
   - Se não existir, cria contato com `status = active` e `consent_status` conforme payload.
   - Se já existir, atualiza apenas campos enviados (não sobrescreve com vazio) e mantém contadores (bounce etc).
5. **Consentimento**:
   - Para `consent_status = confirmed`, invoca `recordConsent` com `consent_source`.
   - Para `opted_out`, chama `markOptOut` e armazena `suppression_reason`.
6. **Atributos customizados**: para cada coluna `custom.*`, grava/atualiza em `marketing_contact_attributes` com `value_type = inferida` (texto padrão).
7. **Associação à lista**:
   - Usa `AudienceListRepository::attachContact` com `subscription_status = subscribed` (ou `unsubscribed` se `opted_out`).
   - Registra `source` (campo digitado no formulário) + `metadata` com linha original.
8. **Resumo do lote**: retorna array `['processed' => n, 'created' => x, 'updated' => y, 'attached' => z, 'duplicates_in_file' => d, 'invalid' => i]` para exibir na UI e armazenar em `import_logs` (opcional).

## 5. Interfaces & UX
- **Novo botão**: em cada card de lista (`resources/views/marketing/lists.php`), adicionar ação "Importar contatos".
- **Tela `marketing/list_import.php`** (nova view):
  - Card com instruções + download de CSV modelo.
  - Campo "Origem / campanha" (string) para preencher `audience_list_contacts.source`.
  - Upload (`accept=".csv"`) + checkbox "Respeitar opt-out existente" (default ligado).
  - Prévia de resultados após POST (tabela com contadores + lista de erros).
- **Feedback**: mensagens salvas em `marketing_lists_feedback` para voltar ao dashboard com resumo.

## 6. Considerações Técnicas
- Reaproveitar helper `now()` e `digits_only()`.
- O índice único de `marketing_contacts.email` garante dedupe adicional; capturar `PDOException` para linhas concorrentes.
- Adicionar teste unitário/integração leve em `tests/Marketing/MarketingContactImportServiceTest.php` com lote pequeno.
- Configurar limite de 5k linhas por upload inicial (configurável via `config/marketing.php` -> `imports.max_rows`).
- Registrar cada importação em `import_logs` reutilizando `ImportLogRepository` (tipo `marketing_contacts`) para histórico.

## 7. Próximos passos
1. Implementar `MarketingContactImportService` conforme fluxo.
2. Adicionar rotas `GET/POST /marketing/lists/{id}/import` no `Kernel` e handlers no `MarketingController`.
3. Criar view + feedback + download de template.
4. Conectar com `ImportLogRepository` para rastrear cada lote e exibir parcial no painel de listas.

## 8. Implementação & testes (concluído)
- Serviço pronto em `app/Services/Marketing/MarketingContactImportService.php`, reutilizando repositórios existentes, limites de `config/marketing.php` e registrando `ImportLogRepository`.
- Rotas GET/POST já expostas no `Kernel` e novas actions `importList`/`processImport` no `MarketingController`, incluindo validação de upload, feedbacks de sessão e limpeza do arquivo temporário.
- UI dedicada em `resources/views/marketing/list_import.php` com instruções, limites configuráveis e resumo da última execução. Botão “Importar contatos” disponível nos cards de lista.
- Arquivo modelo disponível em `public/samples/marketing_contacts_template.csv` para facilitar a preparação do CSV.
- Teste de integração cobrindo criação/atualização, atributos customizados e duplicidades (`tests/Marketing/MarketingContactImportServiceTest.php`). Executar com `php tests/Marketing/MarketingContactImportServiceTest.php`.
