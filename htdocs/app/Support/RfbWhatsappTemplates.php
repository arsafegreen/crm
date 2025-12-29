<?php

declare(strict_types=1);

namespace App\Support;

final class RfbWhatsappTemplates
{
    public const TEMPLATE_KEYS = ['partnership', 'general'];

    public static function defaults(): array
    {
        return [
            'partnership' => "Olá {{responsavel_primeiro_nome}}, tudo bem? Aqui é a equipe da DWV Certificados Digitais. Vimos que o CNAE {{cnae}} permite criar uma parceria para oferecer certificados com comissionamento e suporte total. Posso te enviar os detalhes agora?",
            'general' => "Olá {{responsavel_primeiro_nome}}, sou da DWV Certificados. Se {{empresa}} ou seus clientes precisarem emitir ou renovar certificado digital, posso cuidar de tudo por aqui mesmo. Quer que eu te ajude agora?",
        ];
    }

    public static function sanitize(string $value, string $fallback): string
    {
        $normalized = preg_replace("/\r\n?/", "\n", $value);
        $normalized = trim((string)$normalized);
        if ($normalized === '') {
            return $fallback;
        }

        $normalized = strip_tags($normalized);
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized);

        return mb_substr($normalized, 0, 600);
    }

    public static function render(array $record, string $template): string
    {
        $responsible = trim((string)($record['responsible_name'] ?? ''));
        $firstName = '';
        if ($responsible !== '') {
            $parts = preg_split('/\s+/', $responsible);
            if (is_array($parts) && isset($parts[0])) {
                $firstName = (string)$parts[0];
            }
        }

        $company = trim((string)($record['company_name'] ?? ''));
        $city = trim((string)($record['city'] ?? ''));
        $state = trim((string)($record['state'] ?? ''));
        $cnpj = trim((string)($record['cnpj'] ?? ''));
        $cnae = trim((string)($record['cnae_code'] ?? ''));

        if ($cnpj !== '' && function_exists('format_document')) {
            $cnpj = format_document($cnpj);
        }

        $message = strtr($template, [
            '{{responsavel}}' => $responsible !== '' ? $responsible : 'responsável',
            '{{responsavel_primeiro_nome}}' => $firstName,
            '{{empresa}}' => $company !== '' ? $company : 'sua empresa',
            '{{cidade}}' => $city,
            '{{estado}}' => $state,
            '{{cnpj}}' => $cnpj,
            '{{cnae}}' => $cnae,
        ]);

        return self::normalizeSpacing($message);
    }

    private static function normalizeSpacing(string $message): string
    {
        // Remove espaços duplicados preservando quebras de linha simples
        $message = preg_replace('/[ \t]+/', ' ', $message);
        $message = preg_replace('/ \n/', "\n", $message);
        $message = preg_replace('/\n /', "\n", $message);
        $message = preg_replace('/\s+,/', ',', $message);
        $message = preg_replace('/,\s*/', ', ', $message);
        $message = preg_replace('/\s{2,}/', ' ', $message);
        $message = preg_replace("/(?:\n){3,}/", "\n\n", $message);

        return trim($message);
    }
}
