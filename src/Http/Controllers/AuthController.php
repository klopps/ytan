<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Exception\ValidationException;
use Ytan\Service\AuthService;

final class AuthController extends BaseController
{
    public function __construct(private readonly AuthService $authService)
    {
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
        return $this->json($response, ['data' => $this->requireAuthUser($request)]);
    }
}
