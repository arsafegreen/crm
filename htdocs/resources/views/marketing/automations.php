<?php
/**
 * Hub de automações de marketing (WhatsApp e e-mail).
 */
$whatsappUrl = $whatsappUrl ?? url('whatsapp') . '?standalone=1&channel=alt';
$emailAutomationsUrl = $emailAutomationsUrl ?? url('campaigns/email');
$automationToken = config('app.automation_token', '');
?>

<style>
    .marketing-grid { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); }
    .marketing-card { border:1px solid var(--border); border-radius:18px; padding:18px; background:rgba(15,23,42,0.6); box-shadow:0 12px 30px rgba(0,0,0,0.18); }
    .marketing-card h2 { margin:0 0 6px; }
    .marketing-card p { margin:0 0 12px; color:var(--muted); }
    .pill { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-size:0.8rem; background:rgba(94,234,212,0.12); color:#5eead4; }
    .cta-row { display:flex; flex-wrap:wrap; gap:10px; }
    .switch input { opacity:0; width:0; height:0; }
    .switch input:checked + .slider { background:rgba(34,197,94,0.7); }
    .switch input:checked + .slider + .knob { transform:translateX(24px); }
    .run-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.55); display:none; align-items:center; justify-content:center; z-index:9999; }
    .run-modal-backdrop.active { display:flex; }
    .run-modal { background:#0b1221; border:1px solid var(--border); border-radius:14px; width:min(820px, 95vw); max-height:80vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.35); }
    .run-modal-header { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--border); }
    .run-modal-body { padding:12px 16px; overflow:auto; }
    .run-modal-list { font-family:monospace; font-size:0.9rem; display:grid; gap:6px; }
    .run-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.75rem; }
    .run-badge.sent { background:rgba(34,197,94,0.15); color:#34d399; }
    .run-badge.failed { background:rgba(239,68,68,0.15); color:#f87171; }
    .run-badge.skipped { background:rgba(148,163,184,0.12); color:#cbd5e1; }
    .run-badge.simulated { background:rgba(96,165,250,0.14); color:#93c5fd; }
    .run-item { padding:8px 10px; border:1px solid rgba(148,163,184,0.25); border-radius:10px; background:rgba(15,23,42,0.45); }
    .block-toggle { border:1px solid; border-radius:12px; padding:10px 14px; font-weight:600; cursor:pointer; min-width:200px; text-align:left; display:flex; align-items:center; gap:8px; }
    .block-toggle.on { background:rgba(239,68,68,0.12); border-color:#f87171; color:#fecaca; }
    .block-toggle.off { background:rgba(34,197,94,0.12); border-color:#34d399; color:#bbf7d0; }
</style>

<header>
    <div>
        <h1 style="margin-bottom:8px;">Automações de marketing</h1>
        <p style="color:var(--muted);max-width:820px;">
            Centralize os fluxos automáticos. Comece pelo WhatsApp (gateway alt) e, em seguida, habilite os disparos de e-mail.
        </p>
    </div>
</header>

<div class="marketing-grid" style="margin-top:16px;">
    <div class="marketing-card">
        <div class="pill">WhatsApp · Gateway alternativo</div>
        <h2>Envios automáticos de WhatsApp</h2>
        <p>Configure aniversários, pré/pós-vencimento e retomada de inativos usando o canal alt já conectado.</p>
        <div class="cta-row">
            <a class="primary" href="<?= htmlspecialchars($whatsappUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Abrir painel WhatsApp</a>
            <a class="ghost" href="<?= url('whatsapp/config'); ?>?standalone=1" target="_blank" rel="noopener">Linhas & gateways</a>
            <button type="button" class="ghost" id="wa-simulate">Simular fila (sem enviar)</button>
        </div>
        <ul style="margin:12px 0 0 16px;color:var(--muted);">
            <li>Aniversário (dedupe por CPF, 1x ao ano)</li>
            <li>Pré-vencimento (T-30 / T-7) e vencimento (T0)</li>
            <li>Pós-vencimento e inativos (T+30 / T+60 / anos anteriores)</li>
        </ul>
    </div>

    <div class="marketing-card">
        <div class="pill" style="background:rgba(96,165,250,0.14);color:#93c5fd;">E-mail · Automação</div>
        <h2>Envios automáticos de e-mail</h2>
        <p>Selecione template, conta SMTP e janela de disparo (24–360h). Respeita limite/hora e segue mesmo com falhas pontuais.</p>
        <div class="cta-row">
            <a class="ghost" href="#email-auto">Agendar disparo</a>
            <a class="ghost" href="<?= htmlspecialchars($emailAutomationsUrl, ENT_QUOTES, 'UTF-8'); ?>">Campanhas de e-mail</a>
            <a class="ghost" href="<?= url('marketing/email-accounts'); ?>">Contas de envio</a>
        </div>
        <ul style="margin:12px 0 0 16px;color:var(--muted);">
            <li>Templates publicados (HTML ou texto)</li>
            <li>Lista padrão: Todos (inscritos)</li>
            <li>Dedupe opcional e pacing em horas</li>
        </ul>
    </div>
</div>

<section class="panel" id="email-auto" style="margin-top:18px;">
    <header style="margin-bottom:10px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
        <div>
            <h3 style="margin:0 0 6px;">Disparos de e-mail</h3>
            <p style="color:var(--muted);margin:0;">Use template publicado, escolha a conta de envio e defina a janela (24–360h). Mantém ritmo por hora da conta.</p>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span id="email-list-info" style="color:var(--muted);font-size:0.95rem;">Base: carregando…</span>
            <button type="button" class="ghost" id="email-options-refresh" title="Atualizar templates e contas">Atualizar opções</button>
        </div>
    </header>

    <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));align-items:flex-start;">
        <label style="display:flex;flex-direction:column;gap:6px;font-weight:600;">
            Template
            <select id="email-template" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <option value="">Carregando…</option>
            </select>
            <small style="color:var(--muted);">Usa versão publicada mais recente.</small>
        </label>

        <label style="display:flex;flex-direction:column;gap:6px;font-weight:600;">
            Conta de envio
            <select id="email-account" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <option value="">Carregando…</option>
            </select>
            <small style="color:var(--muted);">Respeita limite/hora configurado.</small>
        </label>

        <label style="display:flex;flex-direction:column;gap:6px;font-weight:600;">
            Base de dados
            <select id="email-source" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <option value="audience_list">Lista padrão (Todos)</option>
                <option value="crm_clients">CRM - Clientes</option>
                <option value="rfb_prospects">Base RFB</option>
            </select>
            <small style="color:var(--muted);">Escolha a origem dos e-mails (CRM ou base RFB).</small>
        </label>

        <label style="display:flex;flex-direction:column;gap:6px;font-weight:600;">
            Janela (horas)
            <input id="email-window" type="number" min="24" max="360" value="72" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
            <small style="color:var(--muted);">Distribui envios ao longo da janela.</small>
        </label>

        <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
            <input id="email-dedupe" type="checkbox" checked>
            Evitar repetidos (dedupe)
        </label>

        <div style="display:flex;flex-direction:column;gap:8px;font-weight:600;">
            Início
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <select id="email-start-mode" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <option value="now">Enviar agora</option>
                    <option value="schedule">Agendar</option>
                </select>
                <input id="email-start-date" type="date" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" disabled>
                <input id="email-start-time" type="time" value="08:00" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" disabled>
            </div>
            <small style="color:var(--muted);">Para agendar, escolha data/hora. Caso contrário, inicia imediato.</small>
        </div>
    </div>

    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:12px;">
        <button type="button" class="primary" id="email-schedule-btn">Agendar/envio</button>
        <span id="email-schedule-status" style="color:var(--muted);"></span>
    </div>
</section>

<section class="panel" style="margin-top:18px;">
    <header style="margin-bottom:10px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
        <div>
            <h3 style="margin:0 0 6px;">Bloqueio de reenvio</h3>
            <p style="color:var(--muted);margin:0;">Controle se pode reenviar para o mesmo número dentro de uma janela ajustável.</p>
        </div>
        <button type="button" id="block-toggle" class="block-toggle off" aria-pressed="false">Bloqueio desligado</button>
    </header>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <label style="display:flex;align-items:center;gap:6px;">
            Janela de bloqueio (horas):
            <input id="block-hours" type="number" min="1" max="240" value="24" style="width:90px;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
        </label>
        <button type="button" class="primary" id="block-save">Aplicar bloqueio</button>
        <span id="block-status" style="color:var(--muted);"></span>
    </div>
</section>

<section class="panel" style="margin-top:18px;">
    <header style="margin-bottom:10px;">
        <h3 style="margin:0 0 6px;">Envios de aniversário</h3>
        <p style="color:var(--muted);margin:0;">Configure agendamento ou deixe no automático. Nenhum envio é feito enquanto o automático estiver desativado.</p>
    </header>
    <div id="birthday-status" style="border:1px solid var(--border);border-radius:12px;padding:10px 12px;background:rgba(15,23,42,0.35);font-size:0.95rem;color:var(--muted);">Carregando status…</div>

    <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));align-items:flex-start;margin-top:12px;">
        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Agendar data/horário</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input id="birthday-date" type="date" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <input id="birthday-time" type="time" value="12:00" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <input id="birthday-pacing" type="number" min="5" value="40" style="width:90px;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Intervalo entre mensagens (segundos)">
                <button type="button" class="primary" id="birthday-schedule">Salvar agendamento</button>
            </div>
            <small style="color:var(--muted);">Cria uma execução única usando o gateway alt. Deduplica por CPF e envia no ritmo informado.</small>
        </div>
        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Rodar agora</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input id="birthday-run-date" type="date" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Use para simular outro dia. Se vazio, usa hoje.">
                <input id="birthday-run-time" type="time" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <input id="birthday-run-pacing" type="number" min="5" value="40" style="width:90px;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Intervalo entre mensagens (segundos)">
                <label style="display:inline-flex;align-items:center;gap:6px;color:var(--muted);"><input id="birthday-dry-run" type="checkbox">Dry-run</label>
                <button type="button" class="ghost" id="birthday-simulate">Simular</button>
                <button type="button" class="ghost" id="birthday-simulate-pdf">PDF simulado</button>
                <button type="button" class="ghost" id="birthday-run">Rodar</button>
            </div>
            <small style="color:var(--muted);">Dry-run só calcula a fila. Para envio real, deixe desmarcado.</small>
        </div>
        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Automático diário</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input id="birthday-auto-time" type="time" value="12:00" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Horário diário">
                <input id="birthday-auto-pacing" type="number" min="5" value="40" style="width:90px;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Intervalo entre mensagens (segundos)">
                <label class="switch" style="position:relative;display:inline-block;width:52px;height:28px;">
                    <input id="birthday-auto" type="checkbox">
                    <span class="slider" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:rgba(148,163,184,0.4);transition:0.2s;border-radius:34px;"></span>
                    <span class="knob" style="position:absolute;content:'';height:22px;width:22px;left:3px;bottom:3px;background:white;transition:0.2s;border-radius:50%;"></span>
                </label>
            </div>
            <small style="color:var(--muted);">Liga/desliga o job diário para aniversariantes do dia.</small>
        </div>
    </div>

    <div id="birthday-log" style="border:1px solid var(--border);border-radius:12px;padding:12px;margin-top:12px;max-height:320px;overflow:auto;font-family:monospace;font-size:0.9rem;background:rgba(15,23,42,0.4);"></div>
</section>

<section class="panel" style="margin-top:18px;">
    <header style="margin-bottom:10px;">
        <h3 style="margin:0 0 6px;">Envios de renovação</h3>
        <p style="color:var(--muted);margin:0;">Selecione vencimentos por dia/mês e defina se considera somente o ano atual ou todos os anos anteriores.</p>
    </header>
    <div id="renewal-status" style="border:1px solid var(--border);border-radius:12px;padding:10px 12px;background:rgba(15,23,42,0.35);font-size:0.95rem;color:var(--muted);">Carregando status…</div>

    <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));align-items:flex-start;margin-top:12px;">
        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Agendar data/horário</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input id="renewal-date" type="date" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <input id="renewal-time" type="time" value="12:00" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <select id="renewal-scope" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <option value="current">Ano informado</option>
                    <option value="all">Todos os anos</option>
                </select>
                <input id="renewal-pacing" type="number" min="5" value="40" style="width:90px;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Intervalo entre mensagens (segundos)">
                <button type="button" class="primary" id="renewal-schedule">Salvar agendamento</button>
            </div>
            <small style="color:var(--muted);">Agende para um dia/mês específico. Escopo "todos os anos" ignora o ano ao selecionar vencimentos.</small>
        </div>
        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Rodar agora</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input id="renewal-run-date" type="date" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Use para simular outro dia. Se vazio, usa hoje.">
                <input id="renewal-run-time" type="time" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                <select id="renewal-run-scope" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <option value="current">Ano informado</option>
                    <option value="all">Todos os anos</option>
                </select>
                <input id="renewal-run-pacing" type="number" min="5" value="40" style="width:90px;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Intervalo entre mensagens (segundos)">
                <label style="display:inline-flex;align-items:center;gap:6px;color:var(--muted);"><input id="renewal-dry-run" type="checkbox">Dry-run</label>
                <button type="button" class="ghost" id="renewal-simulate">Simular</button>
                <button type="button" class="ghost" id="renewal-simulate-pdf">PDF simulado</button>
                <button type="button" class="ghost" id="renewal-run">Rodar</button>
            </div>
            <small style="color:var(--muted);">Dry-run calcula e mostra a fila; envio real usa o canal alt.</small>
        </div>
        <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Automático diário</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input id="renewal-auto-time" type="time" value="12:00" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Horário diário">
                <select id="renewal-auto-scope" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <option value="current">Ano corrente</option>
                    <option value="all">Todos os anos</option>
                </select>
                <input id="renewal-auto-pacing" type="number" min="5" value="40" style="width:90px;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);" title="Intervalo entre mensagens (segundos)">
                <label class="switch" style="position:relative;display:inline-block;width:52px;height:28px;">
                    <input id="renewal-auto" type="checkbox">
                    <span class="slider" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:rgba(148,163,184,0.4);transition:0.2s;border-radius:34px;"></span>
                    <span class="knob" style="position:absolute;content:'';height:22px;width:22px;left:3px;bottom:3px;background:white;transition:0.2s;border-radius:50%;"></span>
                </label>
            </div>
            <small style="color:var(--muted);">Executa diariamente para os vencimentos do dia (escopo configurável).</small>
        </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;align-items:center;">
        <button type="button" class="ghost" id="renewal-forecast">Prever fila</button>
        <div id="renewal-forecast-box" style="color:var(--muted);"></div>
    </div>

    <div id="renewal-log" style="border:1px solid var(--border);border-radius:12px;padding:12px;margin-top:12px;max-height:320px;overflow:auto;font-family:monospace;font-size:0.9rem;background:rgba(15,23,42,0.4);"></div>
