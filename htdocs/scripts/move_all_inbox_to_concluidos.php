<?php

require __DIR__ . '/../bootstrap/app.php';

use App\Database\Connection;

$pdo = Connection::instance();

$sql = 'UPDATE whatsapp_threads
        SET queue = "concluidos",
            status = "closed",
            assigned_user_id = NULL,
            responsible_user_id = NULL,
            scheduled_for = NULL,
            closed_at = :ts,
            updated_at = :ts
        WHERE (queue = "arrival" OR queue IS NULL)
          AND (status IS NULL OR status != "closed")';

$ts = now();
$stmt = $pdo->prepare($sql);
$stmt->execute([':ts' => $ts]);

$affected = $stmt->rowCount();

echo "Moved to concluidos: {$affected}\n";
