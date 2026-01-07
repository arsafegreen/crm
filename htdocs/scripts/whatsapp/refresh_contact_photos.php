<?php

declare(strict_types=1);

use App\Services\Whatsapp\WhatsappService;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Support/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado via CLI.\n");
    exit(1);
}

$options = getopt('', [
    'limit::',
    'max-age::',
    'help',
]);

if (isset($options['help'])) {
    echo "Refresh de fotos de contatos (gateway snapshot)\n";
    echo "--limit=<n>      Quantos contatos processar (padrao 50)\n";
    echo "--max-age=<seg>  Considera snapshot desatualizado se mais velho que N segundos (padrao 604800)\n";
    exit(0);
}

$limit = isset($options['limit']) && $options['limit'] !== false ? (int)$options['limit'] : 50;
$maxAge = isset($options['max-age']) && $options['max-age'] !== false ? (int)$options['max-age'] : 604800;

$service = new WhatsappService();

try {
    $result = $service->refreshContactProfilePhotos($limit, $maxAge);
    $scanned = $result['scanned'] ?? 0;
    $updated = $result['updated'] ?? 0;
    $skipped = $result['skipped'] ?? 0;

    echo "Escaneados: {$scanned}\n";
    echo "Atualizados: {$updated}\n";
    echo "Ignorados: {$skipped}\n";

    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $error) {
            $cid = $error['contact_id'] ?? 'n/d';
            $msg = $error['error'] ?? 'erro';
            fwrite(STDERR, "Falha no contato {$cid}: {$msg}\n");
        }
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erro: ' . $exception->getMessage() . "\n");
    exit(1);
}
