<?php
/** @var string $logExample */
/** @var string $importCommand */

$encode = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>

<style>
    .manual-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }
    .manual-card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 24px;
        box-shadow: var(--shadow);
    }
    .manual-card h2 {
        margin-top: 0;
        font-size: 1.1rem;
    }
    .manual-card p {
        margin: 8px 0 12px;
        color: var(--muted);
    }
    .manual-card ul {
        padding-left: 18px;
        margin: 0;
        color: var(--text);
    }
    .manual-card li + li {
        margin-top: 6px;
    }
    .manual-code {
        margin: 12px 0 0;
        padding: 16px;
        border-radius: 14px;
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid rgba(148, 163, 184, 0.35);
        font-size: 0.9rem;
        overflow-x: auto;
    }
    .manual-highlight {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        background: rgba(56, 189, 248, 0.18);
        border: 1px solid rgba(56, 189, 248, 0.35);
        color: var(--accent);
        font-size: 0.85rem;
        margin-top: 8px;
    }
</style>

<div class="panel" style="margin-bottom:24px;">
    <h1 style="margin-top:0;">Manual · WhatsApp Copilot</h1>
    <p style="color:var(--muted);max-width:720px;">
        Este conteúdo fica disponível apenas para administradores e reúne o passo a passo da operação híbrida:
        sandbox para testes, importação de históricos e preparação para a API oficial da Meta.
    </p>
</div>

<div class="manual-grid">
    <section class="manual-card">
        <h2>Fluxo recomendado</h2>
        <p>Checklist rápido do que precisa acontecer antes de migrar definitivamente.</p>
        <ul>
            <li>Criar ao menos uma linha <strong>Sandbox</strong> em Configurações &gt; WhatsApp.</li>
            <li>Gerar logs NDJSON com os históricos coletados do gateway atual.</li>
            <li>Rodar o importador CLI para injetar as conversas no CRM (modo somente teste).</li>
            <li>Usar o módulo <em>WhatsApp · IA Copilot</em> em standalone para validar filas/atendimento.</li>
            <li>Somente após os testes, apontar a linha real para a API oficial.</li>
        </ul>
    </section>

    <section class="manual-card">
        <h2>Linha Sandbox</h2>
        <p>Não impacta os números oficiais e grava tudo em <code>storage/logs/whatsapp-sandbox.log</code>.</p>
        <ul>
            <li>Configurações &gt; WhatsApp &gt; “Adicionar linha”.</li>
            <li>Selecione o provedor <strong>Sandbox</strong> e apenas dê um rótulo (ex: “Teste Copilot”).</li>
            <li>Use <em>Injetar mensagem</em> na tela do módulo para simular filas e pré-triagem.</li>
            <li>Todos os erros de envio, filas e sandbox já são despachados para o AlertService.</li>
        </ul>
        <span class="manual-highlight">Objetivo: testar UI e filas sem tocar nos números da Meta.</span>
    </section>

    <section class="manual-card">
        <h2>Formato NDJSON</h2>
        <p>Um JSON por linha. Campos obrigatórios: <code>phone</code>, <code>direction</code>, <code>message</code>.</p>
        <pre class="manual-code"><?= $encode($logExample); ?></pre>
        <ul style="margin-top:12px;">
            <li><code>direction</code>: incoming/outgoing (aceita in/out).</li>
            <li><code>timestamp</code>: epoch ou data/hora legível.</li>
            <li><code>line_label</code> ou <code>line_id</code>: mantém o atendimento na fila correta.</li>
            <li><code>contact_name</code>: opcional, ajuda a identificar o cliente no CRM.</li>
        </ul>
    </section>

    <section class="manual-card">
        <h2>Importador CLI</h2>
        <p>Processa o NDJSON, chama o serviço interno e registra tudo como “importado”.</p>
        <pre class="manual-code"><?= $encode($importCommand); ?></pre>
        <ul style="margin-top:12px;">
            <li>Executa leitura streaming (aceita arquivos grandes).</li>
            <li><code>--mark-read=0</code> mantém as mensagens importadas como não lidas.</li>
            <li>Falhas por linha são exibidas no terminal; exceções críticas vão para AlertService.</li>
            <li>Resumo final: entradas lidas, importadas, erros e conversas afetadas.</li>
        </ul>
    </section>

    <section class="manual-card">
        <h2>Gateway WhatsApp Web</h2>
        <p>Para testes híbridos, execute o serviço em <code>services/whatsapp-web-gateway/</code> (Node.js + WPPConnect).</p>
        <ul>
            <li><code>cd services/whatsapp-web-gateway && cp .env.example .env && npm install && npm start</code></li>
            <li>Configure os tokens em <code>.env</code> e replique nos campos <code>WHATSAPP_ALT_GATEWAY_*</code> do CRM.</li>
            <li>O QR atualizado aparece no painel do módulo WhatsApp (card “WhatsApp Web alternativo”).</li>
            <li>Tokens: <strong>Authorization</strong> no webhook (gateway → CRM) e <strong>X-Gateway-Token</strong> para enviar mensagens (CRM → gateway).</li>
            <li>Logs e sessão ficam em <code>storage/whatsapp-web/</code>; o gateway consulta o banco (SQLite/MySQL) para identificar contatos antes de disparar o webhook.</li>
        </ul>
    </section>

    <section class="manual-card">
        <h2>Preparação para API oficial</h2>
        <p>Quando os testes no sandbox estiverem estáveis:</p>
        <ul>
            <li>Valide tokens/IDs da Meta ou 360dialog nas configurações da linha.</li>
            <li>Ative o webhook oficial apontando para <code>/whatsapp/webhook</code> com o mesmo <em>verify token</em>.</li>
            <li>Monitore <em>Alertas</em> na aba Configurações para qualquer erro HTTP 500.</li>
            <li>Quando tudo estiver pronto, troque o provedor da linha testada para “Meta” e conecte o número real.</li>
            <li>Mantenha o arquivo NDJSON guardado como backup — o importador pode ser reexecutado se necessário.</li>
        </ul>
    </section>
</div>

<div class="panel">
    <h2>Referências rápidas</h2>
    <ul style="margin:0;padding-left:20px;">
        <li><strong>Histórico Sandbox:</strong> <code><?= $encode(storage_path('logs/whatsapp-sandbox.log')); ?></code></li>
        <li><strong>Script CLI:</strong> <code><?= $encode(base_path('scripts/whatsapp/import_logs.php')); ?></code></li>
        <li><strong>Manual técnico PDF:</strong> disponível em Configurações &gt; Manual Técnico.</li>
    </ul>
</div>