</section>

<section class="panel" style="margin-top:18px;">
    <header style="margin-bottom:10px;">
        <h3 style="margin:0 0 6px;">Simulação de envios (visual, sem disparar)</h3>
        <p style="color:var(--muted);margin:0;">Mostra a fila como se estivesse enviando um a um usando os botões do CRM: Felicitações, Renovação e Resgate.</p>
    </header>
    <div id="wa-sim-log" style="border:1px solid var(--border);border-radius:14px;padding:12px;max-height:320px;overflow:auto;font-family:monospace;font-size:0.9rem;background:rgba(15,23,42,0.4);"></div>
    <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;">
        <button type="button" class="primary" id="wa-sim-start">Rodar simulação</button>
        <button type="button" class="ghost" id="wa-sim-clear">Limpar</button>
    </div>
</section>

<div id="run-modal" class="run-modal-backdrop" aria-modal="true" role="dialog">
    <div class="run-modal">
        <div class="run-modal-header">
            <h3 id="run-modal-title" style="margin:0;">Envios</h3>
            <button type="button" id="run-modal-close" class="ghost" style="border:none;background:transparent;color:var(--text);font-size:1.2rem;cursor:pointer;">×</button>
        </div>
        <div class="run-modal-body">
            <div id="run-modal-status" style="color:var(--muted);margin-bottom:10px;">Processando…</div>
            <div id="run-modal-list" class="run-modal-list"></div>
        </div>
    </div>
