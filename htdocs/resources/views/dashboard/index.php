<div class="panel">
    <h2 style="margin:0 0 12px;font-size:1.2rem;">Visão Geral</h2>
    <p style="margin:0;color:var(--muted);">Resumo inicial da operação. Vamos alimentar com dados reais conforme integrarmos contatos e campanhas.</p>

    <div class="metric-grid">
        <div class="metric">
            <span>Contatos</span>
            <strong><?= number_format($metrics['contacts_total'] ?? 0, 0, ',', '.'); ?></strong>
        </div>
        <div class="metric">
            <span>Segmentos</span>
            <strong><?= number_format($metrics['segments_total'] ?? 0, 0, ',', '.'); ?></strong>
        </div>
        <div class="metric">
            <span>Campanhas Ativas</span>
            <strong><?= number_format($metrics['campaigns_active'] ?? 0, 0, ',', '.'); ?></strong>
        </div>
        <div class="metric">
            <span>Canais Conectados</span>
            <strong><?= number_format($metrics['channels_connected'] ?? 0, 0, ',', '.'); ?></strong>
        </div>
    </div>

    <div class="status" id="automationStatus">Aguardando início.</div>
</div>

<script>
    const statusEl = document.getElementById('automationStatus');

    window.addEventListener('automation:updated', function (event) {
        if (!event.detail || !statusEl) {
            return;
        }

        const detail = event.detail;
        if (detail.error) {
            statusEl.innerHTML = '<span style="color:#f87171;">Não foi possível iniciar a automação. Tente novamente.</span>';
            return;
        }

        statusEl.innerHTML = `Fluxo iniciado em <strong>${new Date(detail.started_at).toLocaleString('pt-BR')}</strong>. Status: ${detail.status}.`;
    });
</script>
