# Guia de Integração Facebook, Instagram e WhatsApp

Este documento descreve, em português e de forma detalhada, como preparar a infraestrutura Meta (Facebook, Instagram e WhatsApp Business) para funcionar com o Chatboot-IA / CRM. Inclui pré-requisitos, telas de referência e pontos de atenção para manter a conformidade com a Plataforma Comercial.

---

## 1. Visão geral e pré-requisitos

### 1.1 Contas necessárias

1. **Conta Facebook pessoal** com autenticação em dois fatores habilitada.
2. **Business Manager (Meta Business Suite)** com propriedade das páginas e ativos:
	- Página do Facebook da empresa.
	- Conta do Instagram profissional (convertida para comercial ou Creator).
	- Número do WhatsApp Business oficial (já verificado, como informado).
3. **Domínio corporativo** com acesso DNS para eventuais verificações adicionais.

### 1.2 Permissões internas

- Pelo menos um usuário deve ser **Administrador** do Business Manager.
- Para operação diária, recomenda-se criar um **System User** específico para o Chatboot-IA e conceder apenas os escopos necessários (`whatsapp_business_messaging`, `whatsapp_business_management`, `pages_show_list`, `instagram_basic`, etc.).

### 1.3 Itens a confirmar antes de iniciar

| Item | Pergunta | Como confirmar |
| --- | --- | --- |
| Página publicada | A página FB está ativa e com informações atualizadas? | Business Suite → Páginas |
| Instagram profissional | Conta está convertida para profissional e vinculada à página? | Instagram App → Configurações → Conta → Tipo de conta |
| WhatsApp verificado | O número aparece como "Verificado" no WhatsApp Manager? | business.facebook.com/wa/manage |
| Webhook HTTPS | O domínio do CRM possui certificado válido? | Acessar `https://seu-dominio/whatsapp/webhook` |

---

## 2. Configuração no Facebook Business Manager

### 2.1 Acesso aos ativos

1. Acesse [business.facebook.com/settings](https://business.facebook.com/settings).
2. Em **Contas → Páginas**, confirme que a página da empresa está listada e atribuída ao negócio.
3. Em **Contas → Contas do Instagram**, clique em **Adicionar** caso ainda não esteja vinculada. Será solicitado login na conta Instagram.
4. Em **Dados da Empresa**, valide que o Business Manager está **Verificado** (documentos enviados e aprovados). Isso reduz bloqueios.

### 2.2 Criar/atribuir pessoas e parceiros

1. Aba **Usuários → Pessoas**: adicione os membros responsáveis e conceda as permissões adequadas para Página, Instagram e WhatsApp.
2. **Usuários → Parceiros**: caso trabalhe com agência, conceda acesso por parceiro para evitar compartilhamento de senhas.

### 2.3 Criar usuário de sistema (opcional, recomendado)

1. **Usuários → Usuários do sistema** → **Adicionar**.
2. Selecione "Admin" apenas se o Chatboot precisar administrar ativos; caso contrário, use "Padrão" e depois conceda permissões específicas.
3. Após criado, clique em **Gerar token** → escolha o aplicativo associado → selecione escopos:
	- `whatsapp_business_messaging`
	- `whatsapp_business_management`
	- `business_management`
	- `pages_manage_engagement`, `pages_manage_metadata` (se houver recursos futuros de página)
4. Guarde o token em local seguro. **Ele só será exibido uma vez.**

---

## 3. Verificação e API do WhatsApp Business

### 3.1 Localizando o número, Phone Number ID e WABA ID

1. Acesse [https://business.facebook.com/wa/manage](https://business.facebook.com/wa/manage).
2. Escolha a conta "SafeGreen Certificado Digital" (ID `1107567477439352`).
3. Na aba **Configuration**:
	- **WhatsApp Business Account ID (WABA)** aparece no topo.
	- Em "Phone numbers", clique no número desejado para ver o **Phone Number ID**.
4. Copie esses valores para usar no Chatboot-IA (campos "Business Account ID" e "Phone Number ID").

### 3.2 Gerar Access Token

Existem duas opções:

**a) Token temporário via WhatsApp Manager**

- Em "API Setup" clique em **Generate token** → defina validade (1h/24h). Adequado para testes.

**b) Token de longo prazo via Usuário do Sistema** *(recomendado)*

1. Volte ao Business Settings → **Usuários → Usuários do sistema**.
2. Clique no usuário criado → **Tokens** → **Generate new token**.
3. Selecione o aplicativo Meta (por exemplo, "CRM Chatboot App").
4. Marque os escopos citados e gere o token. Utilize este valor no campo "Access Token" do Chatboot.

### 3.3 Configurar Webhook

1. Ainda em `wa/manage`, abra **Configuration → Webhooks**.
2. Insira a URL pública do CRM: `https://seu-dominio/whatsapp/webhook`.
3. Defina o **Verify Token** (ex.: `safegreen-whatsapp`). Mesma string deve ser informada no Chatboot ao criar a linha.
4. Assine os eventos `messages`, `message_template_status_update`, `account_update`.
5. Teste o webhook usando o botão "Send test" (o CRM deve responder com status 200).

