<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';
require __DIR__ . '/../bootstrap/app.php';

use App\Services\Whatsapp\WhatsappService;

$s = new WhatsappService();
var_export($s->altGatewayInstance('lab01'));
