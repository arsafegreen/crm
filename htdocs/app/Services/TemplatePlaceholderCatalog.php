<?php

declare(strict_types=1);

namespace App\Services;

final class TemplatePlaceholderCatalog
{
    /**
     * Legacy tokens mapped to the new namespace-based placeholders.
     */
    private const LEGACY_MAP = [
        '{{nome}}' => '{{cliente.nome}}',
        '{{name}}' => '{{cliente.nome}}',
        '{{titular_nome}}' => '{{cliente.nome}}',
        '{{empresa}}' => '{{rfb.razao_social}}',
        '{{client_name}}' => '{{cliente.nome}}',
        '{{client_status}}' => '{{cliente.status}}',
        '{{certificate_expires_at_formatted}}' => '{{cliente.certificado_expira_em}}',
        '{{ano}}' => '{{lista.ano}}',
    ];

    public static function catalog(): array
    {
        return [
            'crm' => [
                'cliente.nome',
                'cliente.cpf',
                'cliente.email',
                'cliente.data_nascimento',
                'cliente.telefone',
                'cliente.endereco.rua',
                'cliente.endereco.numero',
                'cliente.endereco.bairro',
                'cliente.endereco.cidade',
                'cliente.endereco.uf',
                'cliente.endereco.cep',
                'cliente.status',
                'cliente.certificado_expira_em',
            ],
            'rfb' => [
                'rfb.cnpj',
                'rfb.razao_social',
                'rfb.nome_fantasia',
                'rfb.situacao',
                'rfb.data_abertura',
                'rfb.atividade_principal',
                'rfb.natureza_juridica',
                'rfb.capital_social',
            ],
            'lista' => [
                'lista.nome',
                'lista.cnpj',
                'lista.email',
            ],
            'partner' => [
                'partner.nome',
                'partner.cnpj',
                'partner.segmento',
            ],
        ];
    }

    public static function isAllowed(string $placeholder): bool
    {
        $all = array_merge(...array_values(self::catalog()));
        return in_array($placeholder, $all, true);
    }

    public static function normalizeContent(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        return str_replace(array_keys(self::LEGACY_MAP), array_values(self::LEGACY_MAP), $content);
    }

    /**
     * Extract placeholders in {{path}} format.
     * @return string[]
     */
    public static function extractPlaceholders(string $content): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all('/{{\s*([a-zA-Z0-9_\.]+)\s*}}/', $content, $matches);
        $placeholders = $matches[1] ?? [];
        return array_values(array_unique($placeholders));
    }
}
