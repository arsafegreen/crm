<?php
require __DIR__ . '/bootstrap/app.php';

$repo = new App\Repositories\ClientRepository();

$available = $repo->searchForPartner('', 5, [
    'list_when_empty' => true,
    'only_without_partner' => true,
]);

$byName = $repo->searchForPartner('a', 5, [
    'list_when_empty' => true,
]);

echo 'available=' . count($available) . PHP_EOL;
echo 'byName=' . count($byName) . PHP_EOL;

if ($available) {
    echo 'first available has_partner=' . (int)$available[0]['has_partner'] . PHP_EOL;
}

if ($byName) {
    echo 'first byName has_partner=' . (int)$byName[0]['has_partner'] . PHP_EOL;
}
