<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Services\TemplateRenderer;
use App\Services\TemplatePlaceholderCatalog;

require __DIR__ . '/../../bootstrap/app.php';

$pdo = Connection::instance();
$renderer = new TemplateRenderer();

function arg(string $name, $default = null): mixed
{
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return $default;
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function formatDate(?int $timestamp): ?string
{
    if ($timestamp === null) {
        return null;
    }
    return date('Y-m-d', $timestamp);
}

$clientId = arg('client-id');
$rfbId = arg('rfb-id');
$listId = arg('list-id');
$partnerId = arg('partner-id');

$client = $clientId
    ? fetchOne($pdo, 'SELECT * FROM clients WHERE id = :id', [':id' => (int)$clientId])
    : fetchOne($pdo, 'SELECT * FROM clients ORDER BY updated_at DESC');

$rfb = $rfbId
    ? fetchOne($pdo, 'SELECT * FROM rfb_prospects WHERE id = :id', [':id' => (int)$rfbId])
    : fetchOne($pdo, 'SELECT * FROM rfb_prospects ORDER BY updated_at DESC');

$lista = $listId
    ? fetchOne($pdo, 'SELECT * FROM audience_lists WHERE id = :id', [':id' => (int)$listId])
    : fetchOne($pdo, 'SELECT * FROM audience_lists ORDER BY updated_at DESC');

$listaContact = null;
if ($lista !== null && isset($lista['id'])) {
    $listaContact = fetchOne(
        $pdo,
        'SELECT mc.*, cli.document AS client_document, cli.name AS client_name
         FROM audience_list_contacts alc
         JOIN marketing_contacts mc ON mc.id = alc.contact_id
         LEFT JOIN clients cli ON cli.id = mc.crm_client_id
         WHERE alc.list_id = :id
         ORDER BY alc.id DESC',
        [':id' => (int)$lista['id']]
    );
}

$partner = $partnerId
    ? fetchOne($pdo, 'SELECT * FROM partners WHERE id = :id', [':id' => (int)$partnerId])
    : fetchOne($pdo, 'SELECT * FROM partners ORDER BY updated_at DESC');

$context = [
    'cliente' => [
        'nome' => $client['name'] ?? null,
        'cpf' => $client['document'] ?? null,
        'email' => $client['email'] ?? null,
        'telefone' => $client['phone'] ?? $client['whatsapp'] ?? null,
        'status' => $client['status'] ?? null,
        'certificado_expira_em' => isset($client['last_certificate_expires_at']) ? formatDate((int)$client['last_certificate_expires_at']) : null,
    ],
    'rfb' => [
        'cnpj' => $rfb['cnpj'] ?? null,
        'razao_social' => $rfb['company_name'] ?? null,
        'nome_fantasia' => $rfb['company_name'] ?? null,
        'situacao' => $rfb['exclusion_status'] ?? null,
        'data_abertura' => isset($rfb['activity_started_at']) ? formatDate((int)$rfb['activity_started_at']) : null,
        'atividade_principal' => $rfb['cnae_code'] ?? null,
        'natureza_juridica' => null,
        'capital_social' => null,
    ],
    'lista' => [
        'nome' => $listaContact['first_name'] ?? $listaContact['client_name'] ?? $lista['name'] ?? null,
        'cnpj' => $listaContact['client_document'] ?? null,
        'email' => $listaContact['email'] ?? null,
    ],
    'partner' => [
        'nome' => $partner['name'] ?? null,
        'cnpj' => $partner['document'] ?? null,
        'segmento' => $partner['type'] ?? null,
    ],
];

$template = <<<TXT
Cliente: {{cliente.nome}} ({{cliente.cpf}}) status {{cliente.status}} expira {{cliente.certificado_expira_em}}
Contato: {{cliente.email}} / {{cliente.telefone}}
RFB: {{rfb.cnpj}} - {{rfb.razao_social}} - atividade {{rfb.atividade_principal}} - abertura {{rfb.data_abertura}}
Lista: {{lista.nome}} CNPJ {{lista.cnpj}} Email {{lista.email}}
Partner: {{partner.nome}} doc {{partner.cnpj}} segmento {{partner.segmento}}
TXT;

$rendered = $renderer->renderString($template, $context);

$outputPath = arg('output', __DIR__ . '/../../storage/test_placeholders.txt');
file_put_contents($outputPath, $rendered);

echo "Arquivo gerado: {$outputPath}\n\n";
echo "Conteudo:\n";
echo $rendered . "\n";

$placeholders = TemplatePlaceholderCatalog::extractPlaceholders($template);
echo "\nPlaceholders presentes: " . implode(', ', $placeholders) . "\n";

echo "\nIDs utilizados -> cliente: " . ($client['id'] ?? 'n/d')
    . " | rfb: " . ($rfb['id'] ?? 'n/d')
    . " | lista: " . ($lista['id'] ?? 'n/d')
    . " | partner: " . ($partner['id'] ?? 'n/d') . "\n";
