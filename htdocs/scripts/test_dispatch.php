<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';
require __DIR__ . '/../bootstrap/app.php';

use App\Services\Whatsapp\WhatsappService;

$s = new WhatsappService();
$inst = $s->altGatewayInstance('lab01');
$method = 'dispatchAltGatewayMessageSingle';

if (!is_callable([$s, $method])) {
    throw new RuntimeException(sprintf(
        "Method %s::%s is not publicly accessible. Use the appropriate public API instead.",
        WhatsappService::class,
        $method
    ));
}

$result = $s->$method($inst, '11930173837', 'teste via reflection', null);
var_export($result);
