# Marketing – Template Library & Editor (Fase 2)

## 1. Objetivos
- Evoluir o módulo atual de `templates` (CRUD simples) para comportar o builder visual planejado na Semana 3.
- Permitir versionamento completo dos modelos (HTML/MJML) com histórico de publicações, rascunhos e rollback.
- Adicionar suporte a coleções/categorias, previews ricos e assets (imagens, logos, componentes reutilizáveis).
- Garantir alinhamento com o roadmap do motor de e-mail (campanhas, jornadas e centro de preferências) sem quebrar o fluxo atual.

## 2. Escopo dos Dados
### 2.1 Tabela `templates`
A tabela existente passa a armazenar metadados de catálogo:
- **slug** (opcional) para referência amigável e permalinks.
- **category** / **tags** para classificação (ex.: "renovação", "consentimento", "ofertas").
- **description**, **preview_text**, **thumbnail_path** para listar na galeria e preencher o inbox preview.
- **status** (`active`, `archived`, `system`) e **editor_mode** (`html`, `mjml`, `builder`).
- **settings** (JSON) para preferências globais (ex.: fontes, cores padrão).
- **locked_by / locked_at** para evitar conflitos de edição cooperativa.
- **latest_version_id** (lazy fill) apontando para a versão publicada mais recente.

O conteúdo bruto (subject/body) continua na tabela para retrocompatibilidade até que todo o frontend consuma somente `template_versions`.

### 2.2 Tabela `template_versions`
Registra cada iteração do modelo:
- FK para `templates`, número incremental de versão e rótulo amigável.
- Status (`draft`, `review`, `published`, `archived`).
- Campos de conteúdo: `subject`, `preview_text`, `body_html`, `body_text`, `body_mjml`.
- `blocks_schema` (JSON do builder), `data_schema` (placeholders/variáveis), `testing_settings` (amostras para render A/B), `checksum` para integridade.
- Auditoria: `created_by`, `published_by`, `published_at`, `created_at`, `updated_at`.

### 2.3 Tabela `template_assets`
Inventário de assets vinculados aos templates:
- Referência ao template e, opcionalmente, à versão que o utilizou.
- Metadados de arquivo (`type`, `mime_type`, `file_size`, `checksum`) e JSON flexível para atributos extras (ex.: cores extraídas, recortes).
- Controle de autoria e timestamps.

## 3. Fluxos Suportados
1. **Catálogo** – usuários visualizam os metadados direto da tabela `templates` (status, tags, preview). 
2. **Edição** – ao iniciar edição é criado um registro em `template_versions` com status `draft`; o builder escreve `blocks_schema`, `body_mjml` e `settings`. Campos legacy (`body_html/body_text`) podem ser sincronizados após render.
3. **Publicação** – ao publicar, marcamos `template_versions.status = published`, guardamos `published_at/by` e atualizamos `templates.latest_version_id` + `templates.updated_at`. Campanhas/jornadas sempre apontam para a versão vigente.
4. **Assets** – uploads feitos no editor geram linhas em `template_assets`; quando uma versão é publicada, os assets relacionados recebem `version_id` para rastrear dependências.
5. **Rollback** – basta duplicar uma versão publicada para um novo rascunho e republicar.

## 4. Próximos Passos Técnicos
1. **Migração** (este PR):
   - Acrescentar colunas na tabela `templates` + índices auxiliares.
   - Criar tabelas `template_versions` e `template_assets` com constraints básicas.
2. **Repository/Service update** – expor métodos para criar/consultar versões, inclusive fallback automático para os dados legacy.
3. **UI/Editor** – construir a nova tela com histórico lateral, comparação e pré-visualização.
4. **Integração Campanhas/Jornadas** – campanhas passam a escolher versão específica (ou sempre a última `published`).
5. **Compliance & Auditing** – registrar hash do conteúdo enviado em `mail_delivery_logs` para rastreabilidade com base no `version_id`.

> Status atual: as tabelas `campaigns` e `campaign_messages` já persistem `template_version_id`, garantindo que cada disparo referencie exatamente qual versão publicada originou o conteúdo.

Com essa fundação, conseguimos seguir para o builder visual sem bloquear o fluxo atual e garantimos rastreabilidade de conteúdo para LGPD e auditorias internas.
