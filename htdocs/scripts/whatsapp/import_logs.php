<?php

declare(strict_types=1);

use App\Services\AlertService;
use App\Services\Whatsapp\WhatsappService;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$options = parseOptions($argv);

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$fileOption = $options['arquivo'] ?? $options['file'] ?? null;
if ($fileOption === null) {
    fwrite(STDERR, "Informe o caminho do arquivo NDJSON com --arquivo=CAMINHO\n\n");
    printUsage();
    exit(1);
}

$filePath = realpath($fileOption) ?: $fileOption;
if (!is_file($filePath)) {
    fwrite(STDERR, "[ERRO] Arquivo não encontrado: {$filePath}\n");
    exit(1);
}

$markRead = true;
if (array_key_exists('mark_read', $options)) {
    $markRead = $options['mark_read'] !== '0';
}
if (array_key_exists('marcar_lidas', $options)) {
    $markRead = $options['marcar_lidas'] !== '0';
}

$service = new WhatsappService();
$handle = fopen($filePath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "[ERRO] Não foi possível abrir {$filePath} para leitura.\n");
    AlertService::push('whatsapp.import_logs', 'Falha ao abrir arquivo para importação', [
        'file' => $filePath,
    ]);
    exit(1);
}

fwrite(STDOUT, sprintf("Importando registros de %s (marcar como lidas: %s)\n", $filePath, $markRead ? 'sim' : 'não'));

$stats = [
    'total' => 0,
    'sucesso' => 0,
    'erros' => 0,
    'incoming' => 0,
    'outgoing' => 0,
    'threads' => [],
];

$lineNumber = 0;

while (($line = fgets($handle)) !== false) {
    $lineNumber++;
    $trimmed = trim($line);

    if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
        continue;
    }

    $stats['total']++;
    $payload = json_decode($line, true);
    if (!is_array($payload)) {
        $stats['erros']++;
        fwrite(STDERR, sprintf("[LINHA %d] JSON inválido: %s\n", $lineNumber, json_last_error_msg()));
        continue;
    }

    try {
        $result = $service->ingestLogEntry($payload, ['mark_read' => $markRead]);
        $stats['sucesso']++;
        $direction = $result['direction'] ?? 'unknown';
        if ($direction === 'incoming') {
            $stats['incoming']++;
        } elseif ($direction === 'outgoing') {
            $stats['outgoing']++;
        }
        $threadId = $result['thread_id'] ?? null;
        if ($threadId !== null) {
            $stats['threads'][(int)$threadId] = true;
        }
    } catch (\RuntimeException $exception) {
        $stats['erros']++;
        fwrite(STDERR, sprintf("[LINHA %d] %s\n", $lineNumber, $exception->getMessage()));
    } catch (\Throwable $exception) {
        $stats['erros']++;
        fwrite(STDERR, sprintf("[LINHA %d] Erro inesperado: %s\n", $lineNumber, $exception->getMessage()));
        AlertService::push('whatsapp.import_logs', 'Erro inesperado ao importar log', [
            'file' => $filePath,
            'line' => $lineNumber,
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
        ]);
    }
}

fclose($handle);

$threadsTouched = count($stats['threads']);
unset($stats['threads']);

fwrite(STDOUT, "\nResumo da importação:\n");
fwrite(STDOUT, sprintf("- Registros lidos: %d\n", $stats['total']));
fwrite(STDOUT, sprintf("- Mensagens importadas: %d (entrada: %d, saída: %d)\n", $stats['sucesso'], $stats['incoming'], $stats['outgoing']));
fwrite(STDOUT, sprintf("- Erros: %d\n", $stats['erros']));
fwrite(STDOUT, sprintf("- Conversas afetadas: %d\n", $threadsTouched));

exit($stats['erros'] > 0 ? 2 : 0);

/**
 * @return array<string,string>
 */
function parseOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--')) {
            continue;
        }

        $parts = explode('=', substr($arg, 2), 2);
        if (count($parts) === 2) {
            $options[str_replace('-', '_', strtolower($parts[0]))] = $parts[1];
        } else {
            $options[str_replace('-', '_', strtolower($parts[0]))] = '1';
        }
    }

    return $options;
}

function printUsage(): void
{
    $example = <<<'JSON'
{"phone":"5511999999999","contact_name":"João Cliente","direction":"incoming","message":"Preciso remarcar","timestamp":"2025-12-10T09:30:00-03:00","line_label":"Sandbox Copilot"}
{"phone":"5511999999999","direction":"outgoing","message":"Sem problemas, tenho vaga às 14h.","timestamp":1733825400,"line_label":"Sandbox Copilot"}
JSON;

    $usage = <<<TXT
Uso: php scripts/whatsapp/import_logs.php --arquivo=/caminho/para/log.ndjson [--mark-read=0]

Formato esperado (NDJSON - 1 JSON por linha):
$example

Campos mínimos:
- phone: número do contato (apenas dígitos ou com +55)
- direction: incoming | outgoing
- message: texto da mensagem

Campos opcionais:
- contact_name: nome amigável do contato
- timestamp: epoch ou data/hora (ISO, "2025-12-10T09:30:00-03:00")
- line_label ou line_id: identifica a linha WhatsApp para preservar filas

Use --mark-read=0 para manter as mensagens importadas como não lidas.
TXT;

    fwrite(STDOUT, $usage . "\n");
}
