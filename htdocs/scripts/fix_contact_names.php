<?php
require __DIR__ . '/../bootstrap/app.php';

use App\Database\Connection;
use App\Repositories\ClientRepository;

$pdo = Connection::instance();
$clientRepo = new ClientRepository();

$contacts = $pdo->query('SELECT id, client_id, name, phone FROM whatsapp_contacts')->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
$matched = 0;
$now = time();

foreach ($contacts as $contact) {
    $digits = preg_replace('/\D+/', '', $contact['phone'] ?? '');
    if ($digits === '') {
        continue;
    }
    $client = $clientRepo->findByPhoneDigits($digits);
    if (!$client) {
        continue;
    }
    $matched++;
    $fields = [];
    $params = [':id' => (int)$contact['id']];
    $newName = trim((string)($client['name'] ?? ''));
    $newClientId = (int)($client['id'] ?? 0);
    if ($newName !== '' && $newName !== (string)$contact['name']) {
        $fields[] = 'name = :name';
        $params[':name'] = $newName;
    }
    if ($newClientId > 0 && (int)$contact['client_id'] !== $newClientId) {
        $fields[] = 'client_id = :client_id';
        $params[':client_id'] = $newClientId;
    }
    if ($fields === []) {
        continue;
    }
    $fields[] = 'updated_at = :updated_at';
    $params[':updated_at'] = $now;
    $sql = 'UPDATE whatsapp_contacts SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $updated++;
}

echo json_encode([
    'contacts' => count($contacts),
    'matched' => $matched,
    'updated' => $updated,
]) . PHP_EOL;
