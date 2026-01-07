<?php
// Login view for user accounts (PF/PJ). Expects $feedback, $csrf, $captcha.
ob_start();
?>
<div class="panel highlight">
    <span class="eyebrow"><span class="badge-dot"></span>Login</span>
    <h1>Acessar conta</h1>
    <p class="lede">Login protegido com captcha de imagem, bloqueio por tentativas e senha forte.</p>
</div>

<?php if ($feedback !== null): ?>
    <div class="feedback feedback-<?= htmlspecialchars($feedback['type'], ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="panel">
    <form method="POST" action="/network/auth/login" class="form-grid" autocomplete="on">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <label>E-mail
            <input type="email" name="email" required autofocus>
        </label>
        <label>Senha
            <input type="password" name="password" required placeholder="Mínimo 8 caracteres, letras, números e especial">
        </label>

        <div>
            <div class="pill" style="margin-bottom:8px;"><span class="badge-dot"></span> Não sou robô</div>
            <p class="lede" style="margin-bottom:8px;">Selecione a imagem que corresponde a: <strong><?= htmlspecialchars($captcha['label'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
            <div class="captcha-grid">
                <?php foreach ($captcha['options'] as $opt): ?>
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
    <div style="display:flex; gap:12px; margin-top:10px; flex-wrap:wrap;">
        <a class="cta alt" href="/network/auth/forgot">Esqueci a senha</a>
        <a class="cta alt" href="/network/auth/register">Criar conta (PF/PJ)</a>
    </div>
</div>

<style>
.captcha-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(120px,1fr)); gap:10px; }
.captcha-card { border:1px solid rgba(148,163,184,0.25); border-radius:12px; padding:10px; display:grid; gap:6px; cursor:pointer; background: rgba(255,255,255,0.02); }
.captcha-card:hover { border-color: rgba(34,211,238,0.45); }
.captcha-card input { margin:0; }
.captcha-card img { width:100%; border-radius:8px; border:1px solid rgba(148,163,184,0.18); }
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
return 1;
