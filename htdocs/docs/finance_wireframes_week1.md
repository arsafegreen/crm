# FIN-03 – Wireframes Semana 1

## 1. Home Financeira

### 1.1 Estrutura Geral
- **Header compacto** com saldo consolidado (Total em Caixa, Total Disponível, Variação diária) e botões de ação rápida: `+ Lançamento`, `Importar Extrato`, `Pagar Guia`.
- **Cards principais** (layout 3 colunas responsivo):
  1. **Contas Bancárias**: lista de até 4 contas com barra de progresso indicando limite vs. saldo atual. Tooltip mostra saldo inicial e último update.
  2. **Alertas Críticos**: badges para `Saldo abaixo do mínimo`, `Impostos vencidos`, `Inadimplências`. Cada item linka para tela específica.
  3. **Próximos 7 dias**: gráfico de linha previsto vs. realizado (eixo X = datas, eixo Y = R$). Debaixo, tabela condensada com colunas `Data`, `Descrição`, `Valor`, `Status`.
- **Timeline lateral** (coluna direita) com eventos mais recentes: importações, conciliações, aprovações. Cada evento mostra avatar/responsável e timestamp relativo.

### 1.2 Estados e Interações
- **Sem dados**: cards exibem placeholders + CTA “Conectar conta ou importar extrato”.
- **Erro em importação**: banner vermelho persistente com link para o batch na tela de importações.
- **Filtro por centro de custo**: dropdown global que afeta cards e timeline; lembrar estado no localStorage.

## 2. Calendário Fiscal

### 2.1 Layout
- Navbar secundária com tabs `Mensal`, `Semanal`, `Lista`. Default: mensal.
- **Calendário** ocupa largura total; cada dia tem até 3 pills coloridas representando obrigações (cores por tipo: azul = federal, verde = estadual, amarelo = municipal, roxo = trabalhista).
- **Painel lateral** mostra detalhes do dia selecionado: título, órgão, valor estimado, responsável (usuário) e botões `Registrar Pagamento`, `Gerar Guia`, `Marcar como postergado`.

### 2.2 Fluxos
- Clique em um dia abre drawer com timeline das ações (criação, lembretes, comprovação).
- Seleção múltipla (arraste) para aplicar bulk action `Agendar lembrete`.
- Campo de busca global (topo) com filtros: `Tipo`, `Centro de custo`, `Status` (`pendente`, `agendado`, `pago`, `postergado`).

### 2.3 Estados Especiais
- **Modo impressao**: reduz cores, converte cards em linhas com ícones monocromáticos.
- **Sem obrigações cadastradas**: mostra checklist de configuração (CNAE, UF, regimes) com links para cadastros.

## 3. Visão de Contas (Contas a Pagar/Receber)

### 3.1 Estrutura
- Header com KPIs: `Aberto esta semana`, `Vencido`, `Previsto mês`, `Liquidado mês` (cards tipo estatística, comparando período anterior).
- **Tabela mestre** com colunas configuráveis: `Descrição`, `Tipo (Pagar/Receber)`, `Centro de Custo`, `Parte`, `Valor`, `Vencimento`, `Status`, `Tags`.
- Lateral esquerda com filtros rápidos (checkbox tree):
  - Tipo: `Pagar`, `Receber`.
  - Status: `Rascunho`, `Aprovado`, `Pago`, `Atrasado`.
  - Fontes: `CRM`, `Manual`, `Importação`, `Assinaturas`.

### 3.2 Interações
- **Linha expandível**: ao clicar, abre subpainel inline contendo histórico, anexos, split por centro de custo e ações (`Aprovar`, `Enviar cobrança`, `Gerar boleto`).
- **Bulk actions** (checkbox por linha): `Aprovar`, `Enviar lembrete`, `Exportar CSV`.
- **Sticky footer** com resumo do filtro atual (total pagar, total receber, saldo líquido) + CTA `Exportar`.

### 3.3 Estados e Validações
- Quando `saldo disponível` da conta vinculada for insuficiente, badge vermelho `Saldo insuficiente` é exibido ao lado do valor.
- Lançamentos recorrentes mostram ícone de loop; hover exibe próxima ocorrência.
- Suporte a modo escuro: contrastes definidos seguindo guidelines WCAG AA.

## 4. Entregáveis
- Estruturas acima estão descritas em formato textual + wireframe ASCII (quando aplicável) armazenadas neste arquivo.
- Sugestão: converter estes blocos em mockups de baixa fidelidade no Figma (componentes `Grid 12 col / 24px gap`).
- Próximos passos: validar com time financeiro, coletar feedback de navegação e começar protótipos clicáveis para Semana 2.
