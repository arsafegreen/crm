<?php
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$baseUrl = $scheme . '://' . $host . ($scriptDir === '' || $scriptDir === '/' ? '' : $scriptDir);
$crmUrl = $baseUrl . '/public/index.php';
$canonicalUrl = rtrim($baseUrl, '/') . '/';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Certificado Digital ICP-Brasil com validação em minutos em São Paulo. Emita e.CPF e e.CNPJ com atendimento remoto, assinatura eletrônica segura e suporte especializado.">
    <meta name="keywords" content="certificado digital, ICP-Brasil, eCPF, eCNPJ, validação facial, emissão online, certificado A1, certificado A3, selo digital, São Paulo">
    <meta name="author" content="seloid.com.br">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="Certificado Digital ICP-Brasil em São Paulo">
    <meta property="og:description" content="Simplifique emissões de e.CPF e e.CNPJ com atendimento remoto, biometria facial e entrega em minutos.">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:site_name" content="Selo ID">
    <meta property="og:type" content="website">
    <meta property="og:image" content="https://selodigitalonline.com.br/imagem/e-CPF-flat.png">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Certificado Digital ICP-Brasil em São Paulo">
    <meta name="twitter:description" content="Certificados digitais e.CPF e e.CNPJ com validação remota, suporte humano e máxima segurança.">
    <meta name="theme-color" content="#020b25">
    <title>Certificado Digital ICP-Brasil | Emissão Online em São Paulo</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2W4Rhis/DbILU74C1vSrLJxCq57Yx04ecqC5Lnw2vK0O79btp6G7iXgP0hKtu2w3Xh1g==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        :root {
            --bg: #010511;
            --bg-alt: #031735;
            --glass: rgba(7, 24, 55, 0.75);
            --border: rgba(255, 255, 255, 0.08);
            --text: #eaf4ff;
            --muted: #9bb6d4;
            --accent: #00e0ff;
            --accent-strong: #9b3cff;
            --success: #66f8c7;
            --radius: 20px;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: 'Space Grotesk', 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            color: var(--text);
            background: radial-gradient(circle at 20% 20%, rgba(0, 224, 255, 0.08), transparent 45%),
                        radial-gradient(circle at 80% 0%, rgba(155, 60, 255, 0.1), transparent 40%),
                        linear-gradient(135deg, #010511, #020b25 50%, #051943 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background: url('data:image/svg+xml,%3Csvg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"%3E%3Cg fill="none" fill-opacity="0.08" stroke="%23ffffff" stroke-width="0.4"%3E%3Cpath d="M0 50h100M50 0v100"/%3E%3C/g%3E%3C/svg%3E');
            opacity: 0.07;
            z-index: 0;
            pointer-events: none;
        }

        a { color: var(--accent); text-decoration: none; }
        .small { font-size: 0.85rem; color: var(--muted); }
        img { max-width: 100%; height: auto; display: block; }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(16px);
            background: rgba(1, 5, 17, 0.75);
            border-bottom: 1px solid var(--border);
            padding: 18px clamp(16px, 4vw, 48px);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-mark {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: radial-gradient(circle at 30% 30%, var(--accent), var(--accent-strong));
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: grid;
            place-items: center;
            font-weight: 700;
            letter-spacing: 0.08em;
        }

        .brand-text small { color: var(--muted); display: block; }
        .brand-text strong { font-size: 1rem; letter-spacing: 0.08em; }

        nav { display: flex; gap: 18px; flex-wrap: wrap; }
        nav a { color: var(--muted); font-size: 0.95rem; transition: color 0.2s ease; }
        nav a:hover { color: var(--text); }

        .header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

        .btn {
            border: 0;
            border-radius: 999px;
            padding: 12px 20px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-icon {
            padding: 10px 18px;
            border-radius: 999px;
            border: 1px solid rgba(0, 224, 255, 0.6);
            background: radial-gradient(circle at 30% 30%, rgba(0, 224, 255, 0.35), rgba(34, 197, 94, 0.15));
            color: #e5ffe8;
            font-size: 1rem;
            box-shadow: 0 10px 25px rgba(0, 224, 255, 0.25);
        }

        .btn-icon-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
        }

        .btn-icon-logo svg {
            width: 100%;
            height: 100%;
        }

        .btn-icon:hover {
            box-shadow: 0 14px 30px rgba(0, 224, 255, 0.35);
        }

        .btn-gradient {
            background: linear-gradient(120deg, var(--accent), var(--accent-strong));
            color: #020510;
            box-shadow: 0 15px 30px rgba(0, 224, 255, 0.3);
        }

        .btn-ghost {
            color: var(--text);
            border: 1px solid var(--border);
            background: transparent;
        }

        .btn:hover { transform: translateY(-2px); }

        main {
            position: relative;
            z-index: 1;
            max-width: 1200px;
            margin: 0 auto;
            padding: clamp(32px, 6vw, 80px) clamp(16px, 5vw, 32px) 100px;
            display: flex;
            flex-direction: column;
            gap: clamp(40px, 6vw, 80px);
        }

        section {
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: clamp(24px, 4vw, 48px);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.35);
        }

        .hero {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 40px;
            background: linear-gradient(140deg, rgba(0, 224, 255, 0.08), rgba(5, 25, 67, 0.95));
        }

        .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.18em;
            font-size: 0.8rem;
            color: var(--accent);
        }

        .hero h1 {
            font-size: clamp(2.4rem, 5vw, 3.6rem);
            margin: 12px 0;
            line-height: 1.1;
        }

        .hero p.lead {
            color: var(--muted);
            margin-bottom: 24px;
            font-size: 1.1rem;
        }

        .hero-cta { display: flex; flex-wrap: wrap; gap: 12px; }

        .hero-stats {
            list-style: none;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
            padding: 0;
            margin: 28px 0 0;
        }

        .hero-stats li {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            text-align: center;
            background: rgba(2, 10, 32, 0.6);
        }

        .hero-stats span {
            display: block;
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--accent);
        }

        .hero-card {
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 24px;
            background: rgba(0, 0, 0, 0.25);
            position: relative;
            overflow: hidden;
        }

        .hero-card::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0, 224, 255, 0.35), transparent 70%);
            top: -40px;
            right: -60px;
            filter: blur(20px);
        }

        .chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .chip {
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid var(--border);
            font-size: 0.9rem;
            color: var(--muted);
        }

        .trust {
            display: grid;
            gap: 20px;
            background: rgba(2, 8, 25, 0.9);
        }

        .logo-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 16px;
            text-align: center;
        }

        .logo-row span {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
            font-size: 0.95rem;
            color: var(--muted);
        }

        .section-header { text-align: center; margin-bottom: 32px; }
        .section-header h2 { font-size: clamp(1.8rem, 4vw, 2.6rem); margin: 10px 0; }
        .section-header p { color: var(--muted); }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .card {
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            background: rgba(3, 10, 32, 0.8);
            min-height: 220px;
        }

        .card h3 { margin-top: 0; }
        .card p { color: var(--muted); }

        .products .card {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .price { font-size: 1.6rem; font-weight: 700; color: var(--accent); }
        .badge { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.2em; color: var(--accent-strong); }

        .process-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
        }

        .process-step {
            padding: 16px;
            border-radius: 16px;
            border: 1px solid var(--border);
            background: rgba(2, 8, 29, 0.7);
        }

        .process-step strong { display: block; font-size: 2rem; color: var(--accent); }

        .cta-lead {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 32px;
            background: linear-gradient(120deg, rgba(2, 10, 32, 0.95), rgba(9, 41, 95, 0.95));
        }

        form {
            display: grid;
            gap: 14px;
        }

        label { font-size: 0.9rem; color: var(--muted); }

        input, select, textarea {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(1, 4, 19, 0.7);
            color: var(--text);
            font-family: inherit;
        }

        textarea { min-height: 120px; resize: vertical; }

        .faq details {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 18px;
            background: rgba(0, 0, 0, 0.25);
        }

        .faq summary {
            cursor: pointer;
            font-weight: 600;
        }

        .faq details + details { margin-top: 12px; }

        footer {
            text-align: center;
            color: var(--muted);
            padding: 32px 16px 64px;
        }

        .floating-chat {
            position: fixed;
            bottom: 18px;
            right: 18px;
            z-index: 20;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }

        .floating-chat [hidden] {
            display: none !important;
        }

        .floating-trigger {
            border: none;
            border-radius: 999px;
            padding: 12px 18px;
            background: linear-gradient(120deg, var(--accent), var(--accent-strong));
            color: #010511;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 20px 35px rgba(0, 224, 255, 0.35);
        }

        .floating-panel {
            width: min(360px, 90vw);
            background: rgba(3, 12, 34, 0.95);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
        }

        .floating-panel header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .floating-panel header h4 {
            margin: 0;
            font-size: 1rem;
        }

        .floating-close {
            border: none;
            background: transparent;
            color: var(--muted);
            font-size: 1.2rem;
            cursor: pointer;
        }

        .floating-panel form label { font-size: 0.8rem; }

        .floating-panel input,
        .floating-panel textarea {
            border-radius: 10px;
        }

        .floating-feedback {
            margin-top: 8px;
            font-size: 0.85rem;
            color: var(--success);
        }

        .floating-session {
            margin-top: 12px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .floating-status {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.05);
        }

        .floating-status strong {
            font-size: 0.9rem;
            color: var(--text);
            display: block;
        }

        .floating-status small {
            display: block;
            margin-top: 4px;
            color: var(--muted);
        }

        .floating-messages {
            max-height: 280px;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: rgba(3, 12, 34, 0.7);
        }

        .floating-message {
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .floating-message header {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 4px;
            color: var(--muted);
        }

        .floating-message.agent {
            background: rgba(14, 165, 233, 0.12);
            border: 1px solid rgba(14, 165, 233, 0.2);
            align-self: flex-start;
        }

        .floating-message.visitor {
            background: rgba(102, 248, 199, 0.12);
            border: 1px solid rgba(102, 248, 199, 0.2);
            align-self: flex-end;
        }

        .floating-message.system {
            background: rgba(148, 163, 184, 0.12);
            border: 1px solid rgba(148, 163, 184, 0.2);
            text-align: center;
            align-self: center;
        }

        .floating-reply {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .floating-reply textarea {
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(255, 255, 255, 0.02);
            color: var(--text);
            padding: 10px 12px;
            resize: vertical;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
        }

        @media (max-width: 820px) {
            .site-header { flex-direction: column; align-items: flex-start; }
            nav { width: 100%; }
            .header-actions { width: 100%; justify-content: flex-start; }
        }
    </style>
</head>
<body>
<header class="site-header" id="top">
    <div class="brand">
        <div class="brand-mark">SI</div>
        <div class="brand-text">
            <small>Autoridade ICP-Brasil</small>
            <strong>Selo ID Digital</strong>
        </div>
    </div>
    <nav aria-label="Navegação principal">
        <a href="#solucoes">Soluções</a>
        <a href="#planos">Planos</a>
        <a href="#processo">Processo</a>
        <a href="#faq">FAQ</a>
        <a href="#contato">Contato</a>
    </nav>
    <div class="header-actions">
        <a class="btn btn-icon" href="https://wa.me/5511971380207" target="_blank" rel="noopener" aria-label="Chamar no WhatsApp">
            <span class="btn-icon-logo" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="presentation">
                    <circle cx="12" cy="12" r="12" fill="#25D366"></circle>
                    <path fill="#fff" d="M17 14.5c-.2-.12-1.18-.58-1.36-.64-.18-.06-.31-.12-.44.12-.13.24-.5.64-.62.76-.12.12-.23.13-.44.02-.2-.12-.86-.32-1.64-1-.6-.53-.99-1.18-1.11-1.38-.12-.2-.01-.31.09-.43.1-.1.2-.23.3-.35.1-.12.13-.2.2-.34.06-.15.03-.26-.01-.37-.04-.1-.44-1.05-.6-1.44-.16-.38-.31-.33-.43-.34-.12-.01-.26-.01-.39-.01-.13 0-.35.05-.54.26-.18.2-.71.7-.71 1.7s.73 1.97.84 2.11c.1.14 1.43 2.2 3.46 3.08.48.21.85.33 1.14.42.48.15.93.13 1.28.08.39-.06 1.18-.48 1.35-.94.17-.45.17-.84.12-.93-.05-.09-.18-.15-.37-.25z"></path>
                </svg>
            </span>
            <span class="sr-only">WhatsApp</span>
        </a>
        <a class="btn btn-ghost" href="tel:5511971380207"><i class="fas fa-phone"></i> (11) 97138-0207</a>
        <a class="btn btn-gradient" href="<?= htmlspecialchars($crmUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">CRM</a>
    </div>
</header>

<main>
    <section class="hero" id="inicio">
        <div>
            <p class="eyebrow">Validação remota aprovada pela ICP-Brasil</p>
            <h1>Certificado digital com entrega em minutos</h1>
            <p class="lead">Emitimos e.CPF e e.CNPJ com biometria facial, assinatura eletrônica com validade jurídica e suporte humano 24/7 para todo o Brasil.</p>
            <div class="hero-cta">
                <a class="btn btn-gradient" href="#planos">Emitir certificado</a>
                <a class="btn btn-ghost" href="https://wa.me/5511971380207?text=Ol%C3%A1%20Selo%20ID%2C%20preciso%20de%20um%20certificado" target="_blank" rel="noopener">Chamar no WhatsApp</a>
            </div>
            <ul class="hero-stats">
                <li><span>+12k</span>Certificados emitidos</li>
                <li><span>4,9/5</span>Avaliação de clientes</li>
                <li><span>&lt;15 min</span>Validação média</li>
            </ul>
        </div>
        <div class="hero-card">
            <h3>Monitoramento em tempo real</h3>
            <div class="chip-list" aria-label="Benefícios do painel">
                <span class="chip">Biometria facial</span>
                <span class="chip">Assinatura remota</span>
                <span class="chip">Entrega A1/A3</span>
                <span class="chip">Auditoria automatizada</span>
            </div>
        </div>
    </section>

    <section class="trust" aria-label="Organizações atendidas">
        <p>Confiado por departamentos jurídicos, escritórios contábeis e operações financeiras em todo o país.</p>
        <div class="logo-row">
            <span>Receita Federal</span>
            <span>Gov.br</span>
            <span>Contabilidades</span>
            <span>Fintechs</span>
            <span>Healthtechs</span>
        </div>
    </section>

    <section id="solucoes">
        <div class="section-header">
            <h2>Segurança de dados sem complicação</h2>
            <p>Integramos onboarding digital, verificação biométrica e assinatura eletrônica com fluxos simples para o usuário final.</p>
        </div>
        <div class="grid-3">
            <article class="card">
                <h3>Cadastro assistido</h3>
                <p>Checklist responsivo, lembretes por WhatsApp e validação documental automática com IA anti-fraude.</p>
            </article>
            <article class="card">
                <h3>Compliance ativo</h3>
                <p>Logs assinados, armazenamento cifrado e aderência às diretrizes da AC Raiz e LGPD.</p>
            </article>
            <article class="card">
                <h3>Atendimento humano</h3>
                <p>Especialistas disponíveis em 15 segundos via chat, telefone ou vídeo para destravar qualquer etapa.</p>
            </article>
        </div>
    </section>

    <section class="products" id="planos">
        <div class="section-header">
            <p class="eyebrow">Planos mais vendidos</p>
            <h2>Escolha o certificado ideal</h2>
            <p>Todos os planos incluem biometria facial, assinatura remota e entrega imediata no e-mail e no app.</p>
        </div>
        <div class="grid-3">
            <article class="card">
                <span class="badge">Pessoas físicas</span>
                <h3>e.CPF A1</h3>
                <p class="price">R$ 129,00</p>
                <p>Validade de 12 meses, indicado para IRPF, eSocial e assinatura de contratos digitais.</p>
                <a class="btn btn-gradient" href="https://parceiro.gestaoar.shop/safegreen/certificado-digital-icpbrasil" target="_blank" rel="noopener">Comprar agora</a>
            </article>
            <article class="card">
                <span class="badge">Empresas</span>
                <h3>e.CNPJ A1</h3>
                <p class="price">R$ 149,00</p>
                <p>Ideal para NF-e, eSocial e obrigações fiscais. Instalação ilimitada em dispositivos autorizados.</p>
                <a class="btn btn-gradient" href="https://parceiro.gestaoar.shop/safegreen/certificado-digital-icpbrasil" target="_blank" rel="noopener">Emitir CNPJ</a>
            </article>
            <article class="card">
                <span class="badge">Operações críticas</span>
                <h3>Token A3 + Cartão</h3>
                <p class="price">A partir de R$ 190,00</p>
                <p>Dispositivo físico com múltiplos fatores, indicado para bancos, hospitais e consórcios.</p>
                <a class="btn btn-ghost" href="#contato">Solicitar proposta</a>
            </article>
        </div>
    </section>

    <section id="processo">
        <div class="section-header">
            <p class="eyebrow">Fluxo em 4 etapas</p>
            <h2>Experiência sem atrito para o usuário final</h2>
        </div>
        <div class="process-list">
            <div class="process-step">
                <strong>01</strong>
                <h4>Cadastro smart</h4>
                <p>Validação automática de documentos, selfie guiada e confirmação de dados fiscais.</p>
            </div>
            <div class="process-step">
                <strong>02</strong>
                <h4>Biometria facial</h4>
                <p>Motor biométrico homologado pela ICP-Brasil com prova de vida ativa.</p>
            </div>
            <div class="process-step">
                <strong>03</strong>
                <h4>Assinatura remota</h4>
                <p>Contrato eletrônico com IP, carimbo de tempo e trilha de auditoria.</p>
            </div>
            <div class="process-step">
                <strong>04</strong>
                <h4>Emissão imediata</h4>
                <p>Download seguro + envio automático ao ERP/CRM ou app móvel do cliente.</p>
            </div>
        </div>
    </section>

    <section class="cta-lead" id="contato">
        <div>
            <p class="eyebrow">Atendimento prioritário</p>
            <h2>Inicie seu certificado agora</h2>
            <p>Chame no chat e nosso time já vai atender em minutos.</p>
            <ul>
                <li>Suporte por WhatsApp, voz ou vídeo</li>
                <li>Equipe especializada em empresas e pessoa física</li>
                <li>Integração com chat interno da Selo ID</li>
            </ul>
        </div>
    </section>

    <section class="faq" id="faq">
        <div class="section-header">
            <p class="eyebrow">FAQ otimizado para SEO</p>
            <h2>Perguntas frequentes</h2>
        </div>
        <details>
            <summary>Quanto tempo leva para emitir o certificado digital?</summary>
            <p>Com documentos aprovados e biometria realizada, emitimos certificados A1 em menos de 15 minutos e A3 em até 24 horas úteis.</p>
        </details>
        <details>
            <summary>Preciso ir até um posto de atendimento?</summary>
            <p>Não. Toda a jornada é digital, com validação por videoconferência e assinatura eletrônica com validade jurídica.</p>
        </details>
        <details>
            <summary>Vocês atendem empresas fora de São Paulo?</summary>
            <p>Sim, realizamos emissões em todo o Brasil e em filiais internacionais via atendimento remoto.</p>
        </details>
    </section>
</main>

<div class="floating-chat" data-floating-chat>
    <button class="floating-trigger" type="button" aria-expanded="false">
        <i class="fas fa-comments"></i>
        <span>Iniciar conversa</span>
    </button>
    <div class="floating-panel" hidden>
        <header>
            <h4>Conecte-se com nossa equipe</h4>
            <button class="floating-close" type="button" aria-label="Fechar chat flutuante">&times;</button>
        </header>
        <div data-floating-intro>
            <p class="small">Preencha os dados e abriremos uma conversa aguardando um especialista.</p>
            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/chat/external-thread" data-floating-chat-form>
                <label>Nome completo
                    <input type="text" name="full_name" required placeholder="Seu nome">
                </label>
                <label>DDD
                    <input type="text" name="ddd" pattern="\d{2}" required placeholder="11">
                </label>
                <label>Telefone / WhatsApp
                    <input type="text" name="phone" pattern="[0-9\-\s]{8,15}" required placeholder="97138-0207">
                </label>
                <label>Mensagem inicial
                    <textarea name="message" required placeholder="Descreva sua necessidade"></textarea>
                </label>
                <input type="hidden" name="source" value="floating-chat">
                <button class="btn btn-gradient" type="submit">Abrir conversa</button>
                <div class="floating-feedback" data-floating-feedback role="status" aria-live="polite"></div>
            </form>
        </div>
        <div class="floating-session" data-floating-session hidden>
            <div class="floating-status" data-floating-status hidden>
                <strong data-floating-status-label></strong>
                <small data-floating-status-agent hidden></small>
            </div>
            <div class="floating-messages" data-floating-messages role="log" aria-live="polite"></div>
            <form class="floating-reply" data-floating-reply>
                <label class="sr-only" for="floating-reply-input">Envie sua mensagem</label>
                <textarea id="floating-reply-input" name="body" rows="2" data-floating-reply-input placeholder="Escreva sua mensagem" required></textarea>
                <button class="btn btn-gradient" type="submit">Enviar</button>
            </form>
        </div>
    </div>
</div>

<footer>
    <p>&copy; 2025 Selo ID Digital - Certificado ICP-Brasil | São Paulo, SP</p>
    <p>contato@seloid.com.br • (11) 97138-0207</p>
    <p><a href="#top">Voltar ao topo</a></p>
</footer>

<script>
(() => {
    const widget = document.querySelector('[data-floating-chat]');
    if (!widget) return;

    const trigger = widget.querySelector('.floating-trigger');
    const triggerLabel = trigger?.querySelector('span');
    const panel = widget.querySelector('.floating-panel');
    const closeBtn = widget.querySelector('.floating-close');
    const intro = widget.querySelector('[data-floating-intro]');
    const form = widget.querySelector('[data-floating-chat-form]');
    const feedback = widget.querySelector('[data-floating-feedback]');
    const statusBox = widget.querySelector('[data-floating-status]');
    const statusLabel = widget.querySelector('[data-floating-status-label]');
    const statusAgent = widget.querySelector('[data-floating-status-agent]');
    const session = widget.querySelector('[data-floating-session]');
    const messageList = widget.querySelector('[data-floating-messages]');
    const replyForm = widget.querySelector('[data-floating-reply]');
    const replyInput = widget.querySelector('[data-floating-reply-input]');
    const storageKey = 'seloid-floating-lead';
    const TRIGGER_CONFIRM_TIMEOUT = 5000;
    const baseEndpoint = form ? form.action.replace(/\/external-thread\/?$/, '/external-thread') : '';

    let statusTimer = null;
    let messageTimer = null;
    let lastMessageId = 0;
    let activeLeadToken = null;
    let triggerConfirming = false;
    let triggerConfirmTimer = null;

    const updateTriggerLabel = () => {
        if (!triggerLabel) return;
        if (activeLeadToken) {
            triggerLabel.textContent = triggerConfirming ? 'Finalizar agora' : 'Finalizar conversa';
            return;
        }
        triggerLabel.textContent = 'Iniciar conversa';
    };

    const resetTriggerConfirmation = () => {
        triggerConfirming = false;
        if (triggerConfirmTimer) {
            clearTimeout(triggerConfirmTimer);
            triggerConfirmTimer = null;
        }
        updateTriggerLabel();
    };

    const requestTriggerConfirmation = () => {
        triggerConfirming = true;
        updateTriggerLabel();
        if (triggerConfirmTimer) {
            clearTimeout(triggerConfirmTimer);
        }
        triggerConfirmTimer = setTimeout(() => {
            triggerConfirming = false;
            triggerConfirmTimer = null;
            updateTriggerLabel();
        }, TRIGGER_CONFIRM_TIMEOUT);
    };

    const syncTriggerVisibility = () => {
        if (!trigger) return;
        const panelVisible = panel && !panel.hasAttribute('hidden');
        const introVisible = intro && !intro.hasAttribute('hidden');
        trigger.hidden = Boolean(panelVisible && introVisible);
    };

    const togglePanel = (force) => {
        const shouldOpen = typeof force === 'boolean' ? force : panel.hasAttribute('hidden');
        if (shouldOpen) {
            panel.removeAttribute('hidden');
            trigger.setAttribute('aria-expanded', 'true');
        } else {
            panel.setAttribute('hidden', '');
            trigger.setAttribute('aria-expanded', 'false');
        }
        syncTriggerVisibility();
        resetTriggerConfirmation();
    };

    const buildStatusUrl = (token) => {
        if (!baseEndpoint || !token) return null;
        return `${baseEndpoint}/${token}/status`;
    };

    const buildMessagesUrl = (token) => {
        if (!baseEndpoint || !token) return null;
        return `${baseEndpoint}/${token}/messages`;
    };

    const persistLead = (payload) => {
        if (!payload || !payload.lead_token) return;
        try {
            const data = {
                token: payload.lead_token,
                thread: payload.thread_id || null
            };
            localStorage.setItem(storageKey, JSON.stringify(data));
        } catch (error) {
            // ignore storage errors
        }
    };

    const loadStoredLead = () => {
        try {
            const raw = localStorage.getItem(storageKey);
            if (!raw) return null;
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    };

    const clearStoredLead = () => {
        try {
            localStorage.removeItem(storageKey);
        } catch (error) {
            // ignore
        }
    };

    const escapeHtml = (value) => String(value || '').replace(/[&<>"]/g, (match) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;'
    })[match]);

    const showStatus = (state = {}) => {
        if (!statusBox || !statusLabel) return;
        statusBox.hidden = false;
        const status = String(state.status || 'pending');
        if (status === 'assigned') {
            const agent = state.agent_name ? String(state.agent_name) : '';
            statusLabel.textContent = agent
                ? `Agente: ${agent} está atendendo.`
                : 'Agente: atendimento em andamento.';
        } else {
            statusLabel.textContent = 'Conversa aberta. Aguardando um agente disponível...';
        }
        if (statusAgent) {
            statusAgent.hidden = true;
            statusAgent.textContent = '';
        }
    };

    const renderMessage = (message) => {
        if (!message) {
            return '';
        }
        const direction = message.direction || 'agent';
        const classes = ['floating-message'];
        if (direction === 'visitor') {
            classes.push('visitor');
        } else if (direction === 'system') {
            classes.push('system');
        } else {
            classes.push('agent');
        }
        const timestamp = new Date((message.created_at || Date.now() / 1000) * 1000).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        return `
            <article class="${classes.join(' ')}" data-message-id="${message.id}">
                <header>${escapeHtml(message.author || 'Equipe')} · ${escapeHtml(timestamp)}</header>
                <p>${escapeHtml(message.body || '').replace(/\n/g, '<br>')}</p>
            </article>
        `;
    };

    const resetMessages = () => {
        lastMessageId = 0;
        if (messageList) {
            messageList.innerHTML = '';
        }
    };

    const appendMessages = (messages) => {
        if (!messageList || !Array.isArray(messages) || messages.length === 0) {
            return;
        }
        const fragments = [];
        messages.forEach((message) => {
            if (message.id && message.id > lastMessageId) {
                lastMessageId = message.id;
            }
            if (message.direction === 'system') {
                return;
            }
            fragments.push(renderMessage(message));
        });
        if (fragments.length === 0) {
            return;
        }
        messageList.insertAdjacentHTML('beforeend', fragments.join(''));
        messageList.scrollTop = messageList.scrollHeight;
    };

    const stopStatusPolling = () => {
        if (statusTimer) {
            clearInterval(statusTimer);
            statusTimer = null;
        }
    };

    const pollStatusOnce = async (token) => {
        const url = buildStatusUrl(token);
        if (!url) return;
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                if (response.status === 404) {
                    clearStoredLead();
                    exitChatSession();
                }
                return;
            }
            const payload = await response.json();
            showStatus(payload);
            if (payload.status === 'assigned') {
                stopStatusPolling();
            }
        } catch (error) {
            // ignore polling errors
        }
    };

    const startStatusPolling = (token) => {
        if (!token) return;
        stopStatusPolling();
        pollStatusOnce(token);
        statusTimer = setInterval(() => pollStatusOnce(token), 8000);
    };

    const pollMessagesOnce = async (token) => {
        const baseUrl = buildMessagesUrl(token);
        if (!baseUrl) return;
        let url = baseUrl;
        if (lastMessageId > 0) {
            url += `?after=${lastMessageId}`;
        }
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                if (response.status === 404) {
                    clearStoredLead();
                    exitChatSession();
                }
                return;
            }
            const payload = await response.json();
            if (payload.lead) {
                showStatus(payload.lead);
            }
            appendMessages(payload.messages || []);
        } catch (error) {
            // ignore
        }
    };

    const stopMessagePolling = () => {
        if (messageTimer) {
            clearInterval(messageTimer);
            messageTimer = null;
        }
    };

    const startMessagePolling = (token) => {
        if (!token) return;
        stopMessagePolling();
        pollMessagesOnce(token);
        messageTimer = setInterval(() => pollMessagesOnce(token), 5000);
    };

    const enterChatSession = (token) => {
        if (!token || !session) {
            return;
        }
        activeLeadToken = token;
        if (intro) {
            intro.setAttribute('hidden', '');
        }
        session.hidden = false;
        resetMessages();
        showStatus({ status: 'pending' });
        startStatusPolling(token);
        startMessagePolling(token);
        resetTriggerConfirmation();
        syncTriggerVisibility();
    };

    function exitChatSession() {
        stopMessagePolling();
        stopStatusPolling();
        activeLeadToken = null;
        if (session) {
            session.hidden = true;
        }
        if (intro) {
            intro.removeAttribute('hidden');
        }
        if (statusBox) {
            statusBox.hidden = true;
        }
        resetMessages();
        if (replyInput) {
            replyInput.value = '';
        }
        resetTriggerConfirmation();
        syncTriggerVisibility();
    }

    trigger?.addEventListener('click', () => {
        if (activeLeadToken) {
            const isPanelHidden = panel?.hasAttribute('hidden');
            if (isPanelHidden) {
                togglePanel(true);
                return;
            }
            if (!triggerConfirming) {
                requestTriggerConfirmation();
                return;
            }
            clearStoredLead();
            exitChatSession();
            togglePanel(false);
            return;
        }
        togglePanel();
    });

    closeBtn?.addEventListener('click', () => togglePanel(false));

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (feedback) {
            feedback.textContent = 'Criando conversa...';
        }
        const formData = new FormData(form);
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            let payload = null;
            try {
                payload = await response.json();
            } catch (jsonError) {
                // ignore parse errors
            }

            if (!response.ok) {
                const message = extractErrorMessage(payload) || 'Não foi possível abrir a conversa agora. Verifique os dados e tente novamente.';
                if (feedback) {
                    feedback.textContent = message;
                }
                return;
            }

            if (feedback) {
                feedback.textContent = 'Conversa criada! Um especialista assumirá em instantes.';
            }
            form.reset();
            persistLead(payload);
            if (payload && payload.lead_token) {
                enterChatSession(payload.lead_token);
            }
        } catch (error) {
            if (feedback) {
                feedback.textContent = 'Não foi possível abrir a conversa agora. Tente novamente.';
            }
        }
    });

    replyForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeLeadToken || !replyInput) {
            return;
        }
        const messageText = replyInput.value.trim();
        if (messageText.length === 0) {
            return;
        }
        const url = buildMessagesUrl(activeLeadToken);
        if (!url) {
            return;
        }
        const submitButton = replyForm.querySelector('button');
        submitButton?.setAttribute('disabled', 'disabled');
        try {
            const formData = new FormData();
            formData.append('body', messageText);
            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok) {
                const message = extractErrorMessage(payload) || 'Não foi possível enviar a mensagem.';
                alert(message);
                return;
            }
            replyInput.value = '';
            if (payload && payload.message) {
                appendMessages([payload.message]);
            }
        } catch (error) {
            alert('Não foi possível enviar a mensagem agora.');
        } finally {
            submitButton?.removeAttribute('disabled');
        }
    });

    const stored = loadStoredLead();
    if (stored && stored.token) {
        enterChatSession(stored.token);
    }
    updateTriggerLabel();
    syncTriggerVisibility();
})();

function extractErrorMessage(payload) {
    if (!payload) return null;
    if (typeof payload.message === 'string') {
        return payload.message;
    }

    if (payload.errors && typeof payload.errors === 'object') {
        const values = Object.values(payload.errors)
            .flat()
            .filter(Boolean)
            .map(String);
        if (values.length > 0) {
            return values[0];
        }
    }

    return null;
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "Selo ID Digital",
  "url": "<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>",
  "logo": "https://selodigitalonline.com.br/imagem/e-CPF-flat.png",
  "telephone": "+55-11-97138-0207",
  "address": {
    "@type": "PostalAddress",
    "addressLocality": "São Paulo",
    "addressRegion": "SP",
    "addressCountry": "BR"
  },
  "sameAs": [
    "https://instagram.com/seloid",
    "https://facebook.com/seloid",
    "https://wa.me/5511971380207"
  ],
  "description": "Autoridade ICP-Brasil especializada em emissão de e.CPF e e.CNPJ com validação remota e suporte 24/7"
}
</script>
</body>
</html>