<?php
/** @var array<int,string> $banners */
/** @var array<int,array<string,string>> $features */
/** @var array<int,array<string,string>> $steps */
/** @var string $cta_lead */
?>
<div class="hero panel highlight" style="position:relative; overflow:hidden;">
    <span class="eyebrow"><span class="badge-dot"></span>Bem-vindo</span>
    <h1>Uma porta de entrada futurista para anunciar seu negócio.</h1>
    <p class="lede">Vitrine pública com estética neon, banners corridos e CTA único para quem quer visibilidade imediata. Totalmente isolado do CRM.</p>
    <div class="cta-row">
        <a class="cta" href="<?= htmlspecialchars($cta_lead, ENT_QUOTES, 'UTF-8'); ?>">Anunciar agora</a>
        <a class="cta alt" href="<?= htmlspecialchars(url('auth/login'), ENT_QUOTES, 'UTF-8'); ?>">Acessar CRM</a>
    </div>

    <div class="panel" style="margin-top:14px; background: rgba(255,255,255,0.04); border-style:dashed;">
        <div class="pill" style="margin-bottom:8px;">
            <span class="badge-dot"></span> Banners dinâmicos
        </div>
        <div class="ticker" aria-label="banners corridos">
            <div class="ticker-track" data-duplicate="1">
                <?php foreach ($banners as $text): ?>
                    <span class="ticker-item"><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endforeach; ?>
                <?php foreach ($banners as $text): ?>
                    <span class="ticker-item"><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="panel" aria-label="Diferenciais">
    <div class="pill"><span class="badge-dot"></span> Por que essa frente</div>
    <div class="steps">
        <?php foreach ($features as $item): ?>
            <div class="step">
                <strong><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <p><?= htmlspecialchars($item['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="panel" aria-label="Fluxo de ação">
    <div class="pill"><span class="badge-dot"></span> Como avançar</div>
    <div class="steps">
        <?php foreach ($steps as $step): ?>
            <div class="step">
                <strong><?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <p><?= htmlspecialchars($step['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    <a class="cta" style="margin-top:10px;" href="<?= htmlspecialchars($cta_lead, ENT_QUOTES, 'UTF-8'); ?>">Quero meu destaque</a>
</div>

<script>
    // ticker infinito simples
    const track = document.querySelector('.ticker-track');
    if (track) {
        const speed = 80; // pixels por segundo
        let offset = 0;
        function step(ts) {
            offset -= 0.16 * speed;
            const width = track.scrollWidth / 2;
            if (-offset >= width) offset = 0;
            track.style.transform = `translateX(${offset}px)`;
            requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }
</script>

<style>
.ticker {
    position: relative;
    overflow: hidden;
    width: 100%;
    border: 1px solid rgba(34, 211, 238, 0.35);
    border-radius: 12px;
    background: linear-gradient(120deg, rgba(34,211,238,0.08), rgba(168,85,247,0.08));
    padding: 10px 0;
}
.ticker-track {
    display: inline-flex;
    gap: 32px;
    white-space: nowrap;
    will-change: transform;
    padding-left: 12px;
}
.ticker-item {
    color: #c7f9ff;
    font-weight: 600;
    letter-spacing: 0.01em;
    text-shadow: 0 0 16px rgba(34, 211, 238, 0.35);
}
</style>
