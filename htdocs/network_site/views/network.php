<?php
// Network lead form view. Expects $feedback, $old, $csrf
ob_start();
?>
<div class="panel highlight">
    <span class="eyebrow"><span class="badge-dot"></span>Network</span>
    <h1>Envie seu interesse para anunciar, investir ou participar.</h1>
    <p class="lede">Formulário completo, validação de dados e guarda em base isolada desta vitrine. Usaremos apenas para retorno.</p>
</div>

<?php if ($feedback !== null): ?>
    <div class="feedback feedback-<?= htmlspecialchars($feedback['type'], ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="panel" aria-label="Formulário de lead" id="lead">
    <form method="POST" action="/network/lead" class="form-grid" autocomplete="on">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

        <label>Nome completo
            <input type="text" name="name" required value="<?= htmlspecialchars($old['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>E-mail
            <input type="email" name="email" required value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>Telefone / WhatsApp
            <input type="text" name="phone" required value="<?= htmlspecialchars($old['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="(DDD) 99999-0000">
        </label>

        <label>Empresa (opcional)
            <input type="text" name="company" value="<?= htmlspecialchars($old['company'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>CNPJ (se PJ)
            <input type="text" name="cnpj" value="<?= htmlspecialchars($old['cnpj'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Apenas números">
        </label>
        <label>CPF (se PF)
            <input type="text" name="cpf" value="<?= htmlspecialchars($old['cpf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Apenas números">
        </label>
        <label>Data de nascimento (se PF)
            <input type="date" name="birthdate" value="<?= htmlspecialchars($old['birthdate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>

        <label>Endereço ou cidade
            <input type="text" name="address" value="<?= htmlspecialchars($old['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>Região/UF
            <input type="text" name="region" required value="<?= htmlspecialchars($old['region'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex: SP, RJ, Sul, Nordeste">
        </label>

        <label>Área de atuação / segmento
            <input type="text" name="area" required value="<?= htmlspecialchars($old['area'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Tecnologia, Saúde, Educação, ...">
        </label>
        <label>Objetivo
            <input type="text" name="objective" required value="<?= htmlspecialchars($old['objective'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Anunciar, captar, investir, partnerships">
        </label>
        <label>Interesse específico (opcional)
            <input type="text" name="interest" value="<?= htmlspecialchars($old['interest'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Marketplace, mídia, parceria, ...">
        </label>

        <label>Mensagem
            <textarea name="message" rows="4" placeholder="Fale sobre sua proposta ou necessidade."><?= htmlspecialchars($old['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <label style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="consumer_mode" value="1" <?= !empty($old['consumer_mode']) ? 'checked' : ''; ?>>
            Quero abordagem como consumidor (PF) também
        </label>
        <label>Link de CV/Portfólio (opcional)
            <input type="url" name="cv_link" value="<?= htmlspecialchars($old['cv_link'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://">
        </label>
        <label>Habilidades / stacks (opcional)
            <textarea name="skills" rows="3" placeholder="Stacks, habilidades, certificações."><?= htmlspecialchars($old['skills'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <label style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="ecommerce_interest" value="1" <?= !empty($old['ecommerce_interest']) ? 'checked' : ''; ?>>
            Tenho interesse em e-commerce / loja virtual
        </label>

        <label>Preferência política (opcional)
            <select name="political_pref">
                <?php $options = ['neutral' => 'Neutro/indiferente', 'progressive' => 'Progressista', 'conservative' => 'Conservador', 'no_info' => 'Prefiro não informar'];
                foreach ($options as $val => $label): ?>
                    <option value="<?= $val; ?>" <?= ($old['political_pref'] ?? 'neutral') === $val ? 'selected' : ''; ?>><?= $label; ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label style="display:flex; align-items:flex-start; gap:10px;">
            <input type="checkbox" name="consent" value="1" required <?= !empty($old['consent']) ? 'checked' : ''; ?> style="margin-top:6px;">
            <span>Concordo em compartilhar meus dados para contato, segmentação e retorno sobre esta solicitação.</span>
        </label>

        <button class="cta" type="submit">Enviar interesse</button>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
return 1;
