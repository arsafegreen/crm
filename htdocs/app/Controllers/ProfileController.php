<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Auth\PasswordPolicy;
use App\Auth\SessionAuthService;
use App\Repositories\UserRepository;
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

    private function currentUser(Request $request): ?AuthenticatedUser
    {
        $user = $request->attributes->get('user');
        if ($user instanceof AuthenticatedUser) {
            return $user;
        }

        return $this->auth->currentUser();
    }
}
