<?php

declare(strict_types=1);

// Marca todas as conversas em Concluídos como lidas (unread_count = 0).

require __DIR__ . '/../bootstrap/app.php';

use App\Database\Connection;

$pdo = Connection::instance();

$stmt = $pdo->prepare('UPDATE whatsapp_threads SET unread_count = 0 WHERE queue = :queue');
$stmt->execute([':queue' => 'concluidos']);

$affected = $stmt->rowCount();

echo "Marcadas como lidas em Concluidos: {$affected}\n";
