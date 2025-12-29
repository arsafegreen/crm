<?php

declare(strict_types=1);

use App\Repositories\WhatsappThreadRepository;
use App\Repositories\PartnerRepository;
use App\Services\Whatsapp\WhatsappService;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$service = new WhatsappService();
$threadRepo = new WhatsappThreadRepository();
$partnerRepo = new PartnerRepository();

$line = ensureSandboxLine($service, 'QA Sandbox Queue');

$phone = '55999' . random_int(1000000, 9999999);
$simulation = $service->simulateIncomingMessage((int)$line['id'], $phone, 'Teste automático de fila ' . date('H:i:s'));
$threadId = (int)$simulation['thread_id'];

$state = [];
$state[] = describeThread($threadRepo->find($threadId));

$service->updateQueue($threadId, 'scheduled', [
    'scheduled_for' => now() + 7200,
]);
$state[] = describeThread($threadRepo->find($threadId));

$partner = $partnerRepo->findOrCreate('Parceiro QA ' . date('Ymd'));
if ($partner !== null) {
    $service->updateQueue($threadId, 'partner', [
        'partner_id' => (int)$partner['id'],
    ]);
    $state[] = describeThread($threadRepo->find($threadId));
}

$service->updateQueue($threadId, 'arrival');
$state[] = describeThread($threadRepo->find($threadId));

foreach ($state as $index => $info) {
    echo sprintf("Passo %d: fila=%s, status=%s, agendado=%s, parceiro=%s\n",
        $index + 1,
        $info['queue'],
        $info['status'],
        $info['scheduled_for'] ?? '-',
        $info['partner_name'] ?? '-'
    );
}

echo "\nThread de teste: #{$threadId} (telefone {$phone})\n";

echo "Concluído sem exceções.\n";

function ensureSandboxLine(WhatsappService $service, string $label): array
{
    foreach ($service->lines() as $line) {
        if (strcasecmp((string)($line['label'] ?? ''), $label) === 0) {
            return $line;
        }
    }

    return $service->createLine([
        'label' => $label,
        'provider' => 'sandbox',
        'is_default' => false,
    ]);
}

function describeThread(?array $thread): array
{
    if ($thread === null) {
        return [
            'queue' => 'desconhecida',
            'status' => '-',
        ];
    }

    return [
        'queue' => (string)($thread['queue'] ?? ''),
        'status' => (string)($thread['status'] ?? ''),
        'scheduled_for' => isset($thread['scheduled_for']) ? date('c', (int)$thread['scheduled_for']) : null,
        'partner_name' => $thread['partner_name'] ?? null,
    ];
}
