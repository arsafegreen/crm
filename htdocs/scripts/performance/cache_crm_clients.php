<?php

declare(strict_types=1);

use App\Database\Connection;
use Predis\Client as RedisClient;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execute via CLI: php scripts/performance/cache_crm_clients.php --warmup\n");
    exit(1);
}

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$options = getopt('', ['warmup', 'client:', 'ttl::']);
$warmup = array_key_exists('warmup', $options);
$singleClient = isset($options['client']) ? (int)$options['client'] : null;
$ttl = isset($options['ttl']) ? (int)$options['ttl'] : 900;

$redis = new RedisClient([
    'scheme' => 'tcp',
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => (int)env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD'),
]);
$pdo = Connection::instance();

$query = 'SELECT id, document, name, email, status, next_follow_up_at FROM clients';
$params = [];

if ($singleClient !== null) {
    $query .= ' WHERE id = :id LIMIT 1';
    $params[':id'] = $singleClient;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$total = 0;
foreach ($rows as $row) {
    $decoded = [
        'id' => (int)$row['id'],
        'document' => (string)($row['document'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'status' => $row['status'] ?? 'prospect',
        'next_follow_up_at' => $row['next_follow_up_at'] !== null ? (int)$row['next_follow_up_at'] : null,
    ];

    $cacheKey = sprintf('crm:client:%d:snapshot', $decoded['id']);
    $redis->setex($cacheKey, $ttl, json_encode($decoded, JSON_UNESCAPED_UNICODE));
    $total++;
}

fwrite(STDOUT, sprintf("Cached %d client snapshot(s) with TTL %d seconds.\n", $total, $ttl));

if ($warmup) {
    fwrite(STDOUT, "Warmup completed successfully.\n");
}