### 3.4 Templates e opt-in

- Em **Message Templates**, crie mensagens transacionais (boas-vindas, lembretes). Cada template precisa ser aprovado.
- Garanta que os contatos deram consentimento (opt-in) antes de enviar mensagens proativas.

---

## 4. Integração do Instagram com a página e ferramentas comerciais

### 4.1 Converter e vincular a conta

1. No aplicativo Instagram:
	- Configurações → Conta → **Mudar para conta profissional** (se ainda não for).
	- Escolha a categoria e defina "Empresa".
2. Em **Conta → Compartilhar em outras apps**, conecte a página do Facebook correspondente.
3. Volte ao Business Manager → **Contas → Contas do Instagram** → selecione a conta → **Atribuir ativos** à Página e às pessoas necessárias.

### 4.2 Permissões para ferramentas externas

- Se for utilizar publicação ou mensagens diretas via API, conceda escopos `instagram_basic`, `instagram_manage_messages`, `pages_manage_metadata` ao aplicativo/usuário do sistema.
- Lembre-se de revisar as políticas de conteúdo do Instagram (evitar bloqueios por automação excessiva).

### 4.3 Configurações adicionais

- Configure **Respostas rápidas** e **Perguntas frequentes** no Instagram para manter a consistência com o WhatsApp.
- Ative **Centro de Contas Meta** para sincronizar experiência entre plataformas.

---

## 5. Conectando tudo ao Chatboot-IA / CRM

### 5.1 Cadastro de linhas dentro do Chatboot

1. Acesse `https://seu-dominio/whatsapp` (Chatboot-IA).
2. Em **Linhas registradas** → **Salvar linha**:
	- **API Provider**: escolha `Meta Cloud API (oficial)` ou `Dialog360` (já disponível) ou futuros provedores.
	- **Label/Display Phone**: nomes amigáveis para identificação.
	- **Phone Number ID** / **Business Account ID**: valores copiados do WhatsApp Manager.
	- **Access Token**: token longo gerado no Business Manager ou D360-API-KEY se usar Dialog360.
	- **Verify Token**: mesmo valor configurado no webhook.
	- **Status**: mantenha `active`. Use `maintenance` se precisar pausar a linha.
3. Defina uma linha como **Padrão** (checkbox) para envios quando o thread ainda não tiver um número associado.

### 5.2 Permissões de usuários no Chatboot

1. Em **Permissões & Copilot**, marque os atendentes autorizados.
2. Habilite a opção "Bloquear AVPs" caso não queira que usuários com perfil AVP acessem o módulo.
3. Salve e peça para o time relogar; as permissões são verificadas na sessão.

### 5.3 Teste ponta a ponta

1. Use a ferramenta "Send test message" do WhatsApp Manager para enviar uma mensagem ao número configurado.
2. Verifique se a conversa aparece na fila "Fila aguardando" ou "Conversas abertas" do Chatboot.
3. Responda pelo Chatboot e confira no celular que a mensagem foi entregue.
4. Se usar Dialog360, valide no dashboard deles se a mensagem foi encaminhada.

---

## 6. Checklist de validação e resolução de problemas

### 6.1 Checklist rápido

- [ ] Business Manager verificado e com usuários atribuídos.
- [ ] Página do Facebook e conta do Instagram vinculadas.
- [ ] WhatsApp Business com `Phone Number ID`, `WABA ID`, token e webhook configurados.
- [ ] Linha cadastrada no Chatboot-IA com provider correto.
- [ ] Webhook respondendo 200 (testado pela Meta).
- [ ] Mensagens de teste fluindo nos dois sentidos.

### 6.2 Problemas comuns

| Sintoma | Possível causa | Como resolver |
| --- | --- | --- |
| Webhook retorna 403 na verificação | Verify Token diferente | Ajuste o token no Chatboot ou na Meta para que sejam iguais |
| Mensagem enviada mas não aparece no Chatboot | Webhook não está recebendo eventos `messages` | Reabra a assinatura em `wa/manage` e verifique logs do servidor |
| Token expira após 24h | Token temporário em uso | Gere token de longo prazo via Usuário do Sistema |
| Instagram não aparece no Business Manager | Conta ainda não é profissional ou não vinculada à página | Converter para profissional e reconectar |

### 6.3 Segurança e conformidade

- Nunca exponha tokens em canais não seguros. Utilize cofres de segredo.
- Restrinja acesso ao Business Manager somente a usuários necessários.
- Revise periodicamente os templates de WhatsApp para manter aderência às políticas da Meta.

---

## 7. Próximos passos

1. Criar documentação interna com credenciais e responsáveis.
2. Monitorar métricas (status de filas, consumo de mensagens, templates recusados).
3. Se precisar de provedores adicionais (Infobip, Twilio, etc.), replicar o processo adicionando novos providers no Chatboot.

Com este guia, o time consegue configurar Facebook Business, Instagram e WhatsApp Business de ponta a ponta e manter o Chatboot-IA operando com acesso oficial à Plataforma Comercial.
