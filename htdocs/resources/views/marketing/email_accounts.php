<?php
        $accounts = $accounts ?? [];
        $summaryDefaults = [
            'totalAccounts' => count($accounts),
            'activeAccounts' => 0,
            'readyAccounts' => 0,
            'totalPerMinute' => 0,
            'totalPerHour' => 0,
            'totalDaily' => 0,
        ];
        $summary = array_merge($summaryDefaults, is_array($summary ?? null) ? $summary : []);
        $feedback = $feedback ?? null;

        $firstAccountId = null;
        foreach ($accounts as $accountRow) {
            if (isset($accountRow['id'])) {
                $firstAccountId = (int)$accountRow['id'];
                break;
            }
        }

        $inboxUrl = url('email/inbox') . ($firstAccountId ? '?account_id=' . $firstAccountId . '&standalone=1' : '?standalone=1');

        $escape = static function ($value): string {
            if ($value === null) {
                return '';
            }

            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }

            if (is_scalar($value)) {
                return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            }

            return '';
        };

        $formatInt = static function ($value): string {
            return number_format((int)$value, 0, ',', '.');
        };
        ?>

        <link rel="stylesheet" href="<?= asset('css/marketing-email-accounts.css'); ?>">

        <header class="email-accounts">
            <div>
                <h1>Contas e infraestrutura de e-mail</h1>
                <p>Centralize provedores SMTP, monitore limites e entregue acesso direto à caixa de entrada unificada. Todos os dados de contas já chegam prontos deste painel para o inbox Outlook-like.</p>
            </div>
            <div class="actions">
                <a class="primary" href="<?= $inboxUrl; ?>" rel="noopener">Abrir inbox</a>
                <a class="ghost" href="<?= url('marketing/email-accounts/create'); ?>">+ Nova conta</a>
            </div>
        </header>

        <section class="panel" style="margin-top:18px;">
            <div class="email-summary">
                <article class="email-summary-card">
                    <span>Contas cadastradas</span>
                    <strong><?= $formatInt($summary['totalAccounts'] ?? 0); ?></strong>
                </article>
                <article class="email-summary-card">
                    <span>Contas ativas</span>
                    <strong><?= $formatInt($summary['activeAccounts'] ?? 0); ?></strong>
                </article>
                <article class="email-summary-card">
                    <span>Prontas para envio</span>
                    <strong><?= $formatInt($summary['readyAccounts'] ?? 0); ?></strong>
                </article>
                <article class="email-summary-card">
                    <span>Capacidade por minuto</span>
                    <strong><?= $formatInt($summary['totalPerMinute'] ?? 0); ?></strong>
                </article>
                <article class="email-summary-card">
                    <span>Capacidade por hora</span>
                    <strong><?= $formatInt($summary['totalPerHour'] ?? 0); ?></strong>
                </article>
                <article class="email-summary-card">
                    <span>Capacidade dia</span>
                    <strong><?= $formatInt($summary['totalDaily'] ?? 0); ?></strong>
                </article>
            </div>
        </section>

        <?php if ($feedback !== null): ?>
            <section class="panel" style="margin-top:18px;border-left:4px solid <?= ($feedback['type'] ?? '') === 'success' ? '#22c55e' : '#f87171'; ?>;">
                <strong><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
            </section>
        <?php endif; ?>

        <section class="panel email-inbox-cta" style="margin-top:18px;">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
                <div>
                    <h2 style="margin:0 0 4px;">Ingressar no sistema de e-mail</h2>
                    <p style="margin:0;color:var(--muted);">A caixa de entrada consolidada vive em <code>/email/inbox</code>. Use os atalhos abaixo para evitar duplicidade de telas e levar o time direto ao ambiente Outlook-like.</p>
                </div>
                <a class="primary" href="<?= $inboxUrl; ?>" style="flex-shrink:0;">Abrir inbox agora</a>
            </div>
            <ul>
                <li>Listagens daqui já enviam remetentes e limites corretamente para o inbox.</li>
                <li>Use um único acesso para monitorar envio, respostas e follow-ups.</li>
                <li>Ao editar uma conta, as mudanças refletem no inbox instantaneamente.</li>
            </ul>
        </section>

        <section class="panel" style="margin-top:18px;">
            <?php if ($accounts === []): ?>
                <div style="text-align:center;padding:32px 12px;">
                    <h3 style="margin:0 0 8px;">Nenhuma conta configurada</h3>
                    <p style="color:var(--muted);margin:0 0 18px;">Cadastre pelo menos um provedor SMTP para habilitar o inbox e liberar disparos.</p>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
                        <a class="primary" href="<?= url('marketing/email-accounts/create'); ?>">Cadastrar conta</a>
                        <a class="ghost" href="<?= url('email/inbox'); ?>?standalone=1" target="_blank" rel="noopener">Visitar inbox</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="email-account-grid">
                    <?php foreach ($accounts as $account): ?>
                        <?php
                        $status = strtolower((string)($account['status'] ?? 'active'));
                        $warmup = strtolower((string)($account['warmup_status'] ?? 'ready'));
                        $limits = is_array($account['limits'] ?? null) ? $account['limits'] : [];
                        $tooling = is_array($account['tooling'] ?? null) ? $account['tooling'] : ['ready' => 0, 'total' => 0];
                        $template = is_array($account['template'] ?? null) ? $account['template'] : [];
                        $compliance = is_array($account['compliance'] ?? null) ? $account['compliance'] : [];
                        $footerLinks = is_array($compliance['footer'] ?? null) ? $compliance['footer'] : [];
                        $dnsStatus = is_array($compliance['dns'] ?? null) ? $compliance['dns'] : [];
                        $automation = is_array($account['automation'] ?? null) ? $account['automation'] : [];
                        $warmupPlan = is_array($account['warmup'] ?? null) ? $account['warmup'] : [];
                        $apiIntegration = is_array($account['api'] ?? null) ? $account['api'] : [];
                        ?>
                        <article class="email-account-card">
                            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                                <div>
                                    <h3 style="margin:0;"><?= $escape($account['name'] ?? 'Conta'); ?></h3>
                                    <small style="color:var(--muted);">
                                        <?= $escape($account['provider'] ?? 'custom'); ?>
                                        <?php if (!empty($account['domain'])): ?> · <?= $escape($account['domain']); ?><?php endif; ?>
                                    </small>
                                </div>
                                <div class="email-account-meta" style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                                    <span class="status-pill <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="status-pill <?= htmlspecialchars($warmup, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($warmup, ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            </div>

                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                    <strong>Remetente:</strong>
                                    <span><?= $escape($account['from_label'] ?? '—'); ?></span>
                                </div>
                                <?php if (!empty($account['reply_to'])): ?>
                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                        <strong>Reply-to:</strong>
                                        <span><?= $escape($account['reply_to']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                    <strong>SMTP:</strong>
                                    <span><?= $escape($account['smtp_summary'] ?? 'SMTP:0 · TLS'); ?></span>
                                </div>
                                <?php if (!empty($account['credentials_username'])): ?>
                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                        <strong>Usuário:</strong>
                                        <span><?= $escape($account['credentials_username']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="email-account-stats">
                                <div>
                                    <span>Limite por minuto</span>
                                    <strong><?= $escape($limits['per_minute'] ?? 'Ilimitado'); ?></strong>
                                </div>
                                <div>
                                    <span>Limite por hora</span>
                                    <strong><?= $escape($limits['per_hour'] ?? 'Ilimitado'); ?></strong>
                                </div>
                                <div>
                                    <span>Limite dia</span>
                                    <strong><?= $escape($limits['daily'] ?? 'Ilimitado'); ?></strong>
                                </div>
                                <div>
                                    <span>Burst</span>
                                    <strong><?= $escape($limits['burst'] ?? 'Ilimitado'); ?></strong>
                                </div>
                                <div>
                                    <span>Health check</span>
                                    <strong><?= $escape($account['last_health_check'] ?? '—'); ?></strong>
                                </div>
                            </div>

                            <details class="account-extra">
                                <summary>
                                    Detalhes avançados
                                    <span style="font-size:0.8rem;color:var(--muted);">Templates, DNS, automações</span>
                                </summary>
                                <div class="account-extra-content">
                                    <div class="email-account-matrix">
                                        <div>
                                            <small>Checklist VS Code</small>
                                            <strong><?= (int)($tooling['ready'] ?? 0); ?>/<?= (int)($tooling['total'] ?? 6); ?> extensões</strong>
                                            <?php if (!empty($tooling['notes'])): ?><p style="margin:4px 0 0;color:var(--muted);font-size:0.85rem;">
                                                <?= $escape($tooling['notes']); ?>
                                            </p><?php endif; ?>
                                        </div>
                                        <div>
                                            <small>Templates</small>
                                            <strong><?= $escape($template['engine'] ?? 'MJML'); ?></strong>
                                            <?php if (!empty($template['preview_url'])): ?><p style="margin:4px 0 0;"><a class="ghost" style="padding:0;color:#93c5fd;" href="<?= $escape($template['preview_url']); ?>" target="_blank" rel="noopener">Preview &rarr;</a></p><?php endif; ?>
                                        </div>
                                        <div>
                                            <small>Rodapé / links</small>
                                            <ul class="email-account-meta-list">
                                                <?php if (!empty($footerLinks['unsubscribe_url'])): ?><li><a href="<?= $escape($footerLinks['unsubscribe_url']); ?>" target="_blank" rel="noopener">Cancelar inscrição</a></li><?php endif; ?>
                                                <?php if (!empty($footerLinks['privacy_url'])): ?><li><a href="<?= $escape($footerLinks['privacy_url']); ?>" target="_blank" rel="noopener">Privacy</a></li><?php endif; ?>
                                                <?php if (!empty($footerLinks['company_address'])): ?><li><?= $escape($footerLinks['company_address']); ?></li><?php endif; ?>
                                            </ul>
                                        </div>
                                    </div>

                                    <div>
                                        <small style="color:var(--muted);">Status DNS / header List-Unsubscribe</small>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
                                            <span class="pill">SPF: <?= $escape($dnsStatus['spf'] ?? 'pending'); ?></span>
                                            <span class="pill">DKIM: <?= $escape($dnsStatus['dkim'] ?? 'pending'); ?></span>
                                            <span class="pill">DMARC: <?= $escape($dnsStatus['dmarc'] ?? 'pending'); ?></span>
                                        </div>
                                        <?php if (!empty($compliance['list_unsubscribe_header'])): ?>
                                            <p style="margin:6px 0 0;color:var(--muted);font-size:0.85rem;">Header: <code><?= $escape($compliance['list_unsubscribe_header']); ?></code></p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="email-account-matrix">
                                        <div>
                                            <small>Automação</small>
                                            <strong><?= $escape($automation['bounce_policy_label'] ?? 'Smart'); ?></strong>
                                            <ul class="email-account-meta-list">
                                                <li>Double opt-in: <?= !empty($automation['double_opt_in']) ? 'Ativo' : 'Desligado'; ?></li>
                                                <li>Logs: <?= (int)($automation['log_retention_days'] ?? 0); ?> dias · Limpeza <?= $escape($automation['list_cleaning_label'] ?? 'weekly'); ?></li>
                                                <li>Teste: <?= !empty($automation['test_recipient']) ? $escape($automation['test_recipient']) : 'N/D'; ?></li>
                                                <li>A/B: <?= !empty($automation['ab_testing']) ? 'Sim' : 'Não'; ?> · Reputação: <?= !empty($automation['reputation_monitoring']) ? 'Monitorando' : '—'; ?></li>
                                            </ul>
                                        </div>
                                        <div>
                                            <small>Warm-up</small>
                                            <strong><?= $escape($warmupPlan['target_label'] ?? 'Planejar'); ?></strong>
                                            <?php if (!empty($warmupPlan['plan'])): ?><p style="margin:4px 0 0;color:var(--muted);font-size:0.85rem;">
                                                <?= nl2br($escape($warmupPlan['plan'])); ?>
                                            </p><?php endif; ?>
                                        </div>
                                        <div>
                                            <small>Integração API</small>
                                            <strong><?= $escape($apiIntegration['provider_label'] ?? 'NONE'); ?></strong>
                                            <ul class="email-account-meta-list">
                                                <?php if (!empty($apiIntegration['region'])): ?><li>Região: <?= $escape($apiIntegration['region']); ?></li><?php endif; ?>
                                                <?php if (!empty($apiIntegration['key_id'])): ?><li>Key: <?= $escape($apiIntegration['key_id']); ?></li><?php endif; ?>
                                                <?php if (!empty($apiIntegration['status'])): ?><li>Status: <?= $escape($apiIntegration['status']); ?></li><?php endif; ?>
                                            </ul>
                                        </div>
                                    </div>

                                    <?php if (!empty($account['notes'])): ?>
                                        <p style="margin:0;color:var(--muted);">
                                            <?= nl2br($escape($account['notes'])); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </details>

                            <footer style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
                                <a class="primary" href="<?= url('email/inbox'); ?>?account_id=<?= (int)($account['id'] ?? 0); ?>&standalone=1" target="_blank" rel="noopener">Abrir inbox desta conta</a>
                                <a class="ghost" href="<?= url('marketing/email-accounts/' . (int)($account['id'] ?? 0) . '/edit'); ?>">Editar</a>
                                <form method="post" action="<?= url('marketing/email-accounts/' . (int)($account['id'] ?? 0) . '/archive'); ?>" onsubmit="return confirm('Arquivar esta conta? Ela deixará de ser usada em novas campanhas.');" style="margin:0;">
                                    <?= csrf_field(); ?>
                                    <button type="submit" class="ghost" style="color:#f97316;">Arquivar</button>
                                </form>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel" style="margin-top:18px;">
            <div class="guide-grid">
                <article class="guide-card">
                    <h3>Integração única</h3>
                    <p>Todos os fluxos de envio usam o inbox em <code>/email/inbox</code>. Evite telas duplicadas compartilhando apenas esse link.</p>
                </article>
                <article class="guide-card">
                    <h3>Saúde e limites</h3>
                    <p>Revisar limites hora/dia antes de disparos grandes garante que o hub Outlook-like mantenha reputação alta.</p>
                </article>
                <article class="guide-card">
                    <h3>Governança LGPD</h3>
                    <p>Os dados de footer, links e headers cadastrados aqui são refletidos automaticamente nos templates do inbox.</p>
                </article>
            </div>
        </section>
