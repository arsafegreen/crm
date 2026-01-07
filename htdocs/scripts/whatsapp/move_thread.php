<?php

declare(strict_types=1);

use App\Database\Connection;

require __DIR__ . '/../../bootstrap/app.php';

$options = getopt('', ['thread:', 'queue::', 'status::', 'chat_type::']);
$threadId = isset($options['thread']) ? (int)$options['thread'] : 0;
if ($threadId <= 0) {
    fwrite(STDERR, "Use --thread=<id> [--queue=groups] [--status=open] [--chat_type=group]\n");
    exit(1);
}

$queue = isset($options['queue']) ? (string)$options['queue'] : 'groups';
$status = isset($options['status']) ? (string)$options['status'] : 'open';
$chatType = isset($options['chat_type']) ? (string)$options['chat_type'] : 'group';

$pdo = Connection::instance('whatsapp');

$stmt = $pdo->prepare(
    'UPDATE whatsapp_threads
     SET queue = :queue,
         status = :status,
         chat_type = :chat_type,
         assigned_user_id = NULL,
         scheduled_for = NULL,
         updated_at = :now,
         closed_at = CASE WHEN :status = "closed" THEN COALESCE(closed_at, :now) ELSE NULL END
     WHERE id = :id'
);
$now = time();
$stmt->execute([
    ':queue' => $queue,
    ':status' => $status,
    ':chat_type' => $chatType,
    ':now' => $now,
    ':id' => $threadId,
]);

printf("Thread %d moved to queue=%s, status=%s, chat_type=%s\n", $threadId, $queue, $status, $chatType);
