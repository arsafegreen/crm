<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';

require __DIR__ . '/../bootstrap/app.php';

use App\Repositories\SettingRepository;

$settings = new SettingRepository();

$startMinutes = 7 * 60;   // 07:00
$endMinutes = 18 * 60;    // 18:00

$settings->set('security.access_start_minutes', $startMinutes);
$settings->set('security.access_end_minutes', $endMinutes);

printf("Security access window updated to %02d:%02d - %02d:%02d\n", $startMinutes / 60, $startMinutes % 60, $endMinutes / 60, $endMinutes % 60);
