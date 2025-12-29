<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';

use App\Database\Connection;
use App\Repositories\ClientRepository;
use PDO;

$options = getopt('', ['commit', 'limit::', 'client::', 'verbose']);
$shouldCommit = array_key_exists('commit', $options);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : null;
$verbose = array_key_exists('verbose', $options);
$clientFilter = [];

if (isset($options['client'])) {
    $raw = trim((string)$options['client']);
    if ($raw !== '') {
        foreach (explode(',', $raw) as $part) {
            $id = (int)trim($part);
            if ($id > 0) {
                $clientFilter[] = $id;
            }
        }
    }
}

$pdo = Connection::instance();
$clientRepo = new ClientRepository();

$sql = 'SELECT id, document, name, titular_name FROM clients WHERE document IS NOT NULL AND document <> ""';
$params = [];

if ($clientFilter !== []) {
    $placeholders = implode(', ', array_fill(0, count($clientFilter), '?'));
    $sql .= ' AND id IN (' . $placeholders . ')';
    $params = $clientFilter;
}

$sql .= ' ORDER BY id ASC';

if ($limit !== null) {
    $sql .= ' LIMIT ' . $limit;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($clients === []) {
    fwrite(STDOUT, "Nenhum cliente encontrado para processar.\n");
    exit(0);
}

$payloadStmt = $pdo->prepare(
    'SELECT source_payload FROM certificates WHERE client_id = :client_id AND source_payload IS NOT NULL ORDER BY COALESCE(updated_at, created_at) DESC, id DESC LIMIT 1'
);

$totals = [
    'clients' => 0,
    'cnpj' => 0,
    'cpf' => 0,
    'updates' => 0,
];
$fieldTotals = [
    'name' => 0,
    'titular_name' => 0,
];

foreach ($clients as $client) {
    $totals['clients']++;
    $documentDigits = digits_only((string)($client['document'] ?? ''));
    if ($documentDigits === '') {
        continue;
    }

    $isCompany = strlen($documentDigits) === 14;
    $isIndividual = strlen($documentDigits) === 11;

    if (!$isCompany && !$isIndividual) {
        continue;
    }

    if ($isCompany) {
        $totals['cnpj']++;
    } else {
        $totals['cpf']++;
    }

    $payloadStmt->execute([':client_id' => (int)$client['id']]);
    $payloadJson = $payloadStmt->fetchColumn();

    if (!is_string($payloadJson) || trim($payloadJson) === '') {
        continue;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        continue;
    }

    $changes = determineUpdates($client, $payload, $isCompany, $isIndividual);
    if ($changes === []) {
        continue;
    }

    $totals['updates']++;
    foreach (array_keys($changes) as $field) {
        if (!isset($fieldTotals[$field])) {
            $fieldTotals[$field] = 0;
        }
        $fieldTotals[$field]++;
    }

    if ($verbose) {
        $parts = [];
        foreach ($changes as $field => $value) {
            $before = preview_value($client[$field] ?? null);
            $after = preview_value($value);
            $parts[] = sprintf('%s: "%s" => "%s"', $field, $before, $after);
        }
        $label = $isCompany ? 'CNPJ' : 'CPF';
        fwrite(STDOUT, sprintf('#%d [%s] %s => %s%s', (int)$client['id'], $label, format_document($documentDigits), implode('; ', $parts), PHP_EOL));
    }

    if ($shouldCommit) {
        $clientRepo->update((int)$client['id'], $changes);
    }
}

fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, sprintf("Clientes analisados: %d\n", $totals['clients']));
fwrite(STDOUT, sprintf(" - CNPJ: %d\n", $totals['cnpj']));
fwrite(STDOUT, sprintf(" - CPF: %d\n", $totals['cpf']));
fwrite(STDOUT, sprintf("Clientes com ajustes: %d\n", $totals['updates']));
fwrite(STDOUT, sprintf("Campos corrigidos: nome=%d, titular=%d\n", $fieldTotals['name'] ?? 0, $fieldTotals['titular_name'] ?? 0));

if ($shouldCommit) {
    fwrite(STDOUT, "Atualizações aplicadas com sucesso.\n");
} else {
    fwrite(STDOUT, "Execução em modo de simulação. Reexecute com --commit para gravar as alterações.\n");
}

function determineUpdates(array $client, array $payload, bool $isCompany, bool $isIndividual): array
{
    $changes = [];

    $sheetRazao = sanitize_company_name($payload['Nome'] ?? $payload['RAZAO SOCIAL'] ?? null);
    $sheetTitular = sanitize_person_name($payload['Nome do Titular'] ?? $payload['TITULAR'] ?? null);

    if ($isCompany) {
        if ($sheetRazao !== '' && $sheetRazao !== (string)($client['name'] ?? '')) {
            $changes['name'] = $sheetRazao;
        }

        if ($sheetTitular !== null && $sheetTitular !== (string)($client['titular_name'] ?? '')) {
            $changes['titular_name'] = $sheetTitular;
        }
    } elseif ($isIndividual) {
        $individualDisplay = $sheetTitular ?? sanitize_company_name($payload['Nome'] ?? null);

        if ($individualDisplay !== '') {
            if ($individualDisplay !== (string)($client['name'] ?? '')) {
                $changes['name'] = $individualDisplay;
            }

            $normalizedTitular = sanitize_person_name($individualDisplay);
            if ($normalizedTitular !== null && $normalizedTitular !== (string)($client['titular_name'] ?? '')) {
                $changes['titular_name'] = $normalizedTitular;
            }
        }
    }

    return $changes;
}

function sanitize_company_name(?string $value): string
{
    $normalized = sanitize_person_name($value);
    if ($normalized === null) {
        return '';
    }

    return strtoupper($normalized);
}

function sanitize_person_name(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $normalized = trim(preg_replace('/\s+/', ' ', (string)$value) ?? '');
    return $normalized !== '' ? $normalized : null;
}

function preview_value($value): string
{
    if ($value === null) {
        return '';
    }

    $string = trim((string)$value);
    if ($string === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        $string = mb_substr($string, 0, 60);
    } else {
        $string = substr($string, 0, 60);
    }

    return $string;
}
