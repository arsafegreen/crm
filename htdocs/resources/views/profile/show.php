<section class="panel" style="margin-bottom:28px;">
    <header style="display:flex;flex-direction:column;gap:6px;margin-bottom:18px;">
        <h1 style="margin:0;font-size:1.6rem;">Perfil do usuário</h1>
        <p style="margin:0;color:var(--muted);max-width:640px;">
            Atualize seus dados básicos e gerencie a segurança da sua conta.
        </p>
    </header>

    <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;">
        <div style="display:flex;flex-direction:column;gap:6px;min-width:220px;">
            <span style="font-size:0.85rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;">
                Nome completo
            </span>
            <strong style="font-size:1.1rem;"><?= htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;min-width:220px;">
            <span style="font-size:0.85rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;">
                E-mail de acesso
            </span>
            <strong style="font-size:1.1rem;"><?= htmlspecialchars($user->email, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;min-width:160px;">
            <span style="font-size:0.85rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;">
                Perfil
            </span>
            <span style="padding:6px 12px;border-radius:999px;background:rgba(255,255,255,0.05);border:1px solid rgba(148,163,184,0.28);font-size:0.85rem;font-weight:600;color:var(--muted);display:inline-flex;align-items:center;gap:6px;">
                <?= $user->isAdmin() ? 'Administrador' : 'Colaborador'; ?>
            </span>
        </div>
    </div>
</section>

<section id="details" class="panel" style="margin-bottom:28px;">
    <h2 style="margin:0 0 12px;font-size:1.3rem;">Editar dados</h2>
    <p style="margin:0 0 18px;color:var(--muted);max-width:640px;font-size:0.95rem;">
        Atualize seu nome e e-mail. Essas informações aparecem nos relatórios e notificações internas.
    </p>

    <?php if (!empty($detailsFeedback)): ?>
        <div style="margin-bottom:18px;padding:14px 16px;border-radius:12px;border:1px solid <?= ($detailsFeedback['type'] ?? '') === 'success' ? 'rgba(34,197,94,0.45)' : 'rgba(248,113,113,0.45)'; ?>;background:<?= ($detailsFeedback['type'] ?? '') === 'success' ? 'rgba(34,197,94,0.12)' : 'rgba(248,113,113,0.12)'; ?>;color:<?= ($detailsFeedback['type'] ?? '') === 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
            <?= htmlspecialchars((string)($detailsFeedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= url('profile/details'); ?>" style="display:grid;gap:18px;max-width:520px;">
        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.95rem;">
            <span style="color:var(--muted);font-size:0.85rem;">Nome completo</span>
            <input type="text" name="name" value="<?= htmlspecialchars((string)($detailsValues['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required style="padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);">
        </label>
        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.95rem;">
            <span style="color:var(--muted);font-size:0.85rem;">E-mail</span>
            <input type="email" name="email" value="<?= htmlspecialchars((string)($detailsValues['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required style="padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);">
        </label>
        <div>
            <button type="submit" class="primary" style="padding:12px 20px;border-radius:999px;">Salvar alterações</button>
        </div>
    </form>
</section>

<section id="security" class="panel">
    <h2 style="margin:0 0 12px;font-size:1.3rem;">Segurança</h2>
    <p style="margin:0 0 18px;color:var(--muted);max-width:640px;font-size:0.95rem;">
        Altere sua senha periodicamente para manter a conta protegida. Utilize combinações com letras, números e símbolos.
    </p>

    <?php if (!empty($passwordFeedback)): ?>
        <div style="margin-bottom:18px;padding:14px 16px;border-radius:12px;border:1px solid <?= ($passwordFeedback['type'] ?? '') === 'success' ? 'rgba(34,197,94,0.45)' : 'rgba(248,113,113,0.45)'; ?>;background:<?= ($passwordFeedback['type'] ?? '') === 'success' ? 'rgba(34,197,94,0.12)' : 'rgba(248,113,113,0.12)'; ?>;color:<?= ($passwordFeedback['type'] ?? '') === 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
            <?= htmlspecialchars((string)($passwordFeedback['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= url('profile/password'); ?>" style="display:grid;gap:18px;max-width:520px;">
        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.95rem;">
            <span style="color:var(--muted);font-size:0.85rem;">Senha atual</span>
            <input type="password" name="current_password" autocomplete="current-password" required style="padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);">
        </label>
        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.95rem;">
            <span style="color:var(--muted);font-size:0.85rem;">Nova senha</span>
            <input type="password" name="new_password" autocomplete="new-password" required style="padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);">
        </label>
        <label style="display:flex;flex-direction:column;gap:8px;font-size:0.95rem;">
            <span style="color:var(--muted);font-size:0.85rem;">Confirme a nova senha</span>
            <input type="password" name="new_password_confirmation" autocomplete="new-password" required style="padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(15,23,42,0.65);color:var(--text);">
        </label>
        <div>
            <button type="submit" class="primary" style="padding:12px 20px;border-radius:999px;">Atualizar senha</button>
        </div>
    </form>
</section>
