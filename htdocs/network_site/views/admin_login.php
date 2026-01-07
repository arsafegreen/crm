<?php
// Admin login view. Expects $feedback, $csrf
ob_start();
?>
<div class="panel highlight">
    <span class="eyebrow"><span class="badge-dot"></span>Admin Network</span>
    <h1>Acessar painel seguro</h1>
    <p class="lede">Área restrita para gerenciar leads e anúncios desta vitrine.</p>
</div>

<?php if ($feedback !== null): ?>
    <div class="feedback feedback-error">
        <?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="panel">
    <form method="POST" action="/network/admin/login" class="form-grid">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <label>E-mail
            <input type="email" name="email" required autofocus>
        </label>
        <label>Senha
            <input type="password" name="password" required>
        </label>

        <?php $captcha = $captcha ?? ['label' => '', 'options' => []]; ?>
        <div>
            <div class="pill" style="margin-bottom:8px;"><span class="badge-dot"></span> Não sou robô</div>
            <p class="lede" style="margin-bottom:8px;">Selecione a imagem que corresponde a: <strong><?= htmlspecialchars($captcha['label'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></p>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(120px,1fr)); gap:10px;">
                <?php foreach (($captcha['options'] ?? []) as $opt): ?>
                    <label class="captcha-card">
                        <input type="radio" name="captcha_choice" value="<?= htmlspecialchars($opt['id'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        <img src="<?= htmlspecialchars($opt['src'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($opt['alt'], ENT_QUOTES, 'UTF-8'); ?>">
                        <span><?= htmlspecialchars($opt['alt'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <button class="cta" type="submit">Entrar</button>
    </form>
</div>

<style>
.captcha-card { border:1px solid rgba(148,163,184,0.25); border-radius:12px; padding:10px; display:grid; gap:6px; cursor:pointer; background: rgba(255,255,255,0.02); }
.captcha-card:hover { border-color: rgba(34,211,238,0.45); }
.captcha-card img { width:100%; border-radius:8px; border:1px solid rgba(148,163,184,0.18); }
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
return 1;
