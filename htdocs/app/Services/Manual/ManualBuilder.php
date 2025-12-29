<?php

declare(strict_types=1);

namespace App\Services\Manual;

use Dompdf\Dompdf;
use Dompdf\Options;
use Parsedown;
use RuntimeException;

final class ManualBuilder
{
    private string $sourceDir;
    private string $outputPath;
    private Parsedown $parser;

    public function __construct(?string $sourceDir = null, ?string $outputPath = null)
    {
        $this->sourceDir = $sourceDir ?? base_path('docs/system_manual');
        $this->outputPath = $outputPath ?? storage_path('manual/manual.pdf');
        $this->parser = new Parsedown();
        $this->parser->setSafeMode(true);
    }

    public function ensurePdf(): string
    {
        $files = $this->orderedManualFiles();
        if ($files === []) {
            throw new RuntimeException('Nenhum capítulo encontrado em docs/system_manual.');
        }

        $latestSource = $this->latestSourceTimestamp($files);
        $needsBuild = !is_file($this->outputPath) || filemtime($this->outputPath) < $latestSource;

        if ($needsBuild) {
            $this->buildPdf($files);
        }

        return $this->outputPath;
    }

    /**
     * @return string[]
     */
    private function orderedManualFiles(): array
    {
        $manualIndex = $this->sourceDir . DIRECTORY_SEPARATOR . 'manual.md';
        $files = [];

        if (is_file($manualIndex)) {
            $content = (string)file_get_contents($manualIndex);
            preg_match_all('/\((\d{3}_[^)]+\.md)\)/', $content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $relative) {
                    $fullPath = $this->sourceDir . DIRECTORY_SEPARATOR . $relative;
                    if (is_file($fullPath)) {
                        $files[] = $fullPath;
                    }
                }
            }
        }

        if ($files === []) {
            $files = glob($this->sourceDir . DIRECTORY_SEPARATOR . '*.md') ?: [];
            sort($files);
        }

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * @param string[] $files
     */
    private function latestSourceTimestamp(array $files): int
    {
        $timestamps = array_map(static function (string $file): int {
            return filemtime($file) ?: 0;
        }, $files);

        return (int)max($timestamps);
    }

    /**
     * @param string[] $files
     */
    private function buildPdf(array $files): void
    {
        $sections = [];
        foreach ($files as $file) {
            $markdown = (string)file_get_contents($file);
            $title = $this->extractTitle($markdown) ?? $this->fallbackTitle($file);
            $html = $this->parser->text($markdown);
            $sections[] = sprintf(
                '<article class="manual-section"><h1>%s</h1>%s</article>',
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                $html
            );
        }

        $documentHtml = $this->wrapHtml(implode("\n", $sections));
        $this->renderPdf($documentHtml);
    }

    private function extractTitle(string $markdown): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $markdown, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function fallbackTitle(string $file): string
    {
        $filename = pathinfo($file, PATHINFO_FILENAME);
        $filename = preg_replace('/^\d+_/', '', $filename) ?? $filename;
        $filename = str_replace('_', ' ', $filename);

        return ucwords($filename ?: 'Capítulo');
    }

    private function wrapHtml(string $sectionsHtml): string
    {
        $generatedAt = date('d/m/Y H:i');

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Manual Técnico – Marketing Suite</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; color:#0f172a; font-size:12px; margin:0; padding:40px; }
        h1,h2,h3 { color:#0f172a; }
        h1 { font-size:20px; margin-bottom:12px; }
        h2 { font-size:16px; margin-top:18px; }
        h3 { font-size:14px; }
        p { line-height:1.5; margin:0 0 12px; }
        ul { margin:0 0 12px 18px; }
        li { margin-bottom:4px; }
        code { background:#e2e8f0; padding:2px 4px; border-radius:4px; }
        pre { background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; }
        header { text-align:center; margin-bottom:40px; }
        header h1 { font-size:26px; margin-bottom:8px; }
        header p { margin:4px 0; }
        .manual-section { page-break-before: always; }
        .manual-section:first-of-type { page-break-before: auto; }
    </style>
</head>
<body>
<header>
    <h1>Manual Técnico – Marketing Suite</h1>
    <p>Compilado automaticamente a partir de docs/system_manual</p>
    <p>Gerado em {$generatedAt}</p>
</header>
{$sectionsHtml}
</body>
</html>
HTML;
    }

    private function renderPdf(string $html): void
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $directory = dirname($this->outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o diretório de saída do manual.');
        }

        $bytes = $dompdf->output();
        if (file_put_contents($this->outputPath, $bytes) === false) {
            throw new RuntimeException('Falha ao salvar manual técnico em PDF.');
        }
    }
}
