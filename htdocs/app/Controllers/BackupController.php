<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Backup\BackupService;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BackupController
{
    private BackupService $service;

    public function __construct()
    {
        $this->service = new BackupService();
    }

    public function index(Request $request): Response
    {
        $snapshots = $this->service->listSnapshots();
        $chains = [];

        foreach ($snapshots as $snapshot) {
            $id = (string)($snapshot['id'] ?? '');
            if ($id === '') {
                continue;
            }

            try {
                $chains[$id] = $this->service->resolveChain($id);
            } catch (\Throwable $exception) {
                $chains[$id] = ['error' => $exception->getMessage()];
            }
        }

        $feedback = $_SESSION['backup_feedback'] ?? null;
        unset($_SESSION['backup_feedback']);

        return view('backup/index', [
            'snapshots' => $snapshots,
            'chains' => $chains,
            'feedback' => $feedback,
        ]);
    }

    public function createFull(Request $request): Response
    {
        $withMedia = (string)$request->request->get('with_media', '') === '1';
        $note = trim((string)$request->request->get('note', ''));

        try {
            $result = $this->service->createFull($withMedia, $note);
            $zipPath = $result['zip_path'];
            $_SESSION['backup_feedback'] = [
                'type' => 'success',
                'message' => "Backup completo criado: {$zipPath}",
            ];
        } catch (\Throwable $exception) {
            $_SESSION['backup_feedback'] = [
                'type' => 'error',
                'message' => 'Falha ao gerar backup completo: ' . $exception->getMessage(),
            ];
        }

        return new RedirectResponse(url('backup-manager'));
    }

    public function createIncremental(Request $request): Response
    {
        $baseId = trim((string)$request->request->get('base_id', ''));
        if ($baseId === '') {
            $_SESSION['backup_feedback'] = [
                'type' => 'error',
                'message' => 'Informe o snapshot base para o incremental.',
            ];

            return new RedirectResponse(url('backup-manager'));
        }

        $withMediaRaw = (string)$request->request->get('with_media', '');
        $withMedia = $withMediaRaw === '' ? null : $withMediaRaw === '1';
        $note = trim((string)$request->request->get('note', ''));

        try {
            $result = $this->service->createIncremental($baseId, $withMedia, $note);
            $zipPath = $result['zip_path'];
            $_SESSION['backup_feedback'] = [
                'type' => 'success',
                'message' => "Backup incremental criado: {$zipPath}",
            ];
        } catch (\Throwable $exception) {
            $_SESSION['backup_feedback'] = [
                'type' => 'error',
                'message' => 'Falha ao gerar incremental: ' . $exception->getMessage(),
            ];
        }

        return new RedirectResponse(url('backup-manager'));
    }

    public function restore(Request $request): Response
    {
        $targetId = trim((string)$request->request->get('target_id', ''));
        if ($targetId === '') {
            $_SESSION['backup_feedback'] = [
                'type' => 'error',
                'message' => 'Selecione um snapshot para restaurar.',
            ];

            return new RedirectResponse(url('backup-manager'));
        }

        $destination = trim((string)$request->request->get('destination', ''));
        if ($destination === '') {
            $destination = storage_path('backups/restores/' . $targetId);
        }

        $force = (string)$request->request->get('force', '') === '1';

        try {
            $this->service->restore($targetId, $destination, $force);
            $_SESSION['backup_feedback'] = [
                'type' => 'success',
                'message' => "Restaurado em {$destination}",
            ];
        } catch (\Throwable $exception) {
            $_SESSION['backup_feedback'] = [
                'type' => 'error',
                'message' => 'Falha ao restaurar: ' . $exception->getMessage(),
            ];
        }

        return new RedirectResponse(url('backup-manager'));
    }

    public function prune(Request $request): Response
    {
        $keepFull = max(1, (int)$request->request->get('keep_full', 2));
        $maxGb = trim((string)$request->request->get('max_gb', ''));
        $maxBytes = $maxGb === '' ? null : (int)round((float)$maxGb * 1024 * 1024 * 1024);

        try {
            $result = $this->service->prune($keepFull, $maxBytes);
            $removed = implode(', ', $result['removed'] ?? []);
            $_SESSION['backup_feedback'] = [
                'type' => 'success',
                'message' => $removed === ''
                    ? 'Nenhum snapshot removido. Retenção já atendida.'
                    : 'Removidos: ' . $removed,
            ];
        } catch (\Throwable $exception) {
            $_SESSION['backup_feedback'] = [
                'type' => 'error',
                'message' => 'Falha ao aplicar retenção: ' . $exception->getMessage(),
            ];
        }

        return new RedirectResponse(url('backup-manager'));
    }

    public function download(Request $request, array $vars): Response
    {
        $id = $vars['id'] ?? '';
        if (!is_string($id) || $id === '') {
            return new Response('Snapshot inválido.', 400);
        }

        $path = storage_path('backups/' . $id . '.zip');
        if (!is_file($path)) {
            return new Response('Arquivo não encontrado.', 404);
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition('attachment', $id . '.zip');

        return $response;
    }
}
