# Manual do Usuário – Marketing Suite para Certificado Digital

> Versão 0.2 · Atualizado em 02/12/2025

## 1. Visão Geral
A Marketing Suite consolida CRM, automações e canais digitais para a operação de certificados digitais. Este manual descreve o fluxo diário para atendentes, vendedores e equipe de marketing sem privilégios administrativos.

## 2. Pré‑requisitos de acesso
- Credenciais aprovadas pelo administrador (login + senha e, se habilitado, TOTP).
- Navegador atualizado com HTTPS habilitado.
- Dispositivo autorizado (caso a política de dispositivos esteja ativa).

## 3. Entrada no sistema
1. Acesse a URL oficial (ex.: `https://intranet.exemplo.com`).
2. Informe e‑mail e senha cadastrados em **Entrar**.
3. Caso apareça o segundo fator, abra o app autenticador e digite o código de 6 dígitos.
4. Se o acesso estiver pendente ou negado, entre em contato com o administrador.

## 4. Navegação principal
O menu lateral apresenta módulos conforme suas permissões:
- **Visão geral**: atalhos rápidos e status dos canais conectados.
- **CRM & Renovações**: painel de métricas, importação e estatísticas.
- **Carteira de Clientes**: listagem filtrável de clientes ativos.
- **Carteira Off**: clientes fora da operação principal.
- **Parceiros & Contadores**: gestão de indicações e relatórios.
- **Agenda Operacional**: disponibilidade AVP e agendamentos.
- **Campanhas**: criação de disparos por e‑mail.
- **Contas sociais / Templates**: cadastro de credenciais e biblioteca de comunicação.

## 5. Dashboard & Indicadores
A tela inicial exibe:
- Contagem de contatos, segmentos e campanhas ativas.
- Conexões de canais (Meta, LinkedIn, WhatsApp etc.).
- Alertas de carteira (expirando, perdidos, off) quando permitido.
Acesse **CRM > Vision geral** para métricas detalhadas e gráficos de crescimento mensal.

## 6. CRM
### 6.1 Filtros rápidos
- **Busca**: nome, CPF/CNPJ ou número de protocolo.
- **Status**: Ativo, Recém vencido, Inativo, Perdido, Prospecção, Agendado, Avisar.
- **Janela de expiração**: intervalos pré‑definidos (próx. 10/20/30 dias, atrasados etc.).
- **Tipo de documento**: CPF ou CNPJ.
- **Parceiro**: filtra pelo campo “Nome do contador parceiro”.
- **Aniversário**: filtro por mês/dia do titular.

### 6.2 Cadastro e edição
1. Clique em **Novo cliente**.
2. Informe CPF/CNPJ; o sistema verifica duplicidade automaticamente.
3. Preencha dados principais (nome, titular, contatos, status, pipeline, notas).
4. Salve; o cliente já aparecerá na listagem. Use o botão **Atualizar** para editar campos existentes.

### 6.3 Protocolos e certificados
- Aba **Protocolos** dentro do cliente permite registrar novos números, atualizar status ou remover entradas.
- Importações via planilha atualizam automaticamente protocolo, expiração e estágio.

### 6.4 Carteira Off
- Clientes removidos da operação ativa aparecem em `CRM > Carteira Off`.
- Utilize **Restaurar** para trazê‑los de volta ou **Mover para Off** em um cliente ativo quando necessário.

## 7. Parceiros e Indicações
- Use a busca para encontrar contadores existentes.
- O cartão apresenta semáforo (verde <30 dias, amarelo 30–365, vermelho >365 sem indicação).
- O botão **Marcar como parceiro** converte clientes homônimos em parceiros oficiais.
- Registre relatórios rápidos pelo botão **Salvar Relatório**.

## 8. Agenda Operacional
- Visualize disponibilidade dos AVPs, bloqueios e horários livres.
- O filtro respeita permissões do usuário; alguns agentes enxergam apenas AVPs vinculados.
- Para sugerir mudanças (ex.: feriados), registre solicitações ao administrador.

