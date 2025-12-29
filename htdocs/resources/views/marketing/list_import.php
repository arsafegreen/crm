<?php
$list = $list ?? [];
$form = $form ?? ['source_label' => '', 'respect_opt_out' => '1'];
$errors = $errors ?? [];
$limits = $limits ?? ['max_rows' => 5000, 'max_file_size_mb' => 5];
$result = $result ?? null;
$feedback = $feedback ?? null;
$sampleUrl = url('samples/marketing_contacts_template.csv');
?>

<style>
    .marketing-grid { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); margin-top:16px; }
    .marketing-card { border:1px solid var(--border); border-radius:18px; padding:16px; background:rgba(15,23,42,0.6); }
    .stat-value { font-size:1.2rem; font-weight:600; }
    .panel-table { width:100%; border-collapse:collapse; }
    .panel-table th, .panel-table td { padding:10px 12px; border-bottom:1px solid rgba(148,163,184,0.2); text-align:left; }
</style>

<header style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <div>
        <a class="ghost" href="<?= url('marketing/lists'); ?>">&larr; Voltar para listas</a>
        <h1 style="margin:12px 0 4px;">Importar contatos</h1>
        <p style="color:var(--muted);max-width:720px;">
            Faça upload de um arquivo CSV com cabeçalho para preencher a lista
            <strong><?= htmlspecialchars($list['name'] ?? 'Lista', ENT_QUOTES, 'UTF-8'); ?></strong>.
            Os contatos serão deduplicados por e-mail e receberão consentimento conforme as colunas fornecidas.
        </p>
    </div>
    <div class="panel" style="min-width:220px;">
        <strong>Limites atuais</strong>
        <ul style="margin:12px 0 0 18px;color:var(--muted);">
            <li><?= number_format((int)($limits['max_rows'] ?? 0), 0, ',', '.'); ?> linhas por lote</li>
            <li><?= (int)($limits['max_file_size_mb'] ?? 0); ?>MB por arquivo</li>
        </ul>
        <a class="ghost" href="<?= $sampleUrl; ?>" download>Baixar CSV modelo</a>
    </div>
</header>

<?php if ($feedback !== null): ?>
    <div class="panel" style="margin-top:18px;border-left:4px solid <?= ($feedback['type'] ?? '') === 'success' ? '#22c55e' : '#f87171'; ?>;">
        <strong><?= htmlspecialchars($feedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>
<?php endif; ?>

<section class="panel" style="margin-top:18px;">
    <form method="post" action="<?= url('marketing/lists/' . (int)($list['id'] ?? 0) . '/import'); ?>" enctype="multipart/form-data" style="display:grid;gap:18px;">
        <?= csrf_field(); ?>
        <div>
            <label for="source_label">Origem / campanha (opcional)</label>
            <input type="text" id="source_label" name="source_label" value="<?= htmlspecialchars($form['source_label'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="ex.: evento_rd_2025" />
            <?php if (!empty($errors['source_label'])): ?>
                <small style="color:#f87171;display:block;"><?= htmlspecialchars($errors['source_label'], ENT_QUOTES, 'UTF-8'); ?></small>
            <?php endif; ?>
        </div>
        <div>
            <label for="import_file">Arquivo CSV<span style="color:#f87171;">*</span></label>
            <input type="file" id="import_file" name="import_file" accept=".csv,text/csv" required />
            <small style="color:var(--muted);display:block;margin-top:4px;">Inclua cabeçalho com as colunas aceitas (email, first_name, last_name, phone, tags, consent_status, consent_source, consent_at, custom.*).</small>
            <?php if (!empty($errors['import_file'])): ?>
                <small style="color:#f87171;display:block;"><?= htmlspecialchars($errors['import_file'], ENT_QUOTES, 'UTF-8'); ?></small>
            <?php endif; ?>
        </div>
        <label style="display:flex;align-items:center;gap:12px;">
            <input type="checkbox" name="respect_opt_out" value="1" <?= ($form['respect_opt_out'] ?? '1') === '1' ? 'checked' : ''; ?> />
            <span>Respeitar opt-out já existente (mantém contatos descadastrados)</span>
        </label>
        <div>
            <button type="submit" class="primary">Processar importação</button>
            <small style="display:block;color:var(--muted);margin-top:6px;">O upload pode levar alguns segundos para arquivos grandes.</small>
        </div>
    </form>
</section>

<section class="panel" style="margin-top:18px;">
    <h2 style="margin-top:0;">Estrutura do CSV</h2>
    <table class="panel-table" style="margin-top:12px;">
        <thead>
            <tr>
                <th>Coluna</th>
                <th>Obrigatório?</th>
                <th>Descrição</th>
            </tr>
        </thead>
        <tbody>
            <tr><td><code>email</code></td><td>Sim</td><td>Identificador único. Precisamos de formato válido.</td></tr>
            <tr><td><code>first_name</code> / <code>last_name</code></td><td>Não</td><td>Nome completo do contato.</td></tr>
            <tr><td><code>phone</code></td><td>Não</td><td>Telefone será normalizado para dígitos.</td></tr>
            <tr><td><code>tags</code></td><td>Não</td><td>Separadas por "," ou ";". Mescladas às tags atuais.</td></tr>
            <tr><td><code>consent_status</code></td><td>Não</td><td>Valores aceitos: <em>confirmed</em>, <em>pending</em> ou <em>opted_out</em>.</td></tr>
            <tr><td><code>consent_source</code></td><td>Não</td><td>Origem do opt-in (evento, landing page etc.).</td></tr>
            <tr><td><code>consent_at</code></td><td>Não</td><td>Data/hora do consentimento. Aceita ISO ou DD/MM/YYYY.</td></tr>
            <tr><td><code>custom.*</code></td><td>Não</td><td>Toda coluna com prefixo <code>custom.</code> vira atributo dinâmico.</td></tr>
        </tbody>
    </table>
</section>

<?php if ($result !== null): ?>
    <section class="panel" style="margin-top:18px;">
        <h2 style="margin-top:0;">Resumo da última importação</h2>
        <p style="color:var(--muted);">
            Arquivo: <strong><?= htmlspecialchars($result['filename'] ?? 'contacts.csv', ENT_QUOTES, 'UTF-8'); ?></strong>
            &middot; Concluído em <?= date('d/m/Y H:i', (int)($result['completed_at'] ?? time())); ?>
        </p>
        <div class="marketing-grid" style="margin-top:0;">
            <?php
            $stats = $result['stats'] ?? [];
            $statItems = [
                'processed' => 'Processados',
                'created_contacts' => 'Novos contatos',
                'updated_contacts' => 'Atualizados',
                'attached' => 'Vinculados à lista',
                'duplicates_in_file' => 'Duplicados no CSV',
                'invalid' => 'Linhas inválidas',
            ];
            foreach ($statItems as $key => $label): ?>
                <div class="marketing-card" style="box-shadow:none;">
                    <small style="color:var(--muted);"><?= $label; ?></small>
                    <div class="stat-value" style="font-size:1.4rem;">
                        <?= number_format((int)($stats[$key] ?? 0), 0, ',', '.'); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($result['errors'])): ?>
            <details style="margin-top:18px;">
                <summary style="cursor:pointer;">Ver erros registrados (<?= count($result['errors']); ?>)</summary>
                <ul style="margin-top:12px;padding-left:20px;color:#f87171;">
                    <?php foreach ($result['errors'] as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </section>
<?php endif; ?>
