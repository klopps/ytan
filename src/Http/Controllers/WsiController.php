<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Service\WsiRenderer;

final class WsiController extends BaseController
{
    public function __construct(private readonly WsiRenderer $renderer)
    {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $code = preg_replace('/\.svg$/', '', (string) $args['code']);
        $svg = $this->renderer->render($code);

        $response->getBody()->write($svg);

        return $response
            ->withHeader('Content-Type', 'image/svg+xml')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
