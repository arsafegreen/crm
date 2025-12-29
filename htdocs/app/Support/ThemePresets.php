<?php

declare(strict_types=1);

namespace App\Support;

final class ThemePresets
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'safegreen-blue' => [
                'label' => 'Azul SafeGreen',
                'preview' => 'linear-gradient(135deg, rgba(56,189,248,0.55) 0%, rgba(15,23,42,0.95) 60%)',
                'tokens' => [
                    'color_scheme' => 'dark',
                    'bg' => '#0f172a',
                    'panel' => 'linear-gradient(145deg, rgba(17,30,55,0.88), rgba(17,24,39,0.72))',
                    'text' => '#f8fafc',
                    'muted' => '#94a3b8',
                    'accent' => '#38bdf8',
                    'accent_hover' => '#0ea5e9',
                    'border' => 'rgba(148, 163, 184, 0.2)',
                    'success' => '#22c55e',
                    'shadow' => '0 30px 60px -30px rgba(14, 165, 233, 0.35)',
                    'body' => 'radial-gradient(circle at 10% 20%, rgba(56, 189, 248, 0.15) 0%, rgba(15, 23, 42, 1) 25%), radial-gradient(circle at 90% 10%, rgba(34, 197, 94, 0.12) 0%, rgba(15, 23, 42, 1) 20%), var(--bg)',
                ],
            ],
            'safegreen-green' => [
                'label' => 'Verde Emerald',
                'preview' => 'linear-gradient(135deg, rgba(16,185,129,0.55) 0%, rgba(4,47,46,0.95) 60%)',
                'tokens' => [
                    'color_scheme' => 'dark',
                    'bg' => '#042f2e',
                    'panel' => 'linear-gradient(145deg, rgba(4,47,46,0.92), rgba(6,78,59,0.78))',
                    'text' => '#ecfdf5',
                    'muted' => '#99f6e4',
                    'accent' => '#34d399',
                    'accent_hover' => '#10b981',
                    'border' => 'rgba(45, 212, 191, 0.25)',
                    'success' => '#34d399',
                    'shadow' => '0 30px 60px -30px rgba(16, 185, 129, 0.35)',
                    'body' => 'radial-gradient(circle at 12% 18%, rgba(16, 185, 129, 0.18) 0%, rgba(4, 47, 46, 1) 26%), radial-gradient(circle at 82% 8%, rgba(59, 130, 246, 0.12) 0%, rgba(4, 47, 46, 1) 28%), var(--bg)',
                ],
            ],
            'safegreen-pink' => [
                'label' => 'Rosa Aurora',
                'preview' => 'linear-gradient(135deg, rgba(244,114,182,0.55) 0%, rgba(59,7,100,0.92) 65%)',
                'tokens' => [
                    'color_scheme' => 'dark',
                    'bg' => '#3b0764',
                    'panel' => 'linear-gradient(145deg, rgba(59,7,100,0.92), rgba(76,5,110,0.78))',
                    'text' => '#fdf2f8',
                    'muted' => '#f9a8d4',
                    'accent' => '#f472b6',
                    'accent_hover' => '#ec4899',
                    'border' => 'rgba(244, 114, 182, 0.25)',
                    'success' => '#22c55e',
                    'shadow' => '0 30px 60px -30px rgba(236, 72, 153, 0.35)',
                    'body' => 'radial-gradient(circle at 14% 24%, rgba(244, 114, 182, 0.22) 0%, rgba(59, 7, 100, 1) 30%), radial-gradient(circle at 88% 10%, rgba(56, 189, 248, 0.14) 0%, rgba(59, 7, 100, 1) 28%), var(--bg)',
                ],
            ],
            'safegreen-black' => [
                'label' => 'Preto Carbono',
                'preview' => 'linear-gradient(135deg, rgba(148,163,184,0.22) 0%, rgba(2,6,23,0.96) 65%)',
                'tokens' => [
                    'color_scheme' => 'dark',
                    'bg' => '#020617',
                    'panel' => 'linear-gradient(145deg, rgba(10,12,18,0.94), rgba(24,29,42,0.78))',
                    'text' => '#f8fafc',
                    'muted' => '#cbd5f5',
                    'accent' => '#38bdf8',
                    'accent_hover' => '#0ea5e9',
                    'border' => 'rgba(148, 163, 184, 0.22)',
                    'success' => '#22c55e',
                    'shadow' => '0 30px 60px -35px rgba(59, 130, 246, 0.28)',
                    'body' => 'radial-gradient(circle at 18% 18%, rgba(148, 163, 184, 0.12) 0%, rgba(2, 6, 23, 1) 32%), radial-gradient(circle at 80% 6%, rgba(15, 118, 110, 0.16) 0%, rgba(2, 6, 23, 1) 28%), var(--bg)',
                ],
            ],
            'safegreen-white' => [
                'label' => 'Branco Aurora',
                'preview' => 'linear-gradient(135deg, rgba(226,232,240,1) 0%, rgba(255,255,255,1) 60%)',
                'tokens' => [
                    'color_scheme' => 'light',
                    'bg' => '#f8fafc',
                    'panel' => 'linear-gradient(145deg, rgba(248,250,252,0.98), rgba(226,232,240,0.9))',
                    'text' => '#0f172a',
                    'muted' => '#475569',
                    'accent' => '#2563eb',
                    'accent_hover' => '#1d4ed8',
                    'border' => 'rgba(148, 163, 184, 0.35)',
                    'success' => '#16a34a',
                    'shadow' => '0 25px 50px -25px rgba(15, 23, 42, 0.25)',
                    'body' => 'linear-gradient(180deg, rgba(241, 245, 249, 0.8) 0%, rgba(255, 255, 255, 0.92) 40%, rgba(255, 255, 255, 1) 100%), var(--bg)',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        $presets = self::all();

        return $presets[$key] ?? $presets['safegreen-blue'];
    }
}
