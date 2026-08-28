<?php

declare(strict_types=1);

namespace Ytan;

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Throwable;
use Ytan\Database\Connection;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ApiException;
use Ytan\Http\Controllers\AreaController;
use Ytan\Http\Controllers\AuthController;
use Ytan\Http\Controllers\PoiController;
use Ytan\Http\Controllers\RouteController;
use Ytan\Http\Controllers\TourController;
use Ytan\Http\Controllers\WsiController;
use Ytan\Http\Middleware\AuthMiddleware;
use Ytan\Http\Middleware\CorsMiddleware;
use Ytan\Service\AuthService;
use Ytan\Service\WsiRenderer;

final class App
{
    public static function create(string $rootDir): SlimApp
    {
        if (is_file($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        $pdo = Connection::fromEnv();

        $userRepository = new UserRepository($pdo);
        $authService = new AuthService(
            $userRepository,
            $_ENV['JWT_SECRET'] ?? 'insecure-dev-secret',
            (int) ($_ENV['JWT_TTL_SECONDS'] ?? 86400)
        );
        $poiController = new PoiController(new PoiRepository($pdo));
        $routeController = new RouteController(new RouteRepository($pdo));
        $tourController = new TourController(new TourRepository($pdo));
        $areaController = new AreaController(new AreaRepository($pdo));
        $authController = new AuthController($authService);
        $wsiController = new WsiController(new WsiRenderer($rootDir . '/public/images/wsi'));

        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->add(new CorsMiddleware($_ENV['ALLOWED_ORIGINS'] ?? '*'));
        $app->add(new AuthMiddleware($authService));
        $app->addRoutingMiddleware();

        $displayErrors = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        $errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            function (Request $request, Throwable $exception) use ($app, $displayErrors) {
                $status = match (true) {
                    $exception instanceof ApiException => $exception->getStatusCode(),
                    $exception instanceof \Slim\Exception\HttpSpecializedException => $exception->getCode(),
                    default => 500,
                };
                $payload = ['error' => ['message' => $exception->getMessage()]];
                if ($displayErrors && $status === 500) {
                    $payload['error']['trace'] = explode("\n", $exception->getTraceAsString());
                }

                $response = $app->getResponseFactory()->createResponse($status);
                $response->getBody()->write(json_encode($payload));

                return $response->withHeader('Content-Type', 'application/json');
            }
        );

        $app->get('/api/v1/health', fn (Request $req, Response $res) => self::jsonOk($res, ['status' => 'ok']));

        $app->post('/api/v1/auth/login', [$authController, 'login']);
        $app->get('/api/v1/auth/me', [$authController, 'me']);

        $app->get('/api/v1/pois', [$poiController, 'index']);
        $app->get('/api/v1/pois/bounds', [$poiController, 'bounds']);
        $app->get('/api/v1/pois/{id}', [$poiController, 'show']);
        $app->post('/api/v1/pois', [$poiController, 'create']);
        $app->put('/api/v1/pois/{id}', [$poiController, 'update']);
        $app->delete('/api/v1/pois/{id}', [$poiController, 'delete']);

        $app->get('/api/v1/routes', [$routeController, 'index']);
        $app->get('/api/v1/routes/{id}', [$routeController, 'show']);
        $app->post('/api/v1/routes', [$routeController, 'create']);
        $app->put('/api/v1/routes/{id}', [$routeController, 'update']);
        $app->delete('/api/v1/routes/{id}', [$routeController, 'delete']);

        $app->get('/api/v1/tours', [$tourController, 'index']);
        $app->get('/api/v1/tours/{id}', [$tourController, 'show']);

        $app->get('/api/v1/areas', [$areaController, 'index']);
        $app->get('/api/v1/areas/{id}', [$areaController, 'show']);
        $app->post('/api/v1/areas', [$areaController, 'create']);
        $app->put('/api/v1/areas/{id}', [$areaController, 'update']);
        $app->delete('/api/v1/areas/{id}', [$areaController, 'delete']);

        $app->get('/api/v1/wsi/{code}', [$wsiController, 'show']);

        $app->get('/', function (Request $req, Response $res) use ($rootDir) {
            ob_start();
            $mapsApiKey = $_ENV['MAPS_API_KEY'] ?? '';
            $appName = $_ENV['APP_NAME'] ?? 'YTAN';
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            require $rootDir . '/templates/app.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/legal/impressum', function (Request $req, Response $res) use ($rootDir) {
            ob_start();
            require $rootDir . '/templates/impressum.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/legal/datenschutz', function (Request $req, Response $res) use ($rootDir) {
            ob_start();
            require $rootDir . '/templates/datenschutz.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        return $app;
    }

    private static function jsonOk(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
