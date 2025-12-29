<?php
declare(strict_types=1);
?>
<div class="auth-header">
    <h1>Solicitar acesso</h1>
    <p>Cadastre seus dados para que o administrador libere seu login.</p>
</div>
<?php if (!empty($error)): ?>
    <div style="margin-bottom:18px;padding:12px 14px;border-radius:12px;background:rgba(239, 68, 68, 0.1);border:1px solid rgba(239, 68, 68, 0.35);color:#fecaca;font-size:0.9rem;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>
<form method="post" action="<?= htmlspecialchars(url('auth/register'), ENT_QUOTES, 'UTF-8'); ?>" style="display:grid;gap:18px;">
    <?= csrf_field(); ?>
    <label style="display:grid;gap:6px;font-size:0.9rem;color:var(--muted);">
        <span>Nome completo</span>
        <input type="text" name="name" required autocomplete="name"
               value="<?= htmlspecialchars((string)($values['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
               style="padding:12px 14px;border-radius:12px;border:1px solid rgba(148, 163, 184, 0.28);background:rgba(15,23,42,0.6);color:var(--text);">
    </label>
    <label style="display:grid;gap:6px;font-size:0.9rem;color:var(--muted);">
        <span>E-mail corporativo</span>
        <input type="email" name="email" required autocomplete="email"
               value="<?= htmlspecialchars((string)($values['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
               style="padding:12px 14px;border-radius:12px;border:1px solid rgba(148, 163, 184, 0.28);background:rgba(15,23,42,0.6);color:var(--text);">
    </label>
    <label style="display:grid;gap:6px;font-size:0.9rem;color:var(--muted);">
        <span>Crie uma senha</span>
        <input type="password" name="password" required autocomplete="new-password"
               placeholder="Mínimo de 8 caracteres"
               style="padding:12px 14px;border-radius:12px;border:1px solid rgba(148, 163, 184, 0.28);background:rgba(15,23,42,0.6);color:var(--text);">
    </label>
    <button type="submit" style="appearance:none;border:none;border-radius:12px;padding:12px 16px;font-weight:600;font-size:0.95rem;background:var(--accent);color:#0f172a;cursor:pointer;transition:background 0.2s ease;">
        Enviar solicitação
    </button>
</form>
<a class="auth-link" href="<?= htmlspecialchars(url('auth/login'), ENT_QUOTES, 'UTF-8'); ?>">&larr; Voltar para o login</a>
