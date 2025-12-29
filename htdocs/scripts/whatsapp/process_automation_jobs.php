<?php

declare(strict_types=1);

use App\Services\Marketing\WhatsappAutomationService;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$options = getopt('', ['loop::', 'sleep::']);
$loop = array_key_exists('loop', $options);
$sleep = isset($options['sleep']) ? max(10, (int)$options['sleep']) : 60;

$automation = new WhatsappAutomationService();

do {
    try {
        $results = $automation->processDueJobs();
        if ($results === []) {
            echo sprintf('[%s] Nenhum job devido.', date('c')) . PHP_EOL;
        } else {
            foreach ($results as $entry) {
                $kind = $entry['kind'] ?? 'desconhecido';
                $res = $entry['result'] ?? [];
                $sent = (int)($res['sent'] ?? 0);
                $failed = (int)($res['failed'] ?? 0);
                $total = (int)($res['total_candidates'] ?? 0);
                $skipped = (int)($res['skipped_duplicate'] ?? 0) + (int)($res['skipped_no_phone'] ?? 0);

                echo sprintf(
                    '[%s] %s: %d/%d enviados, %d pulados, %d falhas.',
                    date('c'),
                    $kind,
                    $sent,
                    $total,
                    $skipped,
                    $failed
                ) . PHP_EOL;
            }
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf('[%s] Erro: %s%s', date('c'), $exception->getMessage(), PHP_EOL));
        if (!$loop) {
            exit(1);
        }
    }

    if (!$loop) {
        break;
    }

    sleep($sleep);
} while (true);
