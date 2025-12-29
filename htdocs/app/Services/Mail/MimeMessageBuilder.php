<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

final class MimeMessageBuilder
{
    /**
     * @param array<string, mixed> $context
     */
    public static function build(array $context): string
    {
        $fromEmail = trim((string)($context['from_email'] ?? ''));
        $subject = (string)($context['subject'] ?? '');

        $toList = self::normalizeRecipientList($context['to_list'] ?? []);
        $singleToEmail = trim((string)($context['to_email'] ?? ''));
        if ($toList === [] && $singleToEmail !== '') {
            $toList[] = [
                'email' => $singleToEmail,
                'name' => $context['to_name'] ?? null,
            ];
        }

        if ($fromEmail === '' || $toList === []) {
            throw new RuntimeException('Remetente ou destinatário ausente.');
        }

        $fromName = self::encodeHeaderValue((string)($context['from_name'] ?? ''));
        $replyTo = trim((string)($context['reply_to'] ?? ''));
        $ccList = self::normalizeRecipientList($context['cc_list'] ?? []);
        $bodyHtml = $context['body_html'] ?? null;
        $bodyText = $context['body_text'] ?? null;
        $attachments = self::normalizeAttachments($context['attachments'] ?? []);

        if ($bodyHtml === null && $bodyText === null) {
            throw new RuntimeException('Conteúdo do e-mail não encontrado.');
        }

        if ($bodyText === null && is_string($bodyHtml)) {
            $bodyText = self::plainTextFallback($bodyHtml);
        }

        $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(6)), self::domainFromEmail($fromEmail));
        $date = gmdate('D, d M Y H:i:s O');
        $altBoundary = self::generateBoundary('ALT');

        $headers = [
            'Date' => $date,
            'Message-ID' => $messageId,
            'From' => $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail,
            'To' => self::formatAddressHeader($toList),
            'Subject' => self::encodeHeaderValue($subject),
            'MIME-Version' => '1.0',
        ];

        if ($replyTo !== '') {
            $headers['Reply-To'] = $replyTo;
        }

        if ($ccList !== []) {
            $headers['Cc'] = self::formatAddressHeader($ccList);
        }

        foreach ((array)($context['headers'] ?? []) as $key => $value) {
            $headerName = trim((string)$key);
            if ($headerName === '') {
                continue;
            }
            $headers[$headerName] = (string)$value;
        }

        $alternativeBody = self::renderAlternativeBody($altBoundary, $bodyText, $bodyHtml);

        if ($attachments !== []) {
            $mixedBoundary = self::generateBoundary('MIX');
            $headers['Content-Type'] = sprintf('multipart/mixed; boundary="%s"', $mixedBoundary);
            $body = self::renderMixedBody($mixedBoundary, $altBoundary, $alternativeBody, $attachments);
        } else {
            $headers['Content-Type'] = sprintf('multipart/alternative; boundary="%s"', $altBoundary);
            $body = $alternativeBody;
        }

        $raw = self::joinHeaders($headers) . "\r\n" . $body;
        return $raw;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function joinHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = sprintf('%s: %s', $name, self::normalizeCrlf($value));
        }

        return implode("\r\n", $lines);
    }

    private static function encodeHeaderValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (!preg_match('/[^\x20-\x7E]/', $trimmed)) {
            return $trimmed;
        }

        $encoded = base64_encode($trimmed);
        return sprintf('=?UTF-8?B?%s?=', $encoded);
    }

    private static function plainTextFallback(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text ?? '') ?? '';
        return trim($text);
    }

    private static function renderPart(string $contentType, string $content): string
    {
        $normalized = self::normalizeCrlf($content);
        return implode("\r\n", [
            'Content-Type: ' . $contentType,
            'Content-Transfer-Encoding: quoted-printable',
            '',
            self::toQuotedPrintable($normalized),
        ]);
    }

    private static function toQuotedPrintable(string $input): string
    {
        $lines = preg_split('/\r?\n/', $input) ?: [];
        $output = [];

        foreach ($lines as $line) {
            $encoded = quoted_printable_encode($line);
            $output[] = rtrim($encoded, "\r\n");
        }

        return implode("\r\n", $output);
    }

    private static function normalizeCrlf(string $value): string
    {
        $value = str_replace("\r", '', $value);
        return str_replace("\n", "\r\n", $value);
    }

    private static function domainFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        return count($parts) === 2 ? $parts[1] : 'localhost';
    }

    private static function generateBoundary(string $prefix): string
    {
        return sprintf('=_%s_%s', strtoupper($prefix), bin2hex(random_bytes(8)));
    }

    /**
     * @param mixed $raw
     * @return array<int, array{email:string,name:?string}>
     */
    private static function normalizeRecipientList($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $entry) {
            $email = null;
            $name = null;

            if (is_array($entry)) {
                $email = $entry['email'] ?? ($entry[0] ?? null);
                $name = $entry['name'] ?? ($entry[1] ?? null);
            } elseif (is_string($entry)) {
                [$email, $name] = self::parseAddressString($entry);
            }

            if (!is_string($email)) {
                continue;
            }

            $email = strtolower(trim($email));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $normalized[$email] = [
                'email' => $email,
                'name' => $name !== null && $name !== '' ? (string)$name : null,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param array<int, array{email:string,name:?string}> $recipients
     */
    private static function formatAddressHeader(array $recipients): string
    {
        $parts = [];
        foreach ($recipients as $recipient) {
            $email = $recipient['email'];
            $name = isset($recipient['name']) ? self::encodeHeaderValue((string)$recipient['name']) : '';
            $parts[] = $name !== '' ? sprintf('%s <%s>', $name, $email) : $email;
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private static function parseAddressString(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [null, null];
        }

        $name = null;
        $email = $trimmed;

        if (str_contains($trimmed, '<')) {
            $matches = [];
            if (preg_match('/(.+)<([^>]+)>/', $trimmed, $matches) === 1) {
                $name = trim($matches[1], " \"\t");
                $email = trim($matches[2]);
            }
        }

        return [$email, $name];
    }

    private static function renderAlternativeBody(string $boundary, ?string $bodyText, ?string $bodyHtml): string
    {
        $parts = [];
        if ($bodyText !== null) {
            $parts[] = self::renderPart('text/plain; charset=UTF-8', $bodyText);
        }
        if ($bodyHtml !== null) {
            $parts[] = self::renderPart('text/html; charset=UTF-8', (string)$bodyHtml);
        }

        if ($parts === []) {
            throw new RuntimeException('Nenhum corpo disponível para o e-mail.');
        }

        $lines = [];
        foreach ($parts as $part) {
            $lines[] = '--' . $boundary;
            $lines[] = $part;
        }
        $lines[] = '--' . $boundary . '--';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    /**
     * @param array<int, array{filename:string,mime_type:string,path:string}> $attachments
     */
    private static function renderMixedBody(string $mixedBoundary, string $altBoundary, string $alternativeBody, array $attachments): string
    {
        $lines = [
            '--' . $mixedBoundary,
            'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"',
            '',
            $alternativeBody,
        ];

        foreach ($attachments as $attachment) {
            $lines[] = '--' . $mixedBoundary;
            $lines[] = self::renderAttachmentPart($attachment);
        }

        $lines[] = '--' . $mixedBoundary . '--';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    /**
     * @param mixed $raw
     * @return array<int, array{filename:string,mime_type:string,path:string}>
     */
    private static function normalizeAttachments($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $path = $entry['path'] ?? $entry['storage_path'] ?? null;
            if (!is_string($path)) {
                continue;
            }
            $path = trim($path);
            if ($path === '' || !is_file($path)) {
                continue;
            }

            $filename = (string)($entry['filename'] ?? basename($path));
            if ($filename === '') {
                $filename = basename($path);
            }

            $mimeType = (string)($entry['mime_type'] ?? 'application/octet-stream');
            if ($mimeType === '') {
                $mimeType = 'application/octet-stream';
            }

            $normalized[] = [
                'filename' => $filename,
                'mime_type' => $mimeType,
                'path' => $path,
            ];
        }

        return $normalized;
    }

    /**
     * @param array{filename:string,mime_type:string,path:string} $attachment
     */
    private static function renderAttachmentPart(array $attachment): string
    {
        $contents = @file_get_contents($attachment['path']);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Falha ao ler o anexo "%s".', $attachment['path']));
        }

        $encoded = chunk_split(base64_encode($contents), 76, "\r\n");
        $encoded = rtrim($encoded, "\r\n");
        $filename = self::escapeQuotedString($attachment['filename']);

        return implode("\r\n", [
            'Content-Type: ' . $attachment['mime_type'] . '; name="' . $filename . '"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="' . $filename . '"',
            '',
            $encoded,
        ]);
    }

    private static function escapeQuotedString(string $value): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\"', '', ''], $value);
    }
}
