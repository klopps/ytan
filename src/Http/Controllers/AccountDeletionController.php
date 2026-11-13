<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Service\AccountDeletionService;
use Ytan\Service\MailService;
use Ytan\Service\RequestThrottle;

/**
 * Self-service account deletion (todo.md "Kontolöschung", done.md): the
 * user asks for it with their password, a link valid for 60 minutes is
 * mailed, and only redeeming that link (POST /auth/confirm-account-deletion
 * from the /confirm-account-deletion page) deletes the account through
 * AccountDeletionService. Until then the request can be withdrawn.
 */
final class AccountDeletionController extends BaseController
{
    public const TOKEN_PURPOSE = 'account_delete';
    public const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountDeletionService $deletion,
        private readonly MailService $mail,
        private readonly string $appUrl,
        private readonly RequestThrottle $throttle,
    ) {
    }

    /** What would happen + whether a request is pending. */
    public function status(Request $request, Response $response): Response
    {
        $userId = (int) $this->requireAuthUser($request)['sub'];
        $blocked = null;
        try {
            $this->deletion->assertDeletable($userId);
        } catch (ValidationException $e) {
            $blocked = $e->getErrorCode();
        }

        return $this->json($response, ['data' => [
            'preview' => $this->deletion->preview($userId),
            'blocked' => $blocked,
            'pending_expires_at' => $this->pendingExpiresAt($userId),
        ]]);
    }

    public function requestDeletion(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $userId = (int) $auth['sub'];
        $password = (string) ($this->jsonBody($request)['password'] ?? '');
        if ($password === '') {
            throw new ValidationException('The password is required.', 'account.password_required');
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new UnauthorizedException();
        }
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $this->throttle->assertAllowed(RequestThrottle::LOGIN, (string) $user['username'], $ip);
        if ($user['password'] === null || !password_verify($password, (string) $user['password'])) {
            $this->throttle->record(RequestThrottle::LOGIN, (string) $user['username'], $ip);
            throw new UnauthorizedException('Current password is incorrect.', 'auth.current_password_incorrect');
        }
        $this->deletion->assertDeletable($userId);

        $token = $this->users->createToken($userId, self::TOKEN_PURPOSE, self::TOKEN_TTL_SECONDS);
        try {
            $this->mail->sendAccountDeletionConfirmation(
                (string) $user['email'],
                (string) $user['username'],
                $this->appUrl . '/confirm-account-deletion?token=' . $token
            );
        } catch (\Throwable $e) {
            // Without the mail the request could never be confirmed - don't leave it pending.
            $this->users->deletePendingTokens($userId, self::TOKEN_PURPOSE);
            error_log('Account deletion mail failed: ' . $e->getMessage());
            throw new ApiException('The confirmation email could not be sent.', 502, 'account.mail_failed');
        }

        return $this->json($response, ['data' => ['pending_expires_at' => $this->pendingExpiresAt($userId)]]);
    }

    public function cancelDeletion(Request $request, Response $response): Response
    {
        $userId = (int) $this->requireAuthUser($request)['sub'];
        $this->users->deletePendingTokens($userId, self::TOKEN_PURPOSE);

        return $this->json($response, ['data' => ['pending_expires_at' => null]]);
    }

    /** Public - the mailed token is the proof (like confirm-email-change). */
    public function confirmDeletion(Request $request, Response $response): Response
    {
        $token = (string) ($this->jsonBody($request)['token'] ?? '');
        if ($token === '') {
            throw new ValidationException('Token is required.', 'auth.token_required');
        }
        $row = $this->users->findValidToken($token);
        if ($row === null || $row['purpose'] !== self::TOKEN_PURPOSE) {
            throw new ValidationException('This link is invalid or has expired.', 'auth.link_expired');
        }

        $user = $this->users->findById((int) $row['user_id']);
        $result = $this->deletion->delete((int) $row['user_id']);

        if ($user !== null) {
            try {
                $this->mail->sendAccountDeleted((string) $user['email'], (string) $user['username']);
            } catch (\Throwable $e) {
                // The account is gone either way - a mail problem must not report a failure.
                error_log('Account deletion mail failed: ' . $e->getMessage());
            }
        }

        return $this->json($response, ['data' => $result]);
    }

    private function pendingExpiresAt(int $userId): ?string
    {
        return $this->users->findActivePendingToken($userId, self::TOKEN_PURPOSE)['expires_at'] ?? null;
    }
}
