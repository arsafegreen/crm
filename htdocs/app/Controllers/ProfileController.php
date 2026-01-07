<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Auth\PasswordPolicy;
use App\Auth\SessionAuthService;
use App\Repositories\UserRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProfileController
{
    private SessionAuthService $auth;
    private UserRepository $users;

    public function __construct()
    {
        $this->auth = new SessionAuthService();
        $this->users = new UserRepository();
    }

    public function show(Request $request): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return new RedirectResponse(url('auth/login'));
        }

        $detailsFeedback = $_SESSION['profile_details_feedback'] ?? null;
        $detailsValues = $_SESSION['profile_details_values'] ?? null;
        unset($_SESSION['profile_details_feedback'], $_SESSION['profile_details_values']);

        if (!is_array($detailsValues)) {
            $detailsValues = [
                'name' => $user->name,
                'email' => $user->email,
            ];
        }

        $passwordFeedback = $_SESSION['profile_password_feedback'] ?? null;
        unset($_SESSION['profile_password_feedback']);

        return view('profile/show', [
            'user' => $user,
            'detailsFeedback' => $detailsFeedback,
            'detailsValues' => $detailsValues,
            'passwordFeedback' => $passwordFeedback,
        ]);
    }

    public function updateDetails(Request $request): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return new RedirectResponse(url('auth/login'));
        }

        $name = trim((string)$request->request->get('name', ''));
        $email = trim(strtolower((string)$request->request->get('email', '')));

        $values = [
            'name' => $name,
            'email' => $email,
        ];

        if ($name === '' || $email === '') {
            $_SESSION['profile_details_feedback'] = ['type' => 'error', 'message' => 'Informe nome e e-mail válidos.'];
            $_SESSION['profile_details_values'] = $values;
            return new RedirectResponse(url('profile'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['profile_details_feedback'] = ['type' => 'error', 'message' => 'E-mail informado é inválido.'];
            $_SESSION['profile_details_values'] = $values;
            return new RedirectResponse(url('profile'));
        }

        if ($this->users->emailExists($email, $user->id)) {
            $_SESSION['profile_details_feedback'] = ['type' => 'error', 'message' => 'Já existe outro usuário com este e-mail.'];
            $_SESSION['profile_details_values'] = $values;
            return new RedirectResponse(url('profile'));
        }

        $this->users->update($user->id, [
            'name' => $name,
            'email' => $email,
        ]);

        $_SESSION['profile_details_feedback'] = ['type' => 'success', 'message' => 'Dados atualizados com sucesso.'];

        return new RedirectResponse(url('profile'));
    }

    public function updatePassword(Request $request): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return new RedirectResponse(url('auth/login'));
        }

        $currentPassword = (string)$request->request->get('current_password', '');
        $newPassword = (string)$request->request->get('new_password', '');
        $confirmPassword = (string)$request->request->get('new_password_confirmation', '');

        if ($newPassword === '' || $confirmPassword === '') {
            $_SESSION['profile_password_feedback'] = ['type' => 'error', 'message' => 'Informe a nova senha e a confirmação.'];
            return new RedirectResponse(url('profile'));
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['profile_password_feedback'] = ['type' => 'error', 'message' => 'A confirmação não corresponde à nova senha.'];
            return new RedirectResponse(url('profile'));
        }

        $record = $this->users->find($user->id);
        if ($record === null) {
            $_SESSION['profile_password_feedback'] = ['type' => 'error', 'message' => 'Usuário não encontrado para atualizar a senha.'];
            return new RedirectResponse(url('profile'));
        }

        $hash = (string)($record['password_hash'] ?? '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            $_SESSION['profile_password_feedback'] = ['type' => 'error', 'message' => 'Senha atual incorreta.'];
            return new RedirectResponse(url('profile'));
        }

        $previousHash = (string)($record['previous_password_hash'] ?? '');
        $policyError = PasswordPolicy::validate($newPassword, $hash, $previousHash);
        if ($policyError !== null) {
            $_SESSION['profile_password_feedback'] = ['type' => 'error', 'message' => $policyError];
            return new RedirectResponse(url('profile'));
        }

        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
        $this->users->updatePassword($user->id, $newHash);
        $this->auth->clearPasswordChangeRequirement();
        unset($_SESSION['profile_password_feedback']);

        $_SESSION['profile_password_feedback'] = ['type' => 'success', 'message' => 'Senha alterada com sucesso.'];

        return new RedirectResponse(url('profile'));
    }

    public function uploadPhoto(Request $request): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return new RedirectResponse(url('auth/login'));
        }

        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $_SESSION['profile_media_feedback'] = ['type' => 'error', 'message' => 'Envie uma foto válida.'];
            return new RedirectResponse(url('profile'));
        }

        $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
        $mime = (string)$file->getClientMimeType();
        if (!in_array($mime, $allowedMime, true)) {
            $_SESSION['profile_media_feedback'] = ['type' => 'error', 'message' => 'Formato da foto não suportado. Use JPG, PNG ou WEBP.'];
            return new RedirectResponse(url('profile'));
        }

        $size = (int)$file->getSize();
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            $_SESSION['profile_media_feedback'] = ['type' => 'error', 'message' => 'A foto deve ter até 2MB.'];
            return new RedirectResponse(url('profile'));
        }

        $targetDir = storage_path('uploads/profile/' . $user->id);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $extension = strtolower((string)$file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = 'bin';
        }

        $filename = $this->generateSafeFilename('photo', $extension);
        $file->move($targetDir, $filename);

        $state = $this->readMediaState();
        $state[$user->id]['photo'] = [
            'path' => 'uploads/profile/' . $user->id . '/' . $filename,
            'updated_at' => date('c'),
        ];

        if (!$this->writeMediaState($state)) {
            $_SESSION['profile_media_feedback'] = ['type' => 'error', 'message' => 'Erro ao salvar metadados da foto.'];
            return new RedirectResponse(url('profile'));
        }

        $_SESSION['profile_media_feedback'] = ['type' => 'success', 'message' => 'Foto atualizada com sucesso.'];

        return new RedirectResponse(url('profile'));
    }

    public function uploadCnpjVideo(Request $request): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return new RedirectResponse(url('auth/login'));
        }

        $cnpj = digits_only((string)$request->request->get('cnpj', ''));
        if (strlen($cnpj) !== 14) {
            $_SESSION['profile_media_feedback'] = ['type' => 'error', 'message' => 'CNPJ inválido.'];
            return new RedirectResponse(url('profile'));
        }

        $file = $request->files->get('video');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $_SESSION['profile_media_feedback'] = ['type' => 'error', 'message' => 'Envie um vídeo válido.'];
            return new RedirectResponse(url('profile'));
        }

        $allowedMime = ['video/mp4', 'video/webm', 'video/quicktime'];
        $mime = (string)$file->getClientMimeType();
        if (!in_array($mime, $allowedMime, true)) {
            $_SESSION['profile_media_feedback'] = ['type' => 'error', 'message' => 'Formato do vídeo não suportado. Use MP4, WEBM ou MOV.'];
            return new RedirectResponse(url('profile'));
        }

        $size = (int)$file->getSize();
        if ($size <= 0 || $size > 50 * 1024 * 1024) {
            $_SESSION['profile_media_feedback'] = ['type' => 'error', 'message' => 'O vídeo deve ter até 50MB (máx. ~1 min).'];
            return new RedirectResponse(url('profile'));
        }

        $targetDir = storage_path('uploads/profile/' . $user->id . '/cnpj-' . $cnpj);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $extension = strtolower((string)$file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = 'mp4';
        }

        $filename = $this->generateSafeFilename('video', $extension);
        $file->move($targetDir, $filename);

        $state = $this->readMediaState();
        $state[$user->id]['cnpjVideos'][$cnpj] = [
            'path' => 'uploads/profile/' . $user->id . '/cnpj-' . $cnpj . '/' . $filename,
            'uploaded_at' => date('c'),
        ];

        if (!$this->writeMediaState($state)) {
            $_SESSION['profile_media_feedback'] = ['type' => 'error', 'message' => 'Erro ao salvar metadados do vídeo.'];
            return new RedirectResponse(url('profile'));
        }

        $_SESSION['profile_media_feedback'] = ['type' => 'success', 'message' => 'Vídeo do CNPJ salvo com sucesso.'];

        return new RedirectResponse(url('profile'));
    }

    private function currentUser(Request $request): ?AuthenticatedUser
    {
        $user = $request->attributes->get('user');
        if ($user instanceof AuthenticatedUser) {
            return $user;
        }

        return $this->auth->currentUser();
    }

    private function generateSafeFilename(string $prefix, string $extension): string
    {
        try {
            $random = bin2hex(random_bytes(6));
        } catch (\Throwable $exception) {
            $random = bin2hex((string)uniqid('', true));
        }

        return $prefix . '_' . date('Ymd_His') . '_' . $random . '.' . $extension;
    }

    private function readMediaState(): array
    {
        $path = $this->mediaStatePath();
        if (!is_file($path)) {
            return [];
        }

        $json = (string)file_get_contents($path);
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private function writeMediaState(array $state): bool
    {
        $path = $this->mediaStatePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        return file_put_contents($path, $payload, LOCK_EX) !== false;
    }

    private function mediaStatePath(): string
    {
        return storage_path('profile_media.json');
    }
}
