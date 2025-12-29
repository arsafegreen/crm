<header>
    <div>
        <h1>Campanhas de e-mail por vencimento</h1>
        <p>Segmente de forma rápida quem está com certificado vencendo e prepare uma régua de reativação.</p>
    </div>
    <div>
        <a href="<?= url(); ?>" style="color:var(--accent);font-weight:600;text-decoration:none;">&larr; Voltar ao dashboard</a>
    </div>
</header>

<?php if (!empty($feedback)): ?>
    <div class="panel" style="border-left:4px solid <?= match ($feedback['type'] ?? 'info') {
        'success' => '#22c55e',
        'error' => '#f87171',
        'warning' => '#fbbf24',
        default => '#38bdf8',
    }; ?>; margin-bottom:28px;">
        <strong style="display:block;margin-bottom:6px;">Campanha</strong>
        <p style="margin:0;"><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if (!empty($feedback['meta']) && isset($feedback['meta']['messages'])): ?>
            <p style="margin:6px 0 0;font-size:0.85rem;color:var(--muted);">
                Destinatários gerados: <strong><?= (int)$feedback['meta']['messages']; ?></strong>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="panel" style="margin-bottom:28px;">
    <h2 style="margin-top:0;">Régua automática</h2>
    <p style="margin:0 0 16px;color:var(--muted);font-size:0.95rem;">
        Configure o envio automático de e-mails de renovação e aniversário. Offsets positivos = dias antes do vencimento/data; negativos = dias depois; 0 = no dia.
    </p>
    <form method="post" action="<?= url('campaigns/email/automation'); ?>" style="display:grid;gap:18px;">
        <input type="hidden" name="_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
            <?php $renewalOffsets = $automationConfig['renewal']['offsets'] ?? [50,30,15,5,0,-5,-15,-30]; ?>
            <div style="border:1px solid var(--border);border-radius:14px;padding:16px;background:rgba(15,23,42,0.6);display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <strong>Renovação</strong>
                    <label style="display:flex;gap:8px;align-items:center;font-size:0.85rem;color:var(--muted);">
                        <input type="checkbox" name="automation_renewal_enabled" value="1" <?= !empty($automationConfig['renewal']['enabled']) ? 'checked' : ''; ?>> Ativar
                    </label>
                </div>
                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                    Template
                    <select name="automation_renewal_template_id" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);">
                        <option value="0">Padrão do CRM</option>
                        <?php foreach (($templates ?? []) as $template): ?>
                            <option value="<?= (int)$template['id']; ?>" <?= (int)($automationConfig['renewal']['template_id'] ?? 0) === (int)$template['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($template['name'] ?? ('Modelo #' . (int)$template['id']), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                    Conta de envio
                    <select name="automation_renewal_sender_account_id" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);">
                        <option value="0">Conta padrão (SMTP_*)</option>
                        <?php foreach (($emailAccounts ?? []) as $acc): ?>
                            <option value="<?= (int)$acc['id']; ?>" <?= (int)($automationConfig['renewal']['sender_account_id'] ?? 0) === (int)$acc['id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars(($acc['name'] ?? 'Conta') . ' · ' . ($acc['from_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                    Offsets (dias)
                    <input type="text" name="automation_renewal_offsets" value="<?= htmlspecialchars(implode(',', $renewalOffsets), ENT_QUOTES, 'UTF-8'); ?>" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);" placeholder="50,30,15,5,0,-5,-15,-30">
                </label>
            </div>

            <?php $birthdayOffsets = $automationConfig['birthday']['offsets'] ?? [0]; ?>
            <div style="border:1px solid var(--border);border-radius:14px;padding:16px;background:rgba(15,23,42,0.6);display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <strong>Aniversário</strong>
                    <label style="display:flex;gap:8px;align-items:center;font-size:0.85rem;color:var(--muted);">
                        <input type="checkbox" name="automation_birthday_enabled" value="1" <?= !empty($automationConfig['birthday']['enabled']) ? 'checked' : ''; ?>> Ativar
                    </label>
                </div>
                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                    Template
                    <select name="automation_birthday_template_id" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);">
                        <option value="0">Padrão do CRM</option>
                        <?php foreach (($templates ?? []) as $template): ?>
                            <option value="<?= (int)$template['id']; ?>" <?= (int)($automationConfig['birthday']['template_id'] ?? 0) === (int)$template['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($template['name'] ?? ('Modelo #' . (int)$template['id']), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                    Conta de envio
                    <select name="automation_birthday_sender_account_id" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);">
                        <option value="0">Conta padrão (SMTP_*)</option>
                        <?php foreach (($emailAccounts ?? []) as $acc): ?>
                            <option value="<?= (int)$acc['id']; ?>" <?= (int)($automationConfig['birthday']['sender_account_id'] ?? 0) === (int)$acc['id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars(($acc['name'] ?? 'Conta') . ' · ' . ($acc['from_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                    Offsets (dias)
                    <input type="text" name="automation_birthday_offsets" value="<?= htmlspecialchars(implode(',', $birthdayOffsets), ENT_QUOTES, 'UTF-8'); ?>" style="padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);" placeholder="0">
                </label>
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;color:var(--muted);font-size:0.85rem;">
            <span>Essas configurações alimentam o envio automático (renovação e aniversário). Use números separados por vírgula: 50,30,15,5,0,-5,...</span>
            <button class="primary" type="submit">Salvar régua automática</button>
        </div>
    </form>
</div>

<div class="panel" style="margin-bottom:28px;">
    <h2 style="margin-top:0;">Escolher filtros de vencimento</h2>
            <form method="get" action="<?= url('campaigns/email'); ?>" style="display:grid;gap:18px;">
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Mês de vencimento
                <select name="month" required style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <option value="">Selecione</option>
                    <?php foreach ($monthOptions as $value => $label): ?>
                        <option value="<?= $value; ?>" <?= (int)$selectedMonth === (int)$value ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Âmbito
                <div style="display:flex;gap:16px;padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);">
                    <label style="display:flex;gap:8px;align-items:center;">
                        <input type="radio" name="scope" value="current" <?= $scope === 'all' ? '' : 'checked'; ?>>
                        Apenas ano selecionado
                    </label>
                    <label style="display:flex;gap:8px;align-items:center;">
                        <input type="radio" name="scope" value="all" <?= $scope === 'all' ? 'checked' : ''; ?>>
                        Todos os anos
                    </label>
                </div>
            </div>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Ano de referência (quando filtrar ano específico)
                <select name="year" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <?php foreach ($yearOptions as $yearOption): ?>
                        <option value="<?= $yearOption; ?>" <?= (int)$selectedYear === (int)$yearOption ? 'selected' : ''; ?>><?= $yearOption; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                Modelo de mensagem
                <select name="template_id" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                    <option value="0">Usar conteúdo padrão</option>
                    <?php foreach (($templates ?? []) as $template): ?>
                        <option value="<?= (int)$template['id']; ?>" <?= (int)$selectedTemplateId === (int)$template['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($template['name'] ?? ('Modelo #' . (int)$template['id']), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ((int)$selectedTemplateId === 0): ?>
                    <span style="font-size:0.78rem;color:var(--muted);">O template padrão do CRM será carregado.</span>
                <?php elseif ($selectedTemplate !== null): ?>
                    <span style="font-size:0.78rem;color:var(--muted);">
                        <?php if (!empty($selectedTemplateVersion['version'])): ?>
                            Versão v<?= (int)$selectedTemplateVersion['version']; ?> ·
                        <?php endif; ?>
                        Atualizado <?= format_datetime($selectedTemplate['updated_at'] ?? null); ?>
                    </span>
                <?php endif; ?>
            </label>
        </div>
        <div style="display:flex;justify-content:flex-end;">
            <button class="primary" type="submit">Gerar prévia</button>
        </div>
    </form>
</div>

<?php if ($selectedMonth >= 1 && $selectedMonth <= 12): ?>
    <div class="panel" style="margin-bottom:28px;">
        <h2 style="margin-top:0;">Prévia de destinatários</h2>
        <?php if ($previewCount === 0): ?>
            <p style="margin:0;color:var(--muted);">Nenhum cliente encontrado para <?= htmlspecialchars($monthName ?? 'o mês selecionado', ENT_QUOTES, 'UTF-8'); ?>.</p>
        <?php else: ?>
            <p style="margin:0 0 16px;color:var(--muted);">
                Encontramos <strong><?= number_format($previewCount, 0, ',', '.'); ?></strong> clientes com certificado vencendo em <?= htmlspecialchars($monthName ?? '', ENT_QUOTES, 'UTF-8'); ?>
                <?= $scope === 'all' ? ' (considerando todos os anos)' : ' (apenas para o ano escolhido)'; ?>.
                <?php if ($previewOverflow): ?>
                    Exibindo os primeiros <?= count($previewSample); ?> registros abaixo.
                <?php endif; ?>
            </p>
            <div style="overflow-x:auto; margin:0 -28px; padding:0 28px;">
                <table style="width:100%;border-collapse:collapse;min-width:860px;">
                    <thead>
                        <tr style="text-align:left;font-size:0.78rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted);">
                            <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Cliente</th>
                            <th style="padding:14px 18px;border-bottom:1px solid var(--border);">E-mail</th>
                            <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Status</th>
                            <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Vencimento</th>
                            <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Parceiro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewSample as $row): ?>
                            <tr style="border-bottom:1px solid rgba(148,163,184,0.12);">
                                <td style="padding:14px 18px;">
                                    <strong style="display:block;"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span style="display:block;font-size:0.8rem;color:var(--muted);">CPF/CNPJ: <?= htmlspecialchars($row['document'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td style="padding:14px 18px;color:var(--muted);font-size:0.88rem;">
                                    <?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="padding:14px 18px;color:var(--muted);font-size:0.88rem;">
                                    <?= htmlspecialchars($statusLabels[$row['status']] ?? ucfirst(str_replace('_', ' ', (string)$row['status'])), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="padding:14px 18px;color:var(--muted);font-size:0.88rem;">
                                    <?= format_date($row['expires_at'] ?? null); ?>
                                </td>
                                <td style="padding:14px 18px;color:var(--muted);font-size:0.88rem;">
                                    <?= !empty($row['partner']) ? htmlspecialchars($row['partner'], ENT_QUOTES, 'UTF-8') : '<span style="color:rgba(148,163,184,0.6);">-</span>'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($previewCount > 0): ?>
        <div class="panel" style="margin-bottom:28px;">
            <h2 style="margin-top:0;">Configurar mensagem</h2>
            <form method="post" action="<?= url('campaigns/email'); ?>" style="display:grid;gap:18px;">
                <input type="hidden" name="_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="month" value="<?= (int)$selectedMonth; ?>">
                <input type="hidden" name="scope" value="<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="year" value="<?= (int)$selectedYear; ?>">
                <input type="hidden" name="template_id" value="<?= (int)$selectedTemplateId; ?>">

                <?php if ($selectedTemplate !== null): ?>
                    <div style="padding:16px;border-radius:14px;border:1px solid rgba(56,189,248,0.35);background:rgba(15,23,42,0.55);display:flex;flex-direction:column;gap:6px;font-size:0.85rem;color:var(--muted);">
                        <strong style="color:var(--accent);font-size:0.9rem;">Modelo selecionado: <?= htmlspecialchars($selectedTemplate['name'] ?? ('#' . (int)$selectedTemplateId), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span>Assunto sugerido: <?= htmlspecialchars($selectedTemplate['subject'] ?? $defaultSubject, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if (!empty($selectedTemplateVersion['version'])): ?>
                            <span>Versão publicada: v<?= (int)$selectedTemplateVersion['version']; ?></span>
                        <?php endif; ?>
                        <span>Atualizado <?= format_datetime($selectedTemplate['updated_at'] ?? null); ?></span>
                        <div>
                            <a href="<?= url('templates/' . (int)$selectedTemplate['id'] . '/edit'); ?>" style="color:var(--accent);text-decoration:none;font-weight:600;">Editar modelo</a>
                        </div>
                    </div>
                <?php elseif (!empty($templates)): ?>
                    <div style="padding:16px;border-radius:14px;border:1px dashed rgba(56,189,248,0.35);background:rgba(15,23,42,0.4);font-size:0.82rem;color:var(--muted);">
                        <strong style="color:var(--accent);display:block;margin-bottom:6px;">Modelos disponíveis</strong>
                        <span>Use a seleção acima para carregar automaticamente um dos modelos cadastrados.</span>
                    </div>
                <?php endif; ?>

                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                    Assunto do e-mail
                    <input type="text" name="subject" value="<?= htmlspecialchars($defaultSubject, ENT_QUOTES, 'UTF-8'); ?>" required style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                </label>

                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);">
                    Conteúdo HTML
                    <textarea name="body_html" rows="8" style="padding:14px;border-radius:14px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);resize:vertical;min-height:220px;"><?= htmlspecialchars($defaultBody, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </label>

                <label style="display:flex;flex-direction:column;gap:8px;font-size:0.85rem;color:var(--muted);max-width:280px;">
                    Agendar envio (opcional)
                    <input type="datetime-local" name="scheduled_for" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.6);color:var(--text);">
                </label>

                <div style="font-size:0.82rem;color:var(--muted);background:rgba(15,23,42,0.55);border:1px dashed rgba(56,189,248,0.35);border-radius:14px;padding:16px;">
                    <strong style="display:block;color:var(--accent);margin-bottom:8px;">Placeholders suportados</strong>
                    <p style="margin:0;">Você pode usar <code>{{client_name}}</code>, <code>{{certificate_expires_at_formatted}}</code> e <code>{{client_status}}</code> para personalizar o conteúdo.</p>
                </div>

                <div style="display:flex;justify-content:flex-end;align-items:center;gap:16px;">
                    <span style="color:var(--muted);font-size:0.85rem;">Destinatários: <?= number_format($previewCount, 0, ',', '.'); ?></span>
                    <button class="primary" type="submit">Salvar campanha</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (!empty($recentCampaigns)): ?>
    <div class="panel">
        <h2 style="margin-top:0;">Campanhas recentes</h2>
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
            <?php foreach ($recentCampaigns as $campaign): ?>
                <div style="border:1px solid var(--border);border-radius:16px;padding:18px;background:rgba(15,23,42,0.6);">
                    <strong style="display:block;margin-bottom:6px;"><?= htmlspecialchars($campaign['name'] ?? 'Campanha', ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p style="margin:0 0 10px;color:var(--muted);font-size:0.85rem;">
                        Status: <strong><?= htmlspecialchars($campaign['status'] ?? 'draft', ENT_QUOTES, 'UTF-8'); ?></strong><br>
                        Criada em <?= format_datetime($campaign['created_at'] ?? null); ?>
                    </p>
                    <p style="margin:0 0 10px;color:var(--muted);font-size:0.85rem;">
                        Destinatários: <strong><?= number_format((int)($campaign['messages_count'] ?? 0), 0, ',', '.'); ?></strong><br>
                        Enviados: <strong><?= number_format((int)($campaign['sent_count'] ?? 0), 0, ',', '.'); ?></strong>
                    </p>
                    <?php if (!empty($campaign['template_version_id'])): ?>
                        <p style="margin:0 0 10px;color:rgba(148,163,184,0.78);font-size:0.8rem;">
                            Template: <?= htmlspecialchars($campaign['template_name'] ?? ('Modelo #' . (int)$campaign['template_id']), ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (!empty($campaign['template_version_number'])): ?>
                                · versão v<?= (int)$campaign['template_version_number']; ?>
                            <?php endif; ?>
                            · ID <?= (int)$campaign['template_version_id']; ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($campaign['filters']['month'])): ?>
                        <p style="margin:0;color:rgba(148,163,184,0.75);font-size:0.78rem;">
                            Filtro: mês <?= str_pad((string)$campaign['filters']['month'], 2, '0', STR_PAD_LEFT); ?>
                            <?= ($campaign['filters']['scope'] ?? '') === 'all' ? '(todos os anos)' : '(ano atual)'; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($recentMessages)): ?>
    <div class="panel" style="margin-top:28px;">
        <h2 style="margin-top:0;">Mensagens recentes da régua</h2>
        <p style="margin:0 0 12px;color:var(--muted);font-size:0.88rem;">
            Acompanhe as últimas mensagens geradas e confirme se cada uma está fixada na versão certa do modelo.
        </p>
        <div style="overflow-x:auto;margin:0 -28px;padding:0 28px;">
            <table style="width:100%;border-collapse:collapse;min-width:860px;">
                <thead>
                    <tr style="text-align:left;font-size:0.78rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted);">
                        <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Mensagem</th>
                        <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Campanha</th>
                        <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Destinatário</th>
                        <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Status</th>
                        <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Template</th>
                        <th style="padding:14px 18px;border-bottom:1px solid var(--border);">Atualização</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentMessages as $message): ?>
                        <tr style="border-bottom:1px solid rgba(148,163,184,0.12);">
                            <td style="padding:14px 18px;font-size:0.85rem;">
                                <strong>#<?= (int)($message['id'] ?? 0); ?></strong><br>
                                <span style="color:var(--muted);">Canal: <?= htmlspecialchars($message['channel'] ?? 'email', ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td style="padding:14px 18px;font-size:0.85rem;">
                                <?= htmlspecialchars($message['campaign_name'] ?? 'Campanha', ENT_QUOTES, 'UTF-8'); ?><br>
                                <span style="color:var(--muted);">ID <?= (int)($message['campaign_id'] ?? 0); ?></span>
                            </td>
                            <td style="padding:14px 18px;font-size:0.85rem;">
                                <?= htmlspecialchars($message['client_name'] ?? 'Cliente', ENT_QUOTES, 'UTF-8'); ?><br>
                                <span style="color:var(--muted);font-size:0.78rem;"><?= htmlspecialchars($message['client_email'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td style="padding:14px 18px;font-size:0.85rem;">
                                <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:rgba(56,189,248,0.12);color:var(--accent);font-weight:600;font-size:0.78rem;">
                                    <?= htmlspecialchars($message['status'] ?? 'pending', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php if (!empty($message['scheduled_for'])): ?>
                                    <span style="display:block;margin-top:6px;color:var(--muted);font-size:0.78rem;">
                                        Agendado <?= format_datetime($message['scheduled_for']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:14px 18px;font-size:0.85rem;">
                                <?php if (!empty($message['template_version_id'])): ?>
                                    <?php if (!empty($message['template_version_number'])): ?>
                                        Versão v<?= (int)$message['template_version_number']; ?> ·
                                    <?php else: ?>
                                        Versão vinculada ·
                                    <?php endif; ?>
                                    ID <?= (int)$message['template_version_id']; ?>
                                <?php else: ?>
                                    <span style="color:var(--muted);">Não associado</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:14px 18px;font-size:0.85rem;">
                                <?= format_datetime($message['updated_at'] ?? $message['created_at'] ?? null); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
