<?php
// Perfil e segmentação. Expects $account, $feedback, $csrf
ob_start();
?>
<div class="panel highlight">
    <span class="eyebrow"><span class="badge-dot"></span>Perfil</span>
    <h1>Informações da empresa e preferências</h1>
    <p class="lede">Usamos estes dados para segmentar grupos por política, região e atividade. Campos obrigatórios para acessar mensagens.</p>
</div>

<?php if (!empty($feedback)): ?>
    <div class="feedback feedback-<?= htmlspecialchars($feedback['type'], ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="panel">
    <form method="POST" action="/network/profile" class="form-grid">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

        <label>Segmento / atividade
            <input type="text" name="segment" required value="<?= htmlspecialchars($account['segment'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex: Agro, Tecnologia, Saúde">
        </label>
        <label>CNAE (opcional)
            <input type="text" name="cnae" value="<?= htmlspecialchars($account['cnae'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>Porte (MEI, ME, EPP, etc.)
            <input type="text" name="company_size" value="<?= htmlspecialchars($account['company_size'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>Faixa de faturamento
            <input type="text" name="revenue_range" value="<?= htmlspecialchars($account['revenue_range'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>Número de colaboradores
            <input type="number" name="employees" value="<?= htmlspecialchars($account['employees'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>

        <label>Canais de venda (separe por vírgula)
            <input type="text" name="sales_channels" value="<?= htmlspecialchars(is_array($account['sales_channels'] ?? null) ? implode(', ', $account['sales_channels']) : '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ex: B2B, B2C, Online, Offline">
        </label>
        <label>Objetivos (separe por vírgula)
            <input type="text" name="objectives" value="<?= htmlspecialchars(is_array($account['objectives'] ?? null) ? implode(', ', $account['objectives']) : '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Vender, Comprar, Investir, Parcerias">
        </label>

        <label>Região
            <input type="text" name="region" required value="<?= htmlspecialchars($account['region'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Norte, Sul, Sudeste...">
        </label>
        <label>Estado (UF)
            <input type="text" name="state" required value="<?= htmlspecialchars($account['state'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="SP, RJ...">
        </label>
        <label>Cidade
            <input type="text" name="city" required value="<?= htmlspecialchars($account['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>Endereço (opcional)
            <input type="text" name="address" value="<?= htmlspecialchars($account['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </label>

        <label>Posição política
            <select name="political_pref" required>
                <?php $opts = ['left' => 'Esquerda', 'right' => 'Direita', 'neutral' => 'Neutro/sem posição']; ?>
                <?php foreach ($opts as $val => $label): ?>
                    <option value="<?= $val; ?>" <?= (($account['political_pref'] ?? 'neutral') === $val) ? 'selected' : ''; ?>><?= $label; ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <button class="cta" type="submit">Salvar perfil</button>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
return 1;
