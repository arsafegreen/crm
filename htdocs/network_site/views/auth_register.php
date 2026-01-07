<?php
// Registration view for PF/PJ accounts. Expects $feedback, $old, $csrf, $captcha.
ob_start();
?>
<div class="panel highlight">
    <span class="eyebrow"><span class="badge-dot"></span>Cadastro</span>
    <h1>Criar conta (PF ou PJ)</h1>
    <p class="lede">Dados únicos (CPF, CNPJ, e-mail, telefone). Senha forte e captcha de imagem obrigatórios.</p>
</div>

<?php if ($feedback !== null): ?>
    <div class="feedback feedback-<?= htmlspecialchars($feedback['type'], ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="panel">
    <form method="POST" action="/network/auth/register" class="form-grid" autocomplete="on" id="form-register">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:6px;">
                <input type="radio" name="type" value="pf" required <?= ($old['type'] ?? 'pf') === 'pf' ? 'checked' : ''; ?>> Pessoa Física
            </label>
            <label style="display:flex; align-items:center; gap:6px;">
                <input type="radio" name="type" value="pj" <?= ($old['type'] ?? '') === 'pj' ? 'checked' : ''; ?>> Pessoa Jurídica
            </label>
        </div>

        <label>Nome completo
            <input type="text" name="name" required value="<?= htmlspecialchars($old['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>CPF
            <input type="text" name="cpf" required value="<?= htmlspecialchars($old['cpf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Apenas números">
        </label>
        <label>Data de nascimento
            <input type="date" name="birthdate" required value="<?= htmlspecialchars($old['birthdate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>E-mail
            <input type="email" name="email" required value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>Telefone
            <input type="text" name="phone" required value="<?= htmlspecialchars($old['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="(DDD) 99999-0000">
        </label>
        <label>Endereço
            <input type="text" name="address" required value="<?= htmlspecialchars($old['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>

        <div id="cnpj-fields" style="<?= ($old['type'] ?? 'pf') === 'pj' ? '' : 'display:none;'; ?>">
            <label>CNPJ principal
                <input type="text" name="cnpj_primary" value="<?= htmlspecialchars($old['cnpj_primary'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Apenas números">
            </label>
            <label>CNPJs adicionais (opcional, separados por vírgula)
                <textarea name="cnpjs_extra" rows="2" placeholder="123..., 987..."><?= htmlspecialchars($old['cnpjs_extra'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </label>
        </div>

        <label>Senha
            <input type="password" name="password" required placeholder="Mín. 8 chars, letras, números e especial">
        </label>
        <label>Confirmar senha
            <input type="password" name="password_confirmation" required>
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

        <button class="cta" type="submit">Criar conta</button>
    </form>
    <div style="display:flex; gap:12px; margin-top:10px; flex-wrap:wrap;">
        <a class="cta alt" href="/network/auth/login">Já tenho conta</a>
    </div>
</div>

<style>
.captcha-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(120px,1fr)); gap:10px; }
.captcha-card { border:1px solid rgba(148,163,184,0.25); border-radius:12px; padding:10px; display:grid; gap:6px; cursor:pointer; background: rgba(255,255,255,0.02); }
.captcha-card:hover { border-color: rgba(34,211,238,0.45); }
.captcha-card input { margin:0; }
.captcha-card img { width:100%; border-radius:8px; border:1px solid rgba(148,163,184,0.18); }
</style>

<script>
const form = document.getElementById('form-register');
function toggleCnpj() {
    const type = form.querySelector('input[name="type"]:checked')?.value;
    document.getElementById('cnpj-fields').style.display = type === 'pj' ? '' : 'none';
}
form.addEventListener('change', (e) => {
    if (e.target.name === 'type') toggleCnpj();
});
toggleCnpj();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
return 1;
