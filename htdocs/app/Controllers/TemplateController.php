<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\TemplateRepository;
use App\Services\TemplatePlaceholderCatalog;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TemplateController
{
    public function index(Request $request): Response
    {
        $repo = new TemplateRepository();
        $templates = $repo->all('email');

        if ($templates === []) {
            $repo->seedDefaults();
            $templates = $repo->all('email');
        }

        $feedback = $_SESSION['template_feedback'] ?? null;
        unset($_SESSION['template_feedback']);

        return view('templates/index', [
            'templates' => $templates,
            'feedback' => $feedback,
        ]);
    }

    public function create(Request $request): Response
    {
        return view('templates/create', [
            'template' => [
                'name' => '',
                'subject' => '',
                'preview_text' => '',
                'category' => '',
                'tags' => [],
                'body_html' => '',
                'body_text' => '',
                'status' => 'published',
                'label' => '',
            ],
            'mode' => 'create',
            'placeholderCatalog' => TemplatePlaceholderCatalog::catalog(),
        ]);
    }

    public function store(Request $request): Response
    {
        $payload = $this->extractPayload($request);

        if ($payload['errors'] !== []) {
            $_SESSION['template_errors'] = $payload['errors'];
            $_SESSION['template_old'] = $payload['old'];
            return new RedirectResponse(url('templates/create'));
        }

        $repo = new TemplateRepository();
        $repo->create($payload['data']);

        $this->flash('success', 'Modelo criado com sucesso.');
        return new RedirectResponse(url('templates'));
    }

    public function edit(Request $request, array $vars): Response
    {
        $idFromRoute = (int)($vars['id'] ?? 0);
        $idFromBody = (int)$request->request->get('template_id', 0);
        $idFromQuery = (int)$request->query->get('id', 0);
        $id = $idFromRoute > 0 ? $idFromRoute : ($idFromBody > 0 ? $idFromBody : $idFromQuery);
        $repo = new TemplateRepository();
        $template = $repo->find($id);

        // Fallback: tentar localizar pelo nome enviado quando o ID não veio na rota/corpo
        if ($template === null) {
            $fallbackName = trim((string)$request->request->get('name', ''));
            if ($fallbackName !== '') {
                $foundByName = $repo->findByName($fallbackName, 'email');
                if ($foundByName !== null) {
                    $template = $foundByName;
                    $id = (int)$template['id'];
                }
            }
        }

        if ($template === null) {
            return abort(404, 'Modelo não encontrado.');
        }

        // Assegura ID consistente no payload do template
        if (empty($template['id']) && !empty($template['latest_version']['id'])) {
            $template['id'] = (int)$template['latest_version']['id'];
        }

        // Garantir que nome não venha vazio para o formulário
        if (empty($template['name'])) {
            $template['name'] = sprintf('Modelo #%d', $id);
        }

        $errors = $_SESSION['template_errors'] ?? [];
        $old = $_SESSION['template_old'] ?? null;
        unset($_SESSION['template_errors'], $_SESSION['template_old']);

        if ($old !== null) {
            foreach ($old as $key => $value) {
                // Keep original values when old input is vazio para evitar apagar campos ao reexibir o formulário
                if ($value === '' || $value === null) {
                    continue;
                }
                $template[$key] = $value;
            }
        }

        $versions = $template['versions'] ?? [];
        unset($template['versions']);

        if (!empty($versions)) {
            $prefill = null;
            foreach ($versions as $version) {
                $hasContent = trim((string)($version['subject'] ?? '')) !== ''
                    || trim((string)($version['body_html'] ?? '')) !== ''
                    || trim((string)($version['body_text'] ?? '')) !== ''
                    || trim((string)($version['preview_text'] ?? '')) !== '';
                if ($hasContent) {
                    $prefill = $version;
                    break;
                }
            }

            if ($prefill === null) {
                $prefill = $versions[0];
            }

            if ($old === null && $prefill !== null) {
                $template['subject'] = $prefill['subject'] ?? $template['subject'];
                $template['body_html'] = $prefill['body_html'] ?? $template['body_html'];
                $template['body_text'] = $prefill['body_text'] ?? $template['body_text'];
                $template['preview_text'] = $prefill['preview_text'] ?? $template['preview_text'];
            }
        }

        $currentStatus = $template['latest_version']['status'] ?? $template['status'] ?? 'published';
        $template['status'] = $currentStatus;
        $template['label'] = $template['latest_version']['label'] ?? '';

        return view('templates/edit', [
            'template' => $template,
            'mode' => 'edit',
            'errors' => $errors,
            'versions' => $versions,
            'placeholderCatalog' => TemplatePlaceholderCatalog::catalog(),
        ]);
    }

    public function update(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $repo = new TemplateRepository();
        $template = $repo->find($id);

        if ($template === null) {
            return abort(404, 'Modelo não encontrado.');
        }

        $payload = $this->extractPayload($request, $template);

        if ($payload['errors'] !== []) {
            $_SESSION['template_errors'] = $payload['errors'];
            $_SESSION['template_old'] = $payload['old'];
            return new RedirectResponse(url('templates/' . $id . '/edit'));
        }

        $repo->update($id, $payload['data']);
        $this->flash('success', 'Modelo atualizado com sucesso.');

        return new RedirectResponse(url('templates'));
    }

    public function destroy(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $repo = new TemplateRepository();
        $template = $repo->find($id);

        if ($template === null) {
            return abort(404, 'Modelo não encontrado.');
        }

        $repo->delete($id);
        $this->flash('success', 'Modelo removido.');

        return new RedirectResponse(url('templates'));
    }

    private function extractPayload(Request $request, ?array $current = null): array
    {
        $name = trim((string)$request->request->get('name', $current['name'] ?? ''));
        $subject = trim((string)$request->request->get('subject', $current['subject'] ?? ''));
        $previewText = trim((string)$request->request->get('preview_text', $current['preview_text'] ?? ''));
        $category = trim((string)$request->request->get('category', $current['category'] ?? ''));
        $tagsInput = (string)$request->request->get('tags', $this->tagsToString($current['tags'] ?? []));
        $bodyHtml = trim((string)$request->request->get('body_html', $current['body_html'] ?? ''));
        $bodyText = trim((string)$request->request->get('body_text', $current['body_text'] ?? ''));
        $currentStatus = $current['latest_version']['status'] ?? $current['status'] ?? 'published';
        $statusInput = strtolower(trim((string)$request->request->get('status', $currentStatus)));
        $status = in_array($statusInput, ['draft', 'published'], true) ? $statusInput : 'draft';
        $label = trim((string)$request->request->get('label', $current['label'] ?? ''));
        $tags = $this->normalizeTags($tagsInput);

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Informe um nome.';
        }
        if ($subject === '') {
            $errors['subject'] = 'Informe um assunto padrão.';
        }
        if ($bodyHtml === '' && $bodyText === '') {
            $errors['body_html'] = 'Forneça o conteúdo HTML ou texto.';
        }

        $data = [
            'name' => $name,
            'channel' => 'email',
            'subject' => $subject,
            'preview_text' => $previewText !== '' ? $previewText : null,
            'category' => $category !== '' ? $category : null,
            'tags' => $tags,
            'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
            'body_text' => $bodyText !== '' ? $bodyText : ($bodyHtml !== '' ? trim(strip_tags($bodyHtml)) : null),
            'status' => $status,
            'label' => $label !== '' ? $label : null,
        ];

        return [
            'errors' => $errors,
            'data' => $data,
            'old' => [
                'name' => $name,
                'subject' => $subject,
                'preview_text' => $previewText,
                'category' => $category,
                'tags' => $tags,
                'tags_string' => $tagsInput,
                'body_html' => $bodyHtml,
                'body_text' => $bodyText,
                'status' => $status,
                'label' => $label,
            ],
        ];
    }

    private function normalizeTags(string $tags): array
    {
        $parts = array_map(static fn(string $tag): string => trim($tag), explode(',', $tags));
        $parts = array_filter($parts, static fn(string $tag): bool => $tag !== '');
        $unique = [];
        foreach ($parts as $tag) {
            if (!in_array($tag, $unique, true)) {
                $unique[] = $tag;
            }
        }
        return $unique;
    }

    private function tagsToString($tags): string
    {
        if (is_array($tags)) {
            return implode(', ', $tags);
        }

        if (is_string($tags)) {
            return $tags;
        }

        return '';
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['template_feedback'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}
