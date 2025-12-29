<?php
declare(strict_types=1);
?>
<div class="auth-header">
    <h1><?= htmlspecialchars(config('app.name'), ENT_QUOTES, 'UTF-8'); ?></h1>
    <p>Acesse com seu e-mail e senha seguros.</p>
</div>
<?php if (!empty($notice)): ?>
    <div style="margin-bottom:18px;padding:12px 14px;border-radius:12px;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.35);color:#bbf7d0;font-size:0.9rem;">
        <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div style="margin-bottom:18px;padding:12px 14px;border-radius:12px;background:rgba(239, 68, 68, 0.1);border:1px solid rgba(239, 68, 68, 0.35);color:#fecaca;font-size:0.9rem;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>
<form method="post" action="<?= htmlspecialchars(url('auth/login'), ENT_QUOTES, 'UTF-8'); ?>" style="display:grid;gap:18px;">
    <?= csrf_field(); ?>
    <label style="display:grid;gap:6px;font-size:0.9rem;color:var(--muted);">
        <span>E-mail</span>
        <input type="email" name="email" autocomplete="email" required
               value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>"
               style="padding:12px 14px;border-radius:12px;border:1px solid rgba(148, 163, 184, 0.28);background:rgba(15,23,42,0.6);color:var(--text);">
    </label>
    <label style="display:grid;gap:6px;font-size:0.9rem;color:var(--muted);">
        <span>Senha</span>
        <input type="password" name="password" autocomplete="current-password" required
               style="padding:12px 14px;border-radius:12px;border:1px solid rgba(148, 163, 184, 0.28);background:rgba(15,23,42,0.6);color:var(--text);">
    </label>
    <button type="submit" style="appearance:none;border:none;border-radius:12px;padding:12px 16px;font-weight:600;font-size:0.95rem;background:var(--accent);color:#0f172a;cursor:pointer;transition:background 0.2s ease;">
        Entrar
    </button>
</form>
<div style="margin-top:18px;text-align:center;display:grid;gap:10px;">
    <a class="auth-link" href="<?= htmlspecialchars(url('auth/register'), ENT_QUOTES, 'UTF-8'); ?>" style="justify-content:center;">Quero solicitar acesso &rarr;</a>
</div>
