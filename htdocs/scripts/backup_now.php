<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Support/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado via CLI.\n");
    exit(1);
}

$options = getopt('', [
    'full',
    'incremental:',
    'with-media',
    'note::',
    'restore:',
    'dest::',
    'force',
    'list',
    'prune',
    'keep-full::',
    'max-gb::',
    'help',
]);

if (isset($options['help'])) {
    echo "Backup manager CLI\n";
    echo "--full                Cria snapshot completo (usa --with-media para incluir mídias)\n";
    echo "--incremental=<id>    Cria incremental usando o snapshot base informado\n";
    echo "--with-media          Inclui mídias no snapshot\n";
    echo "--note=\"texto\"       Anota uma observação no manifesto\n";
    echo "--list                Lista snapshots disponíveis\n";
    echo "--restore=<id>        Restaura a cadeia do snapshot em destino\n";
    echo "--dest=<path>         Pasta de destino da restauração (opcional)\n";
    echo "--force               Limpa o destino ao restaurar se não estiver vazio\n";
    echo "--prune               Aplica retenção (usa keep-full e max-gb)\n";
    echo "--keep-full=<n>       Quantos full recentes manter (padrão 2)\n";
    echo "--max-gb=<n>          Limite de espaço total em GB (opcional)\n";
    exit(0);
}

$service = new BackupService();

if (isset($options['list'])) {
    $list = $service->listSnapshots();
    foreach ($list as $item) {
        $id = $item['id'] ?? 'sem-id';
        $type = $item['type'] ?? 'n/d';
        $created = $item['created_at'] ?? '';
        $base = $item['base_id'] ?? '-';
        echo "{$id}\t{$type}\tbase: {$base}\t{$created}\n";
    }
    exit(0);
}

if (isset($options['restore'])) {
    $target = (string)$options['restore'];
    $destination = isset($options['dest']) ? (string)$options['dest'] : storage_path('backups/restores/' . $target);
    $force = isset($options['force']);

    try {
        $result = $service->restore($target, $destination, $force);
        $steps = count($result['chain'] ?? []);
        echo "Restaurado em {$destination} aplicando {$steps} passo(s).\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Falha ao restaurar: ' . $exception->getMessage() . "\n");
        exit(1);
    }

    exit(0);
}

if (isset($options['prune'])) {
    $keepFull = isset($options['keep-full']) && $options['keep-full'] !== false
        ? max(1, (int)$options['keep-full'])
        : 2;

    $maxGbRaw = $options['max-gb'] ?? '';
    $maxBytes = ($maxGbRaw === '' || $maxGbRaw === false) ? null : (int)round((float)$maxGbRaw * 1024 * 1024 * 1024);

    try {
        $result = $service->prune($keepFull, $maxBytes);
        $removed = implode(', ', $result['removed'] ?? []);
        echo $removed === ''
            ? "Nenhum snapshot removido.\n"
            : "Removidos: {$removed}\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Erro: ' . $exception->getMessage() . "\n");
        exit(1);
    }

    exit(0);
}

$note = isset($options['note']) ? (string)$options['note'] : '';
$withMedia = isset($options['with-media']) || isset($options['full']);

if (isset($options['incremental'])) {
    $baseId = (string)$options['incremental'];
    if ($baseId === '') {
        fwrite(STDERR, "Informe --incremental=<id_base>.\n");
        exit(1);
    }

    try {
        $result = $service->createIncremental($baseId, $withMedia ? true : null, $note);
        echo 'Incremental criado: ' . ($result['zip_path'] ?? '') . "\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Erro: ' . $exception->getMessage() . "\n");
        exit(1);
    }

    exit(0);
}

// Padrão: cria snapshot completo.
try {
    $result = $service->createFull($withMedia, $note);
    echo 'Backup completo criado: ' . ($result['zip_path'] ?? '') . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erro: ' . $exception->getMessage() . "\n");
    exit(1);
}