</div>

<script>
    (() => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const statusBox = document.getElementById('birthday-status');
        const logBox = document.getElementById('birthday-log');
        const scheduleBtn = document.getElementById('birthday-schedule');
        const runBtn = document.getElementById('birthday-run');
        const runSimBtn = document.getElementById('birthday-simulate');
        const autoToggle = document.getElementById('birthday-auto');
        const autoTime = document.getElementById('birthday-auto-time');
        const autoPacing = document.getElementById('birthday-auto-pacing');
        const scheduleDate = document.getElementById('birthday-date');
        const scheduleTime = document.getElementById('birthday-time');
        const schedulePacing = document.getElementById('birthday-pacing');
        const runDate = document.getElementById('birthday-run-date');
        const runTime = document.getElementById('birthday-run-time');
        const runPacing = document.getElementById('birthday-run-pacing');
        const dryRun = document.getElementById('birthday-dry-run');
        const runSimPdfBtn = document.getElementById('birthday-simulate-pdf');

        const renewalStatusBox = document.getElementById('renewal-status');
        const renewalLogBox = document.getElementById('renewal-log');
        const renewalForecastBox = document.getElementById('renewal-forecast-box');
        const renewalScheduleBtn = document.getElementById('renewal-schedule');
        const renewalRunBtn = document.getElementById('renewal-run');
        const renewalForecastBtn = document.getElementById('renewal-forecast');
        const renewalAutoToggle = document.getElementById('renewal-auto');
        const renewalAutoTime = document.getElementById('renewal-auto-time');
        const renewalAutoPacing = document.getElementById('renewal-auto-pacing');
        const renewalAutoScope = document.getElementById('renewal-auto-scope');
        const renewalDate = document.getElementById('renewal-date');
        const renewalTime = document.getElementById('renewal-time');
        const renewalScope = document.getElementById('renewal-scope');
        const renewalPacing = document.getElementById('renewal-pacing');
        const renewalRunDate = document.getElementById('renewal-run-date');
        const renewalRunTime = document.getElementById('renewal-run-time');
        const renewalRunScope = document.getElementById('renewal-run-scope');
        const renewalRunPacing = document.getElementById('renewal-run-pacing');
        const renewalDryRun = document.getElementById('renewal-dry-run');
        const renewalSimBtn = document.getElementById('renewal-simulate');
        const renewalSimPdfBtn = document.getElementById('renewal-simulate-pdf');

        const emailTemplate = document.getElementById('email-template');
        const emailAccount = document.getElementById('email-account');
        const emailSource = document.getElementById('email-source');
        const emailWindow = document.getElementById('email-window');
        const emailDedupe = document.getElementById('email-dedupe');
        const emailStartMode = document.getElementById('email-start-mode');
        const emailStartDate = document.getElementById('email-start-date');
        const emailStartTime = document.getElementById('email-start-time');
        const emailScheduleBtn = document.getElementById('email-schedule-btn');
        const emailScheduleStatus = document.getElementById('email-schedule-status');
        const emailListInfo = document.getElementById('email-list-info');
        const emailOptionsRefresh = document.getElementById('email-options-refresh');
        let emailSources = [];
        const emailOptionsCacheKey = 'marketingEmailOptionsCache_v1';
        const automationToken = '<?= htmlspecialchars((string)$automationToken, ENT_QUOTES, 'UTF-8'); ?>';

        function setEmailUiError(message) {
            if (emailScheduleStatus) {
                emailScheduleStatus.textContent = message;
                emailScheduleStatus.classList.add('text-danger');
            }
            if (emailListInfo) {
                emailListInfo.textContent = 'Base: ' + message;
                emailListInfo.classList.add('text-danger');
            }
        }

        const btn = document.getElementById('wa-simulate');
        const simLogBox = document.getElementById('wa-sim-log');
        const startBtn = document.getElementById('wa-sim-start');
        const clearBtn = document.getElementById('wa-sim-clear');

        const runModal = document.getElementById('run-modal');
        const runModalTitle = document.getElementById('run-modal-title');
        const runModalClose = document.getElementById('run-modal-close');
        const runModalStatus = document.getElementById('run-modal-status');
        const runModalList = document.getElementById('run-modal-list');
        let runStreamTimer = null;

        const blockToggle = document.getElementById('block-toggle');
        const blockHours = document.getElementById('block-hours');
        const blockSave = document.getElementById('block-save');
        const blockStatus = document.getElementById('block-status');

        const templates = [
            { key: 'felicitacoes', label: '🎉 Felicitações', cta: 'Enviar felicitações' },
            { key: 'renovacao', label: '🔁 Renovação', cta: 'Mensagem de renovação' },
            { key: 'resgate', label: '🤝 Resgate', cta: 'Resgate de cliente' },
        ];

        const samplePeople = [
            { nome: 'Ana Souza', phone: '+55 11 90000-0001', cpf: '123.456.789-01' },
            { nome: 'Bruno Lima', phone: '+55 21 98888-0002', cpf: '234.567.890-12' },
            { nome: 'Carla Dias', phone: '+55 31 97777-0003', cpf: '345.678.901-23' },
            { nome: 'Diego Alves', phone: '+55 41 96666-0004', cpf: '456.789.012-34' },
            { nome: 'Elaine Costa', phone: '+55 51 95555-0005', cpf: '567.890.123-45' },
        ];

        function appendSimLog(line) {
            if (!simLogBox) return;
            const div = document.createElement('div');
            div.textContent = line;
            simLogBox.appendChild(div);
            simLogBox.scrollTop = simLogBox.scrollHeight;
        }

        function buildSimulatedQueue() {
            const queue = [];
            templates.forEach((tpl) => {
                samplePeople.forEach((person) => {
                    queue.push({
                        template: tpl,
                        person,
                        message: `${tpl.label} para ${person.nome} (${person.phone})`,
                    });
                });
            });
            return queue;
        }

        function runSimulation() {
            const queue = buildSimulatedQueue();
            if (!queue.length) {
                appendSimLog('Nenhum item na fila.');
                return;
            }
            appendSimLog(`Iniciando simulação: ${queue.length} mensagens (nenhum envio real).`);
            let idx = 0;
            const timer = setInterval(() => {
                if (idx >= queue.length) {
                    clearInterval(timer);
                    appendSimLog('Simulação concluída.');
                    return;
                }
                const item = queue[idx];
                appendSimLog(`[${idx + 1}/${queue.length}] ${item.message}`);
                idx += 1;
            }, 600); // ritmo visual ~1.6 msgs/seg
        }

        if (btn) {
            btn.addEventListener('click', runSimulation);
        }
        if (startBtn) {
            startBtn.addEventListener('click', runSimulation);
        }
        if (clearBtn && simLogBox) {
            clearBtn.addEventListener('click', () => {
                simLogBox.innerHTML = '';
            });
        }

        function openRunModal(title, statusText = 'Processando…') {
            if (runModalTitle) runModalTitle.textContent = title;
            if (runModalStatus) runModalStatus.textContent = statusText;
            if (runModalList) runModalList.innerHTML = '';
            if (runModal) runModal.classList.add('active');
        }

        function closeRunModal() {
            if (runModal) runModal.classList.remove('active');
        }

        if (runModalClose) {
            runModalClose.addEventListener('click', closeRunModal);
        }

        function badgeClass(status) {
            switch (status) {
                case 'sent': return 'run-badge sent';
                case 'failed': return 'run-badge failed';
                case 'skipped_duplicate':
                case 'skipped_no_phone':
                    return 'run-badge skipped';
                case 'simulated': return 'run-badge simulated';
                default: return 'run-badge skipped';
            }
        }

        function renderRunLog(log = []) {
            if (!runModalList) return;
            if (!Array.isArray(log) || log.length === 0) {
                runModalList.innerHTML = '<div style="color:var(--muted);">Nenhum item retornado.</div>';
                return;
            }
            runModalList.innerHTML = '';
            log.forEach((item, idx) => appendRunItem(item, idx));
        }

        function appendRunItem(item, idx) {
            if (!runModalList) return;
            const div = document.createElement('div');
            div.className = 'run-item';
            const badge = document.createElement('span');
            badge.className = badgeClass(item.status);
            badge.textContent = item.status || 'status';

            const title = document.createElement('div');
            const who = (item.name || item.cpf || 'Contato');
            title.innerHTML = `<strong>${(idx ?? runModalList.childElementCount) + 1}.</strong> ${who}`;

            const meta = document.createElement('div');
            meta.style.color = 'var(--muted)';
            const phone = item.phone ? ` · Tel: ${item.phone}` : '';
            const cpf = item.cpf ? ` · CPF: ${item.cpf}` : '';
            meta.textContent = `${item.campaign || ''}${phone}${cpf}`;

            const msg = document.createElement('div');
            msg.style.marginTop = '4px';
            msg.textContent = item.message || '';

            div.appendChild(badge);
            div.appendChild(title);
            div.appendChild(meta);
            if (msg.textContent) div.appendChild(msg);
            runModalList.appendChild(div);
            runModalList.scrollTop = runModalList.scrollHeight;
        }

        function renderRunLogStreaming(log = [], pacingSeconds = 1) {
            if (runStreamTimer) {
                clearTimeout(runStreamTimer);
                runStreamTimer = null;
            }
            if (!runModalList) return;
            runModalList.innerHTML = '';
            if (!Array.isArray(log) || log.length === 0) {
                runModalList.innerHTML = '<div style="color:var(--muted);">Nenhum item retornado.</div>';
                return;
            }
            const delay = Math.max(200, (pacingSeconds || 0) * 1000);
            let idx = 0;
            const step = () => {
                if (idx >= log.length) return;
                appendRunItem(log[idx], idx);
                idx += 1;
                if (idx < log.length) {
                    runStreamTimer = setTimeout(step, delay);
                }
            };
            step();
        }

        function renderRunLogStreamingWithSchedule(log = [], schedule = []) {
            if (runStreamTimer) {
                clearTimeout(runStreamTimer);
                runStreamTimer = null;
            }
            if (!runModalList) return;
            runModalList.innerHTML = '';
            if (!Array.isArray(log) || log.length === 0 || !Array.isArray(schedule) || schedule.length === 0) {
                renderRunLogStreaming(log, 1);
                return;
            }
            const count = Math.min(log.length, schedule.length);
            const base = schedule[0]?.scheduled_at ? Number(schedule[0].scheduled_at) : null;
            if (!base) {
                renderRunLogStreaming(log, 1);
                return;
            }
            for (let i = 0; i < count; i += 1) {
                const item = log[i];
                const sched = schedule[i];
                const deltaMs = Math.max(0, (Number(sched.scheduled_at || base) - base) * 1000);
                runStreamTimer = setTimeout(() => {
                    appendRunItem(item, i);
                }, deltaMs);
            }
        }

        function appendLog(line) {
            if (!logBox) return;
            const div = document.createElement('div');
            div.textContent = line;
            logBox.appendChild(div);
            logBox.scrollTop = logBox.scrollHeight;
        }

        function appendRenewalLog(line) {
            if (!renewalLogBox) return;
            const div = document.createElement('div');
            div.textContent = line;
            renewalLogBox.appendChild(div);
            renewalLogBox.scrollTop = renewalLogBox.scrollHeight;
        }

        function appendSchedule(logFn, schedule) {
            if (!schedule || !Array.isArray(schedule) || schedule.length === 0) return;
            const maxLines = 10;
            const items = schedule.slice(0, maxLines).map((item, idx) => {
                const ts = item.scheduled_at ? formatTs(item.scheduled_at) : '—';
                return `${idx + 1}. ${item.name || 'Contato'} · ${item.phone || 's/telefone'} · ${ts}`;
            });
            logFn(`Agenda simulada (${schedule.length}):`);
            items.forEach((line) => logFn('   ' + line));
            if (schedule.length > maxLines) {
                logFn(`   … +${schedule.length - maxLines} restantes`);
            }
        }

        function setText(el, text) {
            if (el) {
                el.textContent = text;
            }
        }

        function formatTs(ts) {
            if (!ts) return '—';
            return new Date(ts * 1000).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
        }

        function computeNext(startTime, serverTs) {
            if (!startTime) return null;
            const nowTs = serverTs || Math.floor(Date.now() / 1000);
            const parts = startTime.split(':');
            const h = parseInt(parts[0] || '12', 10);
            const m = parseInt(parts[1] || '0', 10);
            const base = new Date(nowTs * 1000);
            base.setHours(h, m, 0, 0);
            let target = Math.floor(base.getTime() / 1000);
            if (target <= nowTs) {
                base.setDate(base.getDate() + 1);
                target = Math.floor(base.getTime() / 1000);
            }
            return target;
        }

        function setBlockUi(enabled, hours) {
            if (!blockToggle) return;
            blockToggle.dataset.enabled = enabled ? '1' : '0';
            blockToggle.classList.toggle('on', enabled);
            blockToggle.classList.toggle('off', !enabled);
            blockToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            blockToggle.textContent = enabled ? 'Bloqueio ativo (vermelho)' : 'Bloqueio desligado (verde)';
            if (blockStatus) {
                const h = hours || blockHours?.value || 24;
                blockStatus.textContent = enabled
                    ? `Impede segundo envio por ${h}h para o mesmo telefone.`
                    : 'Bloqueio liberado: envia mesmo número sem travar.';
            }
            if (blockHours && typeof hours === 'number' && !Number.isNaN(hours)) {
                blockHours.value = hours;
            }
        }

        async function loadBlockSettings() {
            try {
                const data = await fetchJson('<?= url('marketing/automations/blocking'); ?>', { headers: { 'Accept': 'application/json' } });
                setBlockUi(Boolean(data.enabled), Number(data.window_hours || 24));
            } catch (err) {
                if (blockStatus) blockStatus.textContent = err.message || 'Falha ao carregar bloqueio.';
            }
        }

        async function safeParseJson(res) {
            const ct = res.headers.get('content-type') || '';
            const clone = res.clone();
            if (!ct.includes('application/json')) {
                const txt = await clone.text().catch(() => '');
                const lower = (txt || '').toLowerCase();
                if (lower.includes('login') || lower.includes('signin') || lower.includes('auth')) {
                    throw new Error('Sessão expirada? Resposta de login ao carregar opções.');
                }
                throw new Error(txt || `Resposta não é JSON (status ${res.status}).`);
            }
            try {
                return await res.json();
            } catch (err) {
                const txt = await clone.text().catch(() => '');
                throw new Error(txt || err.message || `Erro ${res.status}`);
            }
        }

        async function fetchJson(url, options = {}) {
            const headers = new Headers(options.headers || {});
            if (automationToken) {
                headers.set('X-Automation-Token', automationToken);
            }
            const res = await fetch(url, { ...options, headers });
            const data = await safeParseJson(res);
            if (!res.ok || (data && data.error)) {
                const msg = (data && data.error) || (typeof data === 'string' ? data : '') || `Erro ${res.status}`;
                throw new Error(msg);
            }
            return data;
        }

        async function postJson(url, payload) {
            return fetchJson(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: new URLSearchParams(payload),
            });
        }

        async function postJsonWithTimeout(url, payload, timeoutMs = 300000) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), timeoutMs);
            try {
                return await fetchJson(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: new URLSearchParams(payload),
                    signal: controller.signal,
                });
            } finally {
                clearTimeout(timer);
            }
        }

        async function downloadPdf(url, payload, filename) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/pdf',
                },
                body: new URLSearchParams(payload),
            });

            if (!res.ok) {
                const text = await res.text().catch(() => '');
                throw new Error(text || `Erro ${res.status}`);
            }

            const blob = await res.blob();
            const urlObj = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = urlObj;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(urlObj), 1000);
        }

        function setEmailStartInputs(mode) {
            const scheduled = mode === 'schedule';
            if (emailStartDate) emailStartDate.disabled = !scheduled;
            if (emailStartTime) emailStartTime.disabled = !scheduled;
        }

        function updateEmailSourceInfo(selectedKey = null) {
            const key = selectedKey || (emailSource?.value || 'audience_list');
            const info = (emailSources || []).find((s) => s.key === key);
            if (!emailListInfo) return;
            if (info) {
                const count = typeof info.count === 'number' ? ` (${info.count} contatos)` : '';
                emailListInfo.textContent = `${info.label}${count}`;
            } else {
                emailListInfo.textContent = 'Base: não definida.';
            }
        }

        function setEmailOptionsLoading(isLoading, statusText = null) {
            if (emailScheduleStatus) {
                emailScheduleStatus.textContent = isLoading ? 'Carregando opções…' : (statusText ?? emailScheduleStatus.textContent || '');
                emailScheduleStatus.classList.toggle('text-danger', false);
            }
            if (emailListInfo && isLoading) {
                emailListInfo.textContent = 'Base: carregando…';
                emailListInfo.classList.remove('text-danger');
            }
            if (emailOptionsRefresh) emailOptionsRefresh.disabled = isLoading;
            if (emailTemplate) emailTemplate.disabled = isLoading;
            if (emailAccount) emailAccount.disabled = isLoading;
            if (emailSource) emailSource.disabled = isLoading;
            if (emailWindow) emailWindow.disabled = isLoading;
            if (emailDedupe) emailDedupe.disabled = isLoading;
            if (emailStartMode) emailStartMode.disabled = isLoading;
            if (emailScheduleBtn) emailScheduleBtn.disabled = isLoading;

            if (isLoading) {
                if (emailStartDate) emailStartDate.disabled = true;
                if (emailStartTime) emailStartTime.disabled = true;
            } else {
                setEmailStartInputs(emailStartMode?.value || 'now');
            }
        }

        function cacheEmailOptions(data) {
            try {
                localStorage.setItem(emailOptionsCacheKey, JSON.stringify({ ts: Date.now(), data }));
            } catch (err) {
                console.warn('Falha ao salvar cache de opções de e-mail', err);
            }
        }

        function loadCachedEmailOptions(maxAgeMs = 15 * 60 * 1000) {
            try {
                const raw = localStorage.getItem(emailOptionsCacheKey);
                if (!raw) return null;
                const parsed = JSON.parse(raw);
                if (!parsed?.data) return null;
                if (parsed.ts && Date.now() - parsed.ts > maxAgeMs) return null;
                return parsed;
            } catch (err) {
                console.warn('Falha ao ler cache de opções de e-mail', err);
                return null;
            }
        }

        function applyEmailOptions(data) {
            if (!data) return;
            if (data.error) {
                setEmailUiError(data.error);
                return;
            }
            if (emailTemplate) {
                emailTemplate.innerHTML = '';
                (data.templates || []).forEach((tpl) => {
                    const opt = document.createElement('option');
                    opt.value = tpl.id;
                    opt.textContent = tpl.name + (tpl.subject ? ` — ${tpl.subject}` : '');
                    emailTemplate.appendChild(opt);
                });
                if ((data.templates || []).length === 0) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = 'Nenhum template disponível';
                    emailTemplate.appendChild(opt);
                }
            }
            if (emailAccount) {
                emailAccount.innerHTML = '';
                (data.accounts || []).forEach((acc) => {
                    const opt = document.createElement('option');
                    opt.value = acc.id;
                    const limit = acc.hourly_limit ? ` (${acc.hourly_limit}/h)` : '';
                    opt.textContent = `${acc.name}${limit}`;
                    emailAccount.appendChild(opt);
                });
                if ((data.accounts || []).length === 0) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = 'Nenhuma conta disponível';
                    emailAccount.appendChild(opt);
                }
            }
            if (emailSource) {
                emailSources = data.sources || [];
                emailSource.innerHTML = '';
                emailSources.forEach((src) => {
                    const opt = document.createElement('option');
                    opt.value = src.key;
                    const count = typeof src.count === 'number' ? ` (${src.count})` : '';
                    opt.textContent = `${src.label}${count}`;
                    emailSource.appendChild(opt);
                });
                const defSource = data.defaults?.source || 'audience_list';
                emailSource.value = defSource;
            }
            if (emailListInfo && (!data.sources || data.sources.length === 0)) {
                emailListInfo.textContent = 'Nenhuma base disponível.';
                emailListInfo.classList.add('text-danger');
            }
            if (emailWindow && data.defaults?.window_hours) {
                emailWindow.value = data.defaults.window_hours;
            }
            if (emailDedupe) {
                emailDedupe.checked = Boolean(data.defaults?.dedupe ?? true);
            }
            updateEmailSourceInfo();
            if (emailScheduleStatus) emailScheduleStatus.classList.remove('text-danger');
            if (emailListInfo) emailListInfo.classList.remove('text-danger');
        }

        const emailOptionsEndpoints = Array.from(new Set([
            '<?= url('marketing/automations/email/options'); ?>',
            '/marketing/automations/email/options',
            '/public/marketing/automations/email/options',
        ])).map((p) => {
            try {
                return new URL(p, window.location.origin).toString();
            } catch (_) {
                return p;
            }
        });

        async function tryFetchEmailOptions() {
            const errors = [];
            for (const endpoint of emailOptionsEndpoints) {
                try {
                    const data = await fetchJson(endpoint, { headers: { 'Accept': 'application/json' } });
                    return { data, endpoint };
                } catch (err) {
                    errors.push({ endpoint, message: err.message || String(err) });
                }
            }
            const msgs = errors.map((e) => `${e.endpoint}: ${e.message}`).join(' | ');
            throw new Error(msgs || 'Falha ao carregar opções.');
        }

        async function loadEmailOptions() {
            const cached = loadCachedEmailOptions();
            if (cached?.data) {
                applyEmailOptions(cached.data);
                setEmailOptionsLoading(false, 'Opções carregadas (cache). Atualizando…');
            } else {
                setEmailOptionsLoading(true);
            }

            try {
                const { data, endpoint } = await tryFetchEmailOptions();
                if (data && data.error) {
                    setEmailUiError(data.error);
                    setEmailOptionsLoading(false, data.error);
                    console.error('Erro ao carregar opções de e-mail (dados)', data, 'endpoint:', endpoint);
                    return;
                }
                applyEmailOptions(data);
                cacheEmailOptions(data);
                setEmailOptionsLoading(false, 'Pronto para agendar.');
                console.info('Opções de e-mail carregadas de', endpoint);
            } catch (err) {
                setEmailOptionsLoading(false, cached ? 'Usando cache. Falha ao atualizar opções.' : (err.message || 'Falha ao carregar opções.'));
                setEmailUiError(err.message || 'erro ao carregar (veja console)');
                console.error('Erro ao carregar opções de e-mail', err);
            }
        }

        async function scheduleEmailAutomation() {
            if (!emailTemplate?.value) {
                alert('Selecione o template.');
                return;
            }
            if (!emailAccount?.value) {
                alert('Selecione a conta de envio.');
                return;
            }
            const mode = emailStartMode?.value || 'now';
            if (mode === 'schedule' && !emailStartDate?.value) {
                alert('Informe a data para agendar.');
                return;
            }

            const payload = {
                template_id: emailTemplate.value,
                account_id: emailAccount.value,
                source: emailSource?.value || 'audience_list',
                list_slug: 'todos',
                window_hours: emailWindow?.value || '72',
                dedupe: emailDedupe?.checked ? '1' : '0',
                start_mode: mode,
                start_date: emailStartDate?.value || '',
                start_time: emailStartTime?.value || '08:00',
            };

            if (emailScheduleBtn) emailScheduleBtn.disabled = true;
            if (emailScheduleStatus) emailScheduleStatus.textContent = 'Agendando…';

            try {
                const res = await postJson('<?= url('marketing/automations/email/schedule'); ?>', payload);
                const start = res.start_at ? formatTs(res.start_at) : 'agora';
                const end = res.end_at ? formatTs(res.end_at) : '—';
                const perHour = res.per_hour || '—';
                if (emailScheduleStatus) {
                    emailScheduleStatus.textContent = `Agendado: ${res.recipients || 0} contatos · ${perHour}/h · início ${start} · fim estimado ${end}`;
                }
            } catch (err) {
                alert(err.message || 'Erro ao agendar/envio.');
                if (emailScheduleStatus) emailScheduleStatus.textContent = err.message || 'Erro ao agendar.';
            } finally {
                if (emailScheduleBtn) emailScheduleBtn.disabled = false;
            }
        }

        async function loadStatus() {
            try {
                const data = await fetchJson('<?= url('marketing/automations/birthday/status'); ?>', { headers: { 'Accept': 'application/json' } });

                const auto = data.auto || {};
                const manual = data.manual || {};
                const serverTs = data.server_time || Math.floor(Date.now() / 1000);

                const nextAuto = auto.enabled ? computeNext(auto.start_time, serverTs) : null;
                const nextRun = manual.scheduled_for || nextAuto;
                const lastRunAuto = auto.last_run_at || null;
                const lastRunManual = manual.last_run_at || null;
                const lastCount = (auto.last_result?.sent) || (manual.last_result?.sent) || 0;

                if (statusBox) {
                    statusBox.innerHTML = `Automático: ${auto.enabled ? 'ativado' : 'desativado'} · Próxima: ${formatTs(nextRun)} · Última auto: ${formatTs(lastRunAuto)} · Última manual: ${formatTs(lastRunManual)} (${lastCount} msgs)`;
                }

                if (autoToggle) autoToggle.checked = Boolean(auto.enabled);
                if (autoTime && auto.start_time) autoTime.value = auto.start_time;
                if (autoPacing) autoPacing.value = auto.pacing_seconds || 40;
                if (schedulePacing) schedulePacing.value = manual.pacing_seconds || auto.pacing_seconds || 40;
                if (runPacing) runPacing.value = manual.pacing_seconds || auto.pacing_seconds || 40;

                if (manual.scheduled_for && scheduleDate && scheduleTime) {
                    const d = new Date(manual.scheduled_for * 1000);
                    scheduleDate.value = d.toISOString().slice(0, 10);
                    scheduleTime.value = d.toTimeString().slice(0, 5);
                }
            } catch (err) {
                setText(statusBox, err.message || 'Falha ao carregar status.');
            }
        }

        async function loadRenewalStatus() {
            try {
                const data = await fetchJson('<?= url('marketing/automations/renewal/status'); ?>', { headers: { 'Accept': 'application/json' } });

                const auto = data.auto || {};
                const manual = data.manual || {};
                const serverTs = data.server_time || Math.floor(Date.now() / 1000);

                const autoScope = (auto.meta && auto.meta.scope) || 'current';
                const nextAuto = auto.enabled ? computeNext(auto.start_time, serverTs) : null;
                const nextRun = manual.scheduled_for || nextAuto;
                const lastAuto = auto.last_run_at || null;
                const lastManual = manual.last_run_at || null;
                const lastCount = (auto.last_result?.sent) || (manual.last_result?.sent) || 0;

                if (renewalStatusBox) {
                    renewalStatusBox.innerHTML = `Automático: ${auto.enabled ? 'ativado' : 'desativado'} (${autoScope}) · Próxima: ${formatTs(nextRun)} · Última auto: ${formatTs(lastAuto)} · Última manual: ${formatTs(lastManual)} (${lastCount} msgs)`;
                }

                if (renewalAutoToggle) renewalAutoToggle.checked = Boolean(auto.enabled);
                if (renewalAutoTime && auto.start_time) renewalAutoTime.value = auto.start_time;
                if (renewalAutoPacing) renewalAutoPacing.value = auto.pacing_seconds || 40;
                if (renewalAutoScope) renewalAutoScope.value = autoScope;
                if (renewalPacing) renewalPacing.value = manual.pacing_seconds || auto.pacing_seconds || 40;
                if (renewalRunPacing) renewalRunPacing.value = manual.pacing_seconds || auto.pacing_seconds || 40;

                if (manual.scheduled_for && renewalDate && renewalTime) {
                    const d = new Date(manual.scheduled_for * 1000);
                    renewalDate.value = d.toISOString().slice(0, 10);
                    renewalTime.value = d.toTimeString().slice(0, 5);
                }
            } catch (err) {
                setText(renewalStatusBox, err.message || 'Falha ao carregar status de renovação.');
            }
        }

        function todayStr() {
            const d = new Date();
            return d.toISOString().slice(0, 10);
        }

        if (scheduleDate) scheduleDate.value = todayStr();
        if (runDate) runDate.value = todayStr();
        if (renewalDate) renewalDate.value = todayStr();
        if (renewalRunDate) renewalRunDate.value = todayStr();
        if (emailStartDate) emailStartDate.value = todayStr();

        if (scheduleBtn) {
            scheduleBtn.addEventListener('click', async () => {
                if (!scheduleDate?.value) {
                    alert('Informe a data.');
                    return;
                }
                scheduleBtn.disabled = true;
                try {
                    const payload = {
                        date: scheduleDate.value,
                        time: scheduleTime?.value || '12:00',
                        pacing_seconds: schedulePacing?.value || 40,
                    };
                    const data = await postJson('<?= url('marketing/automations/birthday/schedule'); ?>', payload);
                    appendLog(`Agendado para ${formatTs(data.scheduled_for)} (pacing ${payload.pacing_seconds}s).`);
                    await loadStatus();
                } catch (err) {
                    alert(err.message || 'Erro ao agendar.');
                } finally {
                    scheduleBtn.disabled = false;
                }
            });
        }

        if (runBtn) {
            runBtn.addEventListener('click', async () => {
                runBtn.disabled = true;
                openRunModal('Envios de aniversário', 'Processando…');
                try {
                    const payload = {
                        date: runDate?.value || '',
                        time: runTime?.value || '',
                        pacing_seconds: runPacing?.value || 40,
                        dry_run: dryRun?.checked ? '1' : '0',
                        simulate: dryRun?.checked ? '1' : '0',
                    };
                    const isDry = payload.dry_run === '1';
                    const data = await postJsonWithTimeout('<?= url('marketing/automations/birthday/run'); ?>', payload, isDry ? 120000 : 600000);
                    const res = data.result || {};
                    const skipped = (res.skipped_duplicate || 0) + (res.skipped_no_phone || 0);
                    appendLog(`Run ${isDry ? 'dry-run' : 'real'}: ${res.total_candidates || 0} alvo(s), ${res.sent || 0} enviados, ${skipped} pulados, ${res.failed || 0} falhas.`);
                    if (runModalStatus) runModalStatus.textContent = `${isDry ? 'Dry-run (sem envio real)' : 'Envio real'} · Total ${res.total_candidates || 0} · Enviados ${res.sent || 0} · Pulados ${skipped} · Falhas ${res.failed || 0}`;
                    if (isDry) {
                        if (res.schedule && Array.isArray(res.schedule) && res.schedule.length > 0) {
                            renderRunLogStreamingWithSchedule(res.log || [], res.schedule || []);
                        } else {
                            renderRunLogStreaming(res.log || [], Number(payload.pacing_seconds) || 1);
                        }
                    } else {
                        renderRunLogStreaming(res.log || [], Number(payload.pacing_seconds) || 1);
                    }
                    await loadStatus();
                } catch (err) {
                    alert(err.message || 'Erro ao rodar.');
                    if (runModalStatus) runModalStatus.textContent = err.message || 'Erro ao rodar.';
                } finally {
                    runBtn.disabled = false;
                }
            });
        }

        if (runSimBtn) {
            runSimBtn.addEventListener('click', async () => {
                runSimBtn.disabled = true;
                try {
                    const payload = {
                        date: runDate?.value || '',
                        time: runTime?.value || '',
                        pacing_seconds: runPacing?.value || 40,
                        dry_run: '1',
                        simulate: '1',
                    };
                    const data = await postJson('<?= url('marketing/automations/birthday/run'); ?>', payload);
                    const res = data.result || {};
                    const skipped = (res.skipped_duplicate || 0) + (res.skipped_no_phone || 0);
                    appendLog(`Simulação: ${res.total_candidates || 0} alvo(s), ${skipped} pulados. Nenhum envio realizado.`);
                    appendSchedule(appendLog, res.schedule || []);
                } catch (err) {
                    alert(err.message || 'Erro ao simular.');
                } finally {
                    runSimBtn.disabled = false;
                }
            });
        }

        if (runSimPdfBtn) {
            runSimPdfBtn.addEventListener('click', async () => {
                runSimPdfBtn.disabled = true;
                try {
                    const payload = {
                        date: runDate?.value || '',
                        time: runTime?.value || '',
                        pacing_seconds: runPacing?.value || 40,
                        dry_run: '1',
                        simulate: '1',
                        export_pdf: '1',
                    };
                    await downloadPdf('<?= url('marketing/automations/birthday/run'); ?>', payload, 'simulacao_aniversario.pdf');
                    appendLog('PDF simulado gerado (dry-run, sem envio).');
                } catch (err) {
                    alert(err.message || 'Erro ao gerar PDF.');
                } finally {
                    runSimPdfBtn.disabled = false;
                }
            });
        }

        if (autoToggle) {
            autoToggle.addEventListener('change', async () => {
                autoToggle.disabled = true;
                try {
                    const payload = {
                        enabled: autoToggle.checked ? '1' : '0',
                        start_time: autoTime?.value || '12:00',
                        pacing_seconds: autoPacing?.value || 40,
                    };
                    await postJson('<?= url('marketing/automations/birthday/auto'); ?>', payload);
                    appendLog(`Automático ${autoToggle.checked ? 'ativado' : 'desativado'} (${payload.start_time}, ${payload.pacing_seconds}s).`);
                    await loadStatus();
                } catch (err) {
                    alert(err.message || 'Erro ao salvar automático.');
                    autoToggle.checked = !autoToggle.checked;
                } finally {
                    autoToggle.disabled = false;
                }
            });
        }

        if (renewalScheduleBtn) {
            renewalScheduleBtn.addEventListener('click', async () => {
                if (!renewalDate?.value) {
                    alert('Informe a data.');
                    return;
                }
                renewalScheduleBtn.disabled = true;
                try {
                    const payload = {
                        date: renewalDate.value,
                        time: renewalTime?.value || '12:00',
                        pacing_seconds: renewalPacing?.value || 40,
                        scope: renewalScope?.value || 'current',
                    };
                    const data = await postJson('<?= url('marketing/automations/renewal/schedule'); ?>', payload);
                    appendRenewalLog(`Agendado renovação ${payload.scope} para ${formatTs(data.scheduled_for)} (pacing ${payload.pacing_seconds}s).`);
                    await loadRenewalStatus();
                } catch (err) {
                    alert(err.message || 'Erro ao agendar renovação.');
                } finally {
                    renewalScheduleBtn.disabled = false;
                }
            });
        }

        if (renewalRunBtn) {
            renewalRunBtn.addEventListener('click', async () => {
                renewalRunBtn.disabled = true;
                openRunModal('Envios de renovação', 'Processando…');
                try {
                    const payload = {
                        date: renewalRunDate?.value || '',
                        time: renewalRunTime?.value || '',
                        pacing_seconds: renewalRunPacing?.value || 40,
                        dry_run: renewalDryRun?.checked ? '1' : '0',
                        simulate: renewalDryRun?.checked ? '1' : '0',
                        scope: renewalRunScope?.value || 'current',
                    };
                    const isDry = payload.dry_run === '1';
                    const data = await postJsonWithTimeout('<?= url('marketing/automations/renewal/run'); ?>', payload, isDry ? 120000 : 600000);
                    const res = data.result || {};
                    const skipped = (res.skipped_duplicate || 0) + (res.skipped_no_phone || 0);
                    appendRenewalLog(`Renovação ${isDry ? 'dry-run' : 'real'} (${payload.scope}): ${res.total_candidates || 0} alvo(s), ${res.sent || 0} enviados, ${skipped} pulados, ${res.failed || 0} falhas.`);
                    if (runModalStatus) runModalStatus.textContent = `${isDry ? 'Dry-run (sem envio real)' : 'Envio real'} · Total ${res.total_candidates || 0} · Enviados ${res.sent || 0} · Pulados ${skipped} · Falhas ${res.failed || 0}`;
                    if (isDry) {
                        if (res.schedule && Array.isArray(res.schedule) && res.schedule.length > 0) {
                            renderRunLogStreamingWithSchedule(res.log || [], res.schedule || []);
                        } else {
                            renderRunLogStreaming(res.log || [], Number(payload.pacing_seconds) || 1);
                        }
                    } else {
                        renderRunLogStreaming(res.log || [], Number(payload.pacing_seconds) || 1);
                    }
                    await loadRenewalStatus();
                } catch (err) {
                    alert(err.message || 'Erro ao rodar renovação.');
                    if (runModalStatus) runModalStatus.textContent = err.message || 'Erro ao rodar renovação.';
                } finally {
                    renewalRunBtn.disabled = false;
                }
            });
        }

        if (renewalSimBtn) {
            renewalSimBtn.addEventListener('click', async () => {
                renewalSimBtn.disabled = true;
                try {
                    const payload = {
                        date: renewalRunDate?.value || '',
                        time: renewalRunTime?.value || '',
                        pacing_seconds: renewalRunPacing?.value || 40,
                        dry_run: '1',
                        simulate: '1',
                        scope: renewalRunScope?.value || 'current',
                    };
                    const data = await postJson('<?= url('marketing/automations/renewal/run'); ?>', payload);
                    const res = data.result || {};
                    const skipped = (res.skipped_duplicate || 0) + (res.skipped_no_phone || 0);
                    appendRenewalLog(`Simulação (${payload.scope}): ${res.total_candidates || 0} alvo(s), ${skipped} pulados. Nenhum envio realizado.`);
                    appendSchedule(appendRenewalLog, res.schedule || []);
                } catch (err) {
                    alert(err.message || 'Erro ao simular renovação.');
                } finally {
                    renewalSimBtn.disabled = false;
                }
            });
        }

        if (renewalSimPdfBtn) {
            renewalSimPdfBtn.addEventListener('click', async () => {
                renewalSimPdfBtn.disabled = true;
                try {
                    const payload = {
                        date: renewalRunDate?.value || '',
                        time: renewalRunTime?.value || '',
                        pacing_seconds: renewalRunPacing?.value || 40,
                        dry_run: '1',
                        simulate: '1',
                        export_pdf: '1',
                        scope: renewalRunScope?.value || 'current',
                    };
                    await downloadPdf('<?= url('marketing/automations/renewal/run'); ?>', payload, 'simulacao_renovacao.pdf');
                    appendRenewalLog('PDF simulado gerado (dry-run, sem envio).');
                } catch (err) {
                    alert(err.message || 'Erro ao gerar PDF de renovação.');
                } finally {
                    renewalSimPdfBtn.disabled = false;
                }
            });
        }

        if (emailStartMode) {
            setEmailStartInputs(emailStartMode.value || 'now');
            emailStartMode.addEventListener('change', () => {
                setEmailStartInputs(emailStartMode.value || 'now');
            });
        }

        if (emailSource) {
            emailSource.addEventListener('change', () => updateEmailSourceInfo(emailSource.value));
        }

        if (emailScheduleBtn) {
            emailScheduleBtn.addEventListener('click', scheduleEmailAutomation);
        }

        if (emailOptionsRefresh) {
            emailOptionsRefresh.addEventListener('click', () => {
                if (emailScheduleStatus) emailScheduleStatus.textContent = 'Atualizando opções…';
                loadEmailOptions();
            });
        }

        window.addEventListener('error', (event) => {
            setEmailUiError(event.message || 'erro de script (console)');
        });

        if (blockToggle) {
            blockToggle.addEventListener('click', () => {
                const enabled = blockToggle.dataset.enabled === '1';
                setBlockUi(!enabled, Number(blockHours?.value || 24));
            });
        }

        if (blockSave) {
            blockSave.addEventListener('click', async () => {
                blockSave.disabled = true;
                try {
                    const enabled = blockToggle?.dataset.enabled === '1';
                    const hours = Number(blockHours?.value || 24);
                    const payload = {
                        enabled: enabled ? '1' : '0',
                        window_hours: hours.toString(),
                    };
                    const res = await postJson('<?= url('marketing/automations/blocking'); ?>', payload);
                    const data = res.settings || res;
                    setBlockUi(Boolean(data.enabled), Number(data.window_hours || hours));
                    if (blockStatus) blockStatus.textContent = 'Configuração de bloqueio salva.';
                } catch (err) {
                    alert(err.message || 'Erro ao salvar bloqueio.');
                } finally {
                    blockSave.disabled = false;
                }
            });
        }

        if (renewalAutoToggle) {
            renewalAutoToggle.addEventListener('change', async () => {
                renewalAutoToggle.disabled = true;
                try {
                    const payload = {
                        enabled: renewalAutoToggle.checked ? '1' : '0',
                        start_time: renewalAutoTime?.value || '12:00',
                        pacing_seconds: renewalAutoPacing?.value || 40,
                        scope: renewalAutoScope?.value || 'current',
                    };
                    await postJson('<?= url('marketing/automations/renewal/auto'); ?>', payload);
                    appendRenewalLog(`Automático renovação ${renewalAutoToggle.checked ? 'ativado' : 'desativado'} (${payload.scope}, ${payload.start_time}, ${payload.pacing_seconds}s).`);
                    await loadRenewalStatus();
                } catch (err) {
                    alert(err.message || 'Erro ao salvar automático de renovação.');
                    renewalAutoToggle.checked = !renewalAutoToggle.checked;
                } finally {
                    renewalAutoToggle.disabled = false;
                }
            });
        }

        if (renewalForecastBtn) {
            renewalForecastBtn.addEventListener('click', async () => {
                if (!renewalDate?.value) {
                    alert('Informe a data para prever.');
                    return;
                }
                renewalForecastBtn.disabled = true;
                try {
                    const params = new URLSearchParams({
                        date: renewalDate.value,
                        scope: renewalScope?.value || 'current',
                    });
                    const res = await fetch('<?= url('marketing/automations/renewal/forecast'); ?>' + '?' + params.toString(), {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await res.json();
                    if (data.error) throw new Error(data.error);
                    const r = data.result || {};
                    const line = `Previsão (${r.scope}): total ${r.total || 0}, com telefone ${r.with_phone || 0}, já enviados ${r.already_sent || 0}, restantes ${r.remaining || 0}.`;
                    setText(renewalForecastBox, line);
                    appendRenewalLog(line);
                } catch (err) {
                    alert(err.message || 'Erro ao prever fila.');
                } finally {
                    renewalForecastBtn.disabled = false;
                }
            });
        }

        loadStatus();
        loadRenewalStatus();
        loadBlockSettings();
        loadEmailOptions();
    })();
</script>
