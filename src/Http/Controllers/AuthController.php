<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\AuthService;
use Ytan\Service\MailService;

final class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly string $appUrl,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        $body = $this->jsonBody($request);
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new ValidationException('username and password are required.');
        }

        return $this->json($response, $this->authService->login($username, $password));
    }

    public function me(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);

        $pending = $this->users->findActivePendingEmailChange((int) $auth['sub']);
        $auth['pending_email'] = $pending['payload'] ?? null;
        $auth['pending_email_expires_at'] = $pending['expires_at'] ?? null;

        return $this->json($response, ['data' => $auth]);
    }

    /**
     * Always responds with the same generic message regardless of whether
     * the account exists, to avoid leaking which usernames/emails are
     * registered.
     */
    public function forgotPassword(Request $request, Response $response): Response
    {
        $body = $this->jsonBody($request);
        $identifier = trim((string) ($body['identifier'] ?? ''));
        if ($identifier === '') {
            throw new ValidationException('identifier is required.');
        }

        $user = $this->users->findByUsername($identifier) ?? $this->users->findByEmail($identifier);
        if ($user !== null && !empty($user['email'])) {
            $token = $this->users->createToken((int) $user['id'], 'reset', 3600);
            $link = $this->appUrl . '/set-password?token=' . $token;
            try {
                $this->mail->sendPasswordReset($user['email'], $link);
            } catch (\Throwable $e) {
                // Swallowed deliberately: surfacing a mail-delivery failure
                // here (e.g. a 500) would let an attacker distinguish
                // "account exists but mail failed" from "account does not
                // exist", defeating the anti-enumeration point of always
                // returning the same response below.
            }
        }

        return $this->json($response, ['message' => 'If an account exists, a password reset email has been sent.']);
    }

    public function setPassword(Request $request, Response $response): Response
    {
        $body = $this->jsonBody($request);
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($token === '' || $password === '') {
            throw new ValidationException('token and password are required.');
        }

        return $this->json($response, $this->authService->setNewPassword($token, $password));
    }

    /**
     * Self-service password change for the currently logged-in user -
     * requires the current password. See UserController::setPassword() for
     * the admin-override variant that doesn't.
     */
    public function changePassword(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $body = $this->jsonBody($request);
        $currentPassword = (string) ($body['current_password'] ?? '');
        $newPassword = (string) ($body['new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '') {
            throw new ValidationException('current_password and new_password are required.');
        }

        $this->authService->changePassword((int) $auth['sub'], $currentPassword, $newPassword);

        return $this->json($response, ['message' => 'Password changed.']);
    }

    /**
     * Self-service update of the logged-in user's own firstname/lastname/
     * email - requires the current password (email is also the account's
     * recovery address, so changing it deserves the same proof-of-password
     * as changePassword()). firstname/lastname apply immediately; a changed
     * email only takes effect once confirmEmailChange() redeems the mailed
     * link - see AuthService::updateProfile().
     */
    public function updateProfile(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $body = $this->jsonBody($request);
        $firstname = trim((string) ($body['firstname'] ?? ''));
        $lastname = trim((string) ($body['lastname'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $currentPassword = (string) ($body['current_password'] ?? '');

        if ($firstname === '' || $lastname === '' || $email === '' || $currentPassword === '') {
            throw new ValidationException('firstname, lastname, email and current_password are required.');
        }

        return $this->json(
            $response,
            $this->authService->updateProfile((int) $auth['sub'], $firstname, $lastname, $email, $currentPassword)
        );
    }

    /**
     * Cancels a pending email-change request without touching the current
     * (unconfirmed) address.
     */
    public function cancelEmailChange(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $this->authService->cancelEmailChange((int) $auth['sub']);

        return $this->json($response, ['message' => 'Pending email change canceled.']);
    }

    /**
     * Redeems the token from an emailed "confirm your new email" link.
     * Public/unauthenticated - the token itself is the proof, same as
     * setPassword() above.
     */
    public function confirmEmailChange(Request $request, Response $response): Response
    {
        $body = $this->jsonBody($request);
        $token = (string) ($body['token'] ?? '');

        if ($token === '') {
            throw new ValidationException('token is required.');
        }

        return $this->json($response, $this->authService->confirmEmailChange($token));
    }
}
