<?php
$pdo = new PDO('sqlite:' . __DIR__ . '/storage/database.sqlite');
$stmt = $pdo->query('SELECT id, type, status FROM chat_threads ORDER BY id');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['id'] . '|' . $row['type'] . '|' . $row['status'] . PHP_EOL;
}
