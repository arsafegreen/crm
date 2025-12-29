<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

final class SmtpMailer
{
    private string $host;
    private int $port;
    private string $encryption;
    private string $authMode;
    private ?string $username;
    private ?string $password;
    private float $timeout;

    public function __construct(array $config)
    {
        $this->host = trim((string)($config['host'] ?? ''));
        $this->port = (int)($config['port'] ?? 587);
        $this->encryption = strtolower((string)($config['encryption'] ?? 'tls'));
        $this->authMode = strtolower((string)($config['auth_mode'] ?? 'login'));
        $this->username = array_key_exists('username', $config) && $config['username'] !== null
            ? (string)$config['username']
            : null;
        $this->password = array_key_exists('password', $config) && $config['password'] !== null
            ? (string)$config['password']
            : null;
        $this->timeout = (float)($config['timeout'] ?? 20.0);

        if ($this->host === '' || $this->port <= 0) {
            throw new RuntimeException('Configuração de SMTP inválida.');
        }

        if ($this->authMode !== 'login') {
            throw new RuntimeException(sprintf('Modo de autenticação não suportado: %s', $this->authMode));
        }
    }

    /**
     * @param array{
     *     from: string,
     *     to?: string|array<int, string|array<string, string>>,
     *     recipients?: array<int, string|array<string, string>>,
     *     data: string
     * } $message
     */
    public function send(array $message): void
    {
        $fromEmail = $this->sanitizeEmail($message['from'] ?? null);
        if ($fromEmail === null) {
            throw new RuntimeException('Remetente inválido para envio SMTP.');
        }

        $recipients = $this->normalizeRecipients($message);
        if ($recipients === []) {
            throw new RuntimeException('Nenhum destinatário válido informado.');
        }

        $socket = $this->connect();

        try {
            $this->expectCode($socket, 220);
            $capabilities = $this->sendEhlo($socket);

            if ($this->encryption === 'tls') {
                $this->sendCommand($socket, 'STARTTLS');
                $this->expectCode($socket, 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Falha ao negociar TLS.');
                }
                $capabilities = $this->sendEhlo($socket);
            }

            if ($this->authMode === 'login') {
                $this->authenticate($socket);
            }

            $mailFromCommand = $this->buildMailFromCommand($fromEmail, $capabilities);
            $this->sendCommand($socket, $mailFromCommand);
            $this->expectCode($socket, 250);

            foreach ($recipients as $recipient) {
                $rcptCommand = sprintf('RCPT TO:<%s>', $recipient);
                if ($this->supportsCapability($capabilities, 'SMTPUTF8')) {
                    $rcptCommand .= ' SMTPUTF8';
                }

                try {
                    $this->sendCommand($socket, $rcptCommand);
                    $this->expectCode($socket, [250, 251]);
                } catch (RuntimeException $exception) {
                    throw new RuntimeException(
                        sprintf('SMTP rejeitou o destinatário %s: %s', $recipient, $exception->getMessage()),
                        0,
                        $exception
                    );
                }
            }

            $this->sendCommand($socket, 'DATA');
            $this->expectCode($socket, 354);

            $payload = rtrim($message['data'], "\r\n") . "\r\n";
            $payload = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $payload);
            $this->write($socket, $payload . ".\r\n");
            $this->expectCode($socket, 250);

            $this->sendCommand($socket, 'QUIT');
            fclose($socket);
        } catch (RuntimeException $exception) {
            fclose($socket);
            throw $exception;
        }
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $transport = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $endpoint = sprintf('%s://%s:%d', $transport, $this->host, $this->port);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            $endpoint,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('Falha ao conectar ao SMTP: %s (%d)', $errstr, $errno));
        }

        stream_set_timeout($socket, (int)$this->timeout);
        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function authenticate($socket): void
    {
        if ($this->username === null || $this->password === null) {
            throw new RuntimeException('Credenciais SMTP não configuradas.');
        }

        $this->sendCommand($socket, 'AUTH LOGIN');
        $this->expectCode($socket, 334);
        $this->sendCommand($socket, base64_encode($this->username));
        $this->expectCode($socket, 334);
        $this->sendCommand($socket, base64_encode($this->password));
        $this->expectCode($socket, 235);
    }

    /**
     * @param resource $socket
     * @param int|int[] $expected
     * @return string[]
     */
    private function expectCode($socket, $expected): array
    {
        $lines = $this->readResponse($socket);
        if ($lines === []) {
            throw new RuntimeException('SMTP não respondeu.');
        }

        $line = (string)end($lines);
        $code = (int)substr($line, 0, 3);
        $expectedCodes = (array)$expected;

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException(sprintf('SMTP erro: %s', trim($line)));
        }

        return $lines;
    }

    /**
     * @param resource $socket
     */
    private function sendCommand($socket, string $command): void
    {
        $this->write($socket, $command . "\r\n");
    }

    /**
     * @param resource $socket
     * @return string[]
     */
    private function readResponse($socket): array
    {
        $lines = [];

        while (true) {
            $line = fgets($socket);
            if ($line === false) {
                if ($lines === []) {
                    throw new RuntimeException('Conexão SMTP encerrada inesperadamente.');
                }
                break;
            }

            $lines[] = rtrim($line, "\r\n");

            if (!isset($line[3]) || $line[3] !== '-') {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $payload): void
    {
        $result = fwrite($socket, $payload);
        if ($result === false) {
            throw new RuntimeException('Falha ao enviar dados para o SMTP.');
        }
    }

    private function helloCommand(): string
    {
        $hostname = gethostname() ?: 'localhost';
        return sprintf('EHLO %s', $hostname);
    }

    /**
     * @param resource $socket
     * @return array<string, string>
     */
    private function sendEhlo($socket): array
    {
        $this->sendCommand($socket, $this->helloCommand());
        $lines = $this->expectCode($socket, 250);

        return $this->parseCapabilities($lines);
    }

    private function buildMailFromCommand(string $fromEmail, array $capabilities): string
    {
        $command = sprintf('MAIL FROM:<%s>', $fromEmail);

        $parameters = [];
        if ($this->supportsCapability($capabilities, 'SMTPUTF8')) {
            $parameters[] = 'SMTPUTF8';
        }
        if ($this->supportsCapability($capabilities, '8BITMIME')) {
            $parameters[] = 'BODY=8BITMIME';
        }

        if ($parameters !== []) {
            $command .= ' ' . implode(' ', $parameters);
        }

        return $command;
    }

    /**
     * @param string[] $lines
     * @return array<string, string>
     */
    private function parseCapabilities(array $lines): array
    {
        $capabilities = [];

        foreach ($lines as $line) {
            if (preg_match('/^250[-\s](.+)$/i', $line, $matches) !== 1) {
                continue;
            }

            $capability = trim($matches[1]);
            if ($capability === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $capability);
            $token = strtoupper((string)($parts[0] ?? ''));
            if ($token === '') {
                continue;
            }

            $capabilities[$token] = $capability;
        }

        return $capabilities;
    }

    private function supportsCapability(array $capabilities, string $needle): bool
    {
        $key = strtoupper($needle);
        return array_key_exists($key, $capabilities);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<int, string>
     */
    private function normalizeRecipients(array $message): array
    {
        $candidates = [];
        if (array_key_exists('recipients', $message)) {
            $candidates = array_merge($candidates, $this->extractEmails($message['recipients']));
        }
        if (array_key_exists('to', $message)) {
            $candidates = array_merge($candidates, $this->extractEmails($message['to']));
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $email = $this->sanitizeEmail($candidate);
            if ($email === null) {
                continue;
            }
            $unique[$email] = $email;
        }

        return array_values($unique);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function extractEmails($value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $collected = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $collected[] = $entry;
                continue;
            }

            if (is_array($entry)) {
                if (isset($entry['email'])) {
                    $collected[] = (string)$entry['email'];
                } elseif (isset($entry[0])) {
                    $collected[] = (string)$entry[0];
                }
            }
        }

        return $collected;
    }

    private function sanitizeEmail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $email = strtolower(trim($value));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }
}
