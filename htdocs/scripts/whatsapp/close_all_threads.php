<?php

declare(strict_types=1);

use App\Database\Connection;

require __DIR__ . '/../../bootstrap/app.php';

$pdo = Connection::instance('whatsapp');

$now = time();

$total = (int)$pdo->query('SELECT COUNT(*) FROM whatsapp_threads')->fetchColumn();

$sql = 'UPDATE whatsapp_threads
        SET status = :status,
            queue = :queue,
            unread_count = 0,
            assigned_user_id = NULL,
            scheduled_for = NULL,
            updated_at = :now,
            closed_at = COALESCE(closed_at, :now)
        WHERE status != :status
           OR queue != :queue
           OR unread_count != 0
           OR assigned_user_id IS NOT NULL';

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':status' => 'closed',
    ':queue' => 'concluidos',
    ':now' => $now,
]);

$affected = $stmt->rowCount();

printf("Threads total: %d\n", $total);
printf("Atualizados para concluídos/lidos: %d\n", $affected);
