<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

use App\Services\Whatsapp\WhatsappService;

$options = getopt('', ['broadcast::', 'limit::']);
$service = new WhatsappService();

$broadcastId = isset($options['broadcast']) ? (int)$options['broadcast'] : null;
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 1;

if ($broadcastId !== null && $broadcastId > 0) {
    $result = $service->processBroadcast($broadcastId);
    $stats = $result['stats'] ?? [];
    $sent = (int)($stats['sent'] ?? 0);
    $total = (int)($stats['total'] ?? 0);
    $failed = (int)($stats['failed'] ?? 0);

    printf(
        "Broadcast #%d: %d/%d entregues, %d falhas.%s",
        $broadcastId,
        $sent,
        $total,
        $failed,
        PHP_EOL
    );
    return;
}

$results = $service->processPendingBroadcasts($limit);
printf("Processados %d broadcast(s).%s", count($results), PHP_EOL);
