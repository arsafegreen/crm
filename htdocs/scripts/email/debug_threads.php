<?php

declare(strict_types=1);

use App\Auth\AuthenticatedUser;
use App\Controllers\EmailController;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';

$accountId = isset($argv[1]) ? (int)$argv[1] : 1;
$folderId = isset($argv[2]) ? (int)$argv[2] : null;
if ($folderId !== null && $folderId <= 0) {
    $folderId = null;
}

$request = Request::create('/email/inbox/threads', 'GET', [
    'account_id' => $accountId,
    'folder_id' => $folderId,
    'limit' => 25,
    'include_folders' => 1,
]);

$user = new AuthenticatedUser(1, 'CLI', 'cli@example.com', 'admin', 'cli', null);
$request->attributes->set('user', $user);

$controller = new EmailController();
$response = $controller->threads($request);

file_put_contents('php://stdout', $response->getContent() . PHP_EOL);
