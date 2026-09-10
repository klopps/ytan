<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\AuthService;
use Ytan\Service\MailService;

final class UserController extends BaseController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly AuthService $authService,
        private readonly string $appUrl,
    ) {
    }

    private const TOUR_RIGHT_FIELDS = ['is_admin', 'tour_create', 'tour_publish', 'tour_manage', 'tour_copy'];

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $params = $request->getQueryParams();
        $filters = [];
        foreach (self::TOUR_RIGHT_FIELDS as $field) {
            if (isset($params[$field])) {
                $filters[$field] = $params[$field];
            }
        }
        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);

        $body = ['data' => $this->users->findAll($filters, $limit, $offset)];
        if ($limit !== null) {
            $body['meta'] = ['total' => $this->users->countAll($filters), 'limit' => $limit, 'offset' => $offset];
        }

        return $this->json($response, $body);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->findOrFail((int) $args['id'])]);
    }

    public function create(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $data = $this->jsonBody($request);

        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($username === '' || $email === '') {
            throw new ValidationException('username and email are required.');
        }

        $user = $this->users->create($data);
        $token = $this->users->createToken((int) $user['id'], 'invite', 7 * 24 * 3600);
        $link = $this->appUrl . '/set-password?token=' . $token;
        $this->mail->sendInvite($user['email'], $user['username'], $link);

        unset($user['password']);

        return $this->json($response, ['data' => $user], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $id = (int) $args['id'];
        $this->findOrFail($id);

        $data = $this->jsonBody($request);
        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($username === '' || $email === '') {
            throw new ValidationException('username and email are required.');
        }

        $user = $this->users->update($id, $data);
        unset($user['password']);

        return $this->json($response, ['data' => $user]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAdmin($request);
        $id = (int) $args['id'];

        if ($id === (int) $auth['sub']) {
            throw new ValidationException('You cannot delete your own account.');
        }

        $this->findOrFail($id);
        $this->users->delete($id);

        return $this->json($response, ['data' => ['id' => $id]]);
    }

    /**
     * Admin override - sets a user's password directly (no current-password
     * check, unlike AuthController::changePassword()). Useful e.g. when a
     * user is locked out and can't receive the reset email themselves.
     */
    public function setPassword(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $id = (int) $args['id'];
        $this->findOrFail($id);

        $password = (string) ($this->jsonBody($request)['password'] ?? '');
        if ($password === '') {
            throw new ValidationException('password is required.');
        }

        $this->authService->adminSetPassword($id, $password);

        return $this->json($response, ['message' => 'Password changed.']);
    }

    /**
     * Admin-triggered equivalent of AuthController::forgotPassword() for one
     * specific, already-identified user - no anti-enumeration masking
     * needed here since the admin picked this user explicitly from the list.
     */
    public function sendResetEmail(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $id = (int) $args['id'];
        $user = $this->findOrFail($id);

        if (empty($user['email'])) {
            throw new ValidationException('This user has no email address on file.');
        }

        $token = $this->users->createToken($id, 'reset', 3600);
        $link = $this->appUrl . '/set-password?token=' . $token;
        $this->mail->sendPasswordReset($user['email'], $link);

        return $this->json($response, ['message' => 'Password reset email sent.']);
    }

    private function findOrFail(int $id): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw new NotFoundException("User $id not found.");
        }

        unset($user['password']);

        return $user;
    }
}
