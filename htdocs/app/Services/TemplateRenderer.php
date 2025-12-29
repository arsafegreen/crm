<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AlertService;

final class TemplateRenderer
{
    /**
     * Map primary placeholders to alternate paths, so one template works across bases
     * (CRM cliente, listas RFB, parceiros). First value wins.
     * @var array<string, string[]>
     */
    private const FALLBACK_PATHS = [
        'cliente.nome' => ['lista.nome', 'rfb.razao_social', 'partner.nome'],
        'cliente.cpf' => ['lista.cnpj', 'rfb.cnpj', 'partner.cnpj'],
        'cliente.email' => ['lista.email', 'partner.email'],
    ];

    /**
     * Render a string replacing {{placeholder}} with values from context arrays.
     * Leaves unresolved placeholders intact so we can detect missing data upstream if needed.
     */
    public function renderString(string $template, array $context): string
    {
        return preg_replace_callback('/{{\s*([a-zA-Z0-9_\.]+)\s*}}/', function ($matches) use ($context) {
            $path = $matches[1] ?? '';
            $value = $this->getValue($context, $path);
            if ($value === null) {
                foreach (self::FALLBACK_PATHS[$path] ?? [] as $fallback) {
                    $value = $this->getValue($context, $fallback);
                    if ($value !== null) {
                        break;
                    }
                }
            }
            if ($value === null) {
                AlertService::push('template.missing_placeholder', 'Placeholder sem valor', [
                    'placeholder' => $path,
                    'fallbacks' => self::FALLBACK_PATHS[$path] ?? [],
                ]);
                return $matches[0];
            }
            if (is_scalar($value)) {
                return (string)$value;
            }
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $template);
    }

    private function getValue(array $context, string $path)
    {
        $segments = explode('.', $path);
        $current = $context;
        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }
        return $current;
    }
}