## 9. Campanhas de E‑mail
1. Vá em **Campanhas > E‑mail**.
2. Clique em **Nova campanha** e selecione remetente/social account.
3. Escolha ou crie um template, defina segmento e mensagem.
4. Revise o resumo e confirme. Campanhas ficam listadas com status (rascunho, agendada, enviada).

## 10. Templates e Contas Sociais
- **Templates**: crie layouts HTML/MJML básicos para e‑mails e mensagens; suportam variáveis como `{{nome}}`.
- **Contas sociais**: cadastre tokens e credenciais dos canais utilizados nas automações (Meta, LinkedIn, WhatsApp Business). Tokens expiram; atualize sempre que o status indicar.

## 11. Importações por planilha
### 11.1 CRM / Base RFB
1. Acesse `CRM > Importar contatos`.
2. Envie arquivo `.xls`, `.xlsx` ou `.csv` no formato padrão (baixe o modelo em **Configurações**).
3. Ao concluir, o painel mostra quantos registros foram criados/atualizados; erros ficam listados logo abaixo.
4. Se algo falhar, copie o trecho do log exibido ou anexe o arquivo corrigido ao ticket para o administrador.

### 11.2 Contatos de marketing
1. Vá em **Marketing > Listas** e escolha a lista desejada.
2. Clique em **Importar contatos** e baixe o template (`.csv`) caso ainda não possua.
3. Preencha os campos obrigatórios (e-mail, nome, tags, consentimento). Colunas `custom.*` criam atributos adicionais.
4. Defina o rótulo da origem e marque **Respeitar opt-out** quando não quiser reativar contatos desligados.
5. Envie o CSV (limites e tamanho máximo aparecem na tela). O sistema mostra resumo com processados, criados, atualizados e duplicados.
6. Ajuste as linhas exibidas em **Erros recentes** e tente novamente; o histórico completo fica salvo para que o administrador acompanhe.

## 12. WhatsApp + IA Copilot
- O módulo fica em **Conversas inteligentes > WhatsApp** e só aparece para quem possui a permissão correspondente.
- A coluna esquerda lista as conversas sincronizadas via webhook; o painel central mostra o histórico e o formulário de envio; a coluna direita reúne configuração Meta e o painel da IA.
- Utilize o selo superior para saber se o token da Meta está válido, se o webhook foi aceito e se a IA Copilot está pronta.
- Para responder:
	1. Escolha a conversa desejada; as mensagens são agrupadas por contato (telefone formatado automaticamente).
	2. Clique em **Sugerir com IA** (opções Formal/Direto ajustam o tom) para preencher o campo de texto com uma proposta inicial.
	3. Revise o conteúdo, personalize e toque em **Enviar pelo WhatsApp**. O sistema registra o envio localmente e encaminha à API oficial caso as credenciais estejam ativas. Quando ainda estiver em homologação o status aparecerá como `queued`.
- Na lateral é possível atualizar os campos **Access Token**, **Phone Number ID**, **Business Account ID**, **Webhook Verify Token** e **Copilot API Key** sem sair da tela. O feedback embaixo do formulário confirma a gravação.
- Sempre que a Meta entregar uma nova mensagem, a conversa é aberta automaticamente e sinalizada com contador vermelho de não lidos.

## 13. Notificações e sessão
- Mensagens de sucesso/erro aparecem em banners verdes/vermelhos.
- Se não houver atividade por ~20 minutos, surge aviso para manter a sessão; clique em **Continuar** para evitar logout automático.

## 14. Boas práticas do usuário
- Atualize dados de contato sempre que falar com o cliente.
- Registre anotações claras após cada ligação ou visita.
- Revise diariamente os filtros “Expira em 10 dias” e “Recém vencido”.
- Nunca compartilhe senha ou códigos TOTP.

## 15. Suporte
- Problemas de acesso: procure o administrador responsável.
- Inconsistências de dados/importação: registre ticket no canal interno ou envie o arquivo problemático para TI.
- Ideias e melhorias: use o menu **Feedback** (se disponível) ou comunique sua liderança.
