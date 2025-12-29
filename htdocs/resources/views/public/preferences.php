<?php
/** @var array $contact */
/** @var array $categories */
/** @var array $preferences */
/** @var string $token */
/** @var array|null $feedback */
?>
<div class="contact-card">
    <div>
        <strong><?= htmlspecialchars(($contact['first_name'] ?? '') !== '' ? ($contact['first_name'] . ' ' . ($contact['last_name'] ?? '')) : ($contact['email'] ?? 'Contato')); ?></strong>
        <p><?= htmlspecialchars($contact['email'] ?? ''); ?></p>
        <p class="muted">Status atual: <?= htmlspecialchars((string)($contact['consent_status'] ?? 'pending')); ?></p>
    </div>
    <div>
        <a class="logs-link" href="<?= htmlspecialchars(url('preferences/' . rawurlencode($token) . '/logs'), ENT_QUOTES, 'UTF-8'); ?>">Baixar registros</a>
    </div>
</div>

<?php if ($feedback !== null): ?>
    <div class="feedback feedback-<?= htmlspecialchars($feedback['type']); ?>">
        <?= htmlspecialchars($feedback['message']); ?>
    </div>
<?php endif; ?>

<form method="POST" action="<?= htmlspecialchars(url('preferences/' . rawurlencode($token)), ENT_QUOTES, 'UTF-8'); ?>" class="preferences-form">
    <?= csrf_field(); ?>
    <div class="grid">
        <?php foreach ($categories as $key => $category): ?>
            <?php $checked = $preferences[$key] ?? ($category['default'] ?? true); ?>
            <label class="pref-card">
                <input type="checkbox" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" value="1" <?= $checked ? 'checked' : ''; ?>>
                <span class="pref-toggle" aria-hidden="true"></span>
                <span class="pref-content">
                    <strong><?= htmlspecialchars($category['label']); ?></strong>
                    <?php if (!empty($category['description'])): ?>
                        <small><?= htmlspecialchars($category['description']); ?></small>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
    <button type="submit">Atualizar preferências</button>
</form>

<style>
    .contact-card {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        border: 1px solid rgba(148, 163, 184, 0.3);
        border-radius: 16px;
        background: rgba(15, 23, 42, 0.65);
    }
    .contact-card p {
        margin: 4px 0;
    }
    .contact-card .muted {
        color: var(--muted);
        font-size: 0.85rem;
    }
    .logs-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px 16px;
        border-radius: 999px;
        border: 1px solid rgba(56, 189, 248, 0.4);
        color: var(--accent);
        text-decoration: none;
        font-weight: 600;
    }
    .logs-link:hover {
        color: var(--accent-hover);
        border-color: rgba(56, 189, 248, 0.8);
    }
    .feedback {
        margin-top: 18px;
        padding: 14px 16px;
        border-radius: 12px;
        font-size: 0.95rem;
    }
    .feedback-success {
        background: rgba(34, 197, 94, 0.18);
        border: 1px solid rgba(34, 197, 94, 0.4);
        color: #bbf7d0;
    }
    .preferences-form {
        margin-top: 24px;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }
    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
    }
    .pref-card {
        position: relative;
        display: flex;
        gap: 12px;
        padding: 18px;
        border-radius: 16px;
        border: 1px solid rgba(148, 163, 184, 0.28);
        background: var(--panel-alt);
        cursor: pointer;
        min-height: 120px;
    }
    .pref-card input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .pref-toggle {
        width: 46px;
        height: 26px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.45);
        position: relative;
        flex-shrink: 0;
        margin-top: 8px;
    }
    .pref-toggle::after {
        content: '';
        position: absolute;
        top: 2px;
        left: 2px;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: rgba(148, 163, 184, 0.85);
        transition: transform 0.2s ease, background 0.2s ease;
    }
    .pref-card input:checked + .pref-toggle {
        border-color: rgba(56, 189, 248, 0.6);
    }
    .pref-card input:checked + .pref-toggle::after {
        transform: translateX(20px);
        background: var(--accent);
    }
    .pref-content {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .pref-content strong {
        font-size: 1rem;
    }
    .pref-content small {
        color: var(--muted);
        line-height: 1.5;
    }
    .preferences-form button {
        align-self: flex-end;
        padding: 12px 24px;
        border-radius: 999px;
        border: none;
        background: linear-gradient(135deg, var(--accent), var(--accent-hover));
        color: #0f172a;
        font-weight: 600;
        cursor: pointer;
    }
    .preferences-form button:hover {
        filter: brightness(1.05);
    }
</style>
