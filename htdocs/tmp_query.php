<?php
$pdo=new PDO('sqlite:storage/database.sqlite');
foreach($pdo->query('select id,label,display_phone,provider,alt_gateway_instance,status from whatsapp_lines order by id') as $row){
    echo $row['id'],' | ',$row['label'],' | ',$row['display_phone'],' | ',$row['provider'],' | ',$row['alt_gateway_instance'],' | ',$row['status'],PHP_EOL;
}
