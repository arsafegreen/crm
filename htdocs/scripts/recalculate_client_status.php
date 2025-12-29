<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';
require __DIR__ . '/../bootstrap/app.php';

use App\Repositories\ClientRepository;
use App\Services\Import\ClientImportService;

$service = new ClientImportService();
$repository = new ClientRepository();
$clientIds = $repository->allIds();

$service->refreshClients($clientIds);

echo "Client status synchronization completed." . PHP_EOL;
