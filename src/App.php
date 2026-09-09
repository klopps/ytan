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
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ApiException;
use Ytan\Http\Controllers\AreaController;
use Ytan\Http\Controllers\AuthController;
use Ytan\Http\Controllers\PoiController;
use Ytan\Http\Controllers\RouteController;
use Ytan\Http\Controllers\SettingsController;
use Ytan\Http\Controllers\TourController;
use Ytan\Http\Controllers\UserController;
use Ytan\Http\Controllers\WsiController;
use Ytan\Http\Middleware\AuthMiddleware;
use Ytan\Http\Middleware\CorsMiddleware;
use Ytan\Service\AuthService;
use Ytan\Service\CaptchaService;
use Ytan\Service\MailService;
use Ytan\Service\TourImageService;
use Ytan\Service\TourNotificationService;
use Ytan\Service\WsiRenderer;

final class App
{
    public static function create(string $rootDir): SlimApp
    {
        if (is_file($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        $pdo = Connection::fromEnv();

        $appName = $_ENV['APP_NAME'] ?? 'YTAN';
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');

        $userRepository = new UserRepository($pdo);
        $mailService = new MailService(
            $_ENV['MAIL_HOST'] ?? '',
            (int) ($_ENV['MAIL_PORT'] ?? 587),
            $_ENV['MAIL_USERNAME'] ?? '',
            $_ENV['MAIL_PASSWORD'] ?? '',
            $_ENV['MAIL_FROM'] ?? '',
            $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
            $appName
        );
        $authService = new AuthService(
            $userRepository,
            $_ENV['JWT_SECRET'] ?? 'insecure-dev-secret',
            (int) ($_ENV['JWT_TTL_SECONDS'] ?? 86400),
            $mailService,
            $appUrl
        );
        $poiController = new PoiController(new PoiRepository($pdo));
        $tourRepository = new TourRepository($pdo);
        $captchaService = new CaptchaService($_ENV['JWT_SECRET'] ?? 'insecure-dev-secret');
        $tourNotificationService = new TourNotificationService($userRepository, $mailService, $appUrl);
        $tourImageService = new TourImageService($rootDir . '/storage/tour-images');
        $routeController = new RouteController(new RouteRepository($pdo), $tourRepository, $captchaService, $tourNotificationService);
        $tourController = new TourController($tourRepository, $tourImageService);
        $areaController = new AreaController(new AreaRepository($pdo));
        $authController = new AuthController($authService, $userRepository, $mailService, $appUrl);
        $userController = new UserController($userRepository, $mailService, $authService, $appUrl);
        $wsiController = new WsiController(new WsiRenderer($rootDir . '/public/images/wsi'));
        $settingsRepository = new SettingsRepository($pdo);
        $settingsController = new SettingsController($settingsRepository);

        $app = AppFactory::create();

        // When the app isn't served from the web root (e.g. Apache/XAMPP
        // serving this whole project as http://localhost/ytan/public/
        // instead of a vhost whose document root IS public/), routes like
        // "/" or "/api/v1/..." need the request's subdirectory prefix
        // stripped before Slim tries to match them against it.
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $baseUrl = ($scriptDir === '' || $scriptDir === '/') ? '' : $scriptDir;
        if ($baseUrl !== '') {
            $app->setBasePath($baseUrl);
        }

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
                if ($exception instanceof \Ytan\Exception\CaptchaRequiredException) {
                    $payload['error'] = array_merge($payload['error'], $exception->getPayload());
                }
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
        $app->post('/api/v1/auth/forgot-password', [$authController, 'forgotPassword']);
        $app->post('/api/v1/auth/set-password', [$authController, 'setPassword']);
        $app->put('/api/v1/auth/password', [$authController, 'changePassword']);
        $app->put('/api/v1/auth/profile', [$authController, 'updateProfile']);
        $app->delete('/api/v1/auth/email-change', [$authController, 'cancelEmailChange']);
        $app->post('/api/v1/auth/confirm-email-change', [$authController, 'confirmEmailChange']);

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
        $app->post('/api/v1/tours', [$tourController, 'create']);
        $app->put('/api/v1/tours/{id}', [$tourController, 'update']);
        $app->delete('/api/v1/tours/{id}', [$tourController, 'delete']);
        $app->post('/api/v1/tours/{id}/routes', [$tourController, 'addRoute']);
        $app->delete('/api/v1/tours/{id}/routes/{routeId}', [$tourController, 'removeRoute']);
        $app->put('/api/v1/tours/{id}/routes/order', [$tourController, 'reorderRoutes']);
        $app->put('/api/v1/tours/{id}/publish', [$tourController, 'publish']);
        $app->post('/api/v1/tours/{id}/copy', [$tourController, 'copy']);
        $app->post('/api/v1/tours/{id}/images', [$tourController, 'uploadImage']);
        $app->get('/api/v1/tours/{id}/images/{imageId}', [$tourController, 'showImage']);
        $app->delete('/api/v1/tours/{id}/images/{imageId}', [$tourController, 'deleteImage']);
        $app->put('/api/v1/tours/{id}/images/order', [$tourController, 'reorderImages']);

        $app->get('/api/v1/areas', [$areaController, 'index']);
        $app->get('/api/v1/areas/{id}', [$areaController, 'show']);
        $app->post('/api/v1/areas', [$areaController, 'create']);
        $app->put('/api/v1/areas/{id}', [$areaController, 'update']);
        $app->delete('/api/v1/areas/{id}', [$areaController, 'delete']);

        $app->get('/api/v1/users', [$userController, 'index']);
        $app->get('/api/v1/users/{id}', [$userController, 'show']);
        $app->post('/api/v1/users', [$userController, 'create']);
        $app->put('/api/v1/users/{id}', [$userController, 'update']);
        $app->delete('/api/v1/users/{id}', [$userController, 'delete']);
        $app->put('/api/v1/users/{id}/password', [$userController, 'setPassword']);
        $app->post('/api/v1/users/{id}/send-reset', [$userController, 'sendResetEmail']);

        $app->get('/api/v1/wsi/{code}', [$wsiController, 'show']);

        $app->put('/api/v1/settings/google-search-requires-login', [$settingsController, 'updateGoogleSearchRequiresLogin']);

        $app->get('/', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $settingsRepository) {
            ob_start();
            $mapsApiKey = $_ENV['MAPS_API_KEY'] ?? '';
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $googleSearchRequiresLogin = $settingsRepository->googleSearchRequiresLogin();
            require $rootDir . '/templates/app.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/sw.js', function (Request $req, Response $res) use ($rootDir, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/sw.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'application/javascript; charset=utf-8');
        });

        $app->get('/about', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/about.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/legal/imprint', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/imprint.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/legal/privacy', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/privacy.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/set-password', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/set-password.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/forgot-password', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/forgot-password.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/confirm-email', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/confirm-email.php';
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

    /**
     * Cache-busting suffix for a static asset, appended as a `?v=` query
     * string by templates (e.g. `<?= App::assetVersion($rootDir, '/css/style.css') ?>`).
     * public/css/js/lib files have no Cache-Control header, so browsers fall
     * back to heuristic caching that can keep serving a stale copy for a
     * long time after a deploy; changing the URL on every file edit forces
     * a fresh fetch regardless of that.
     */
    public static function assetVersion(string $rootDir, string $relativePath): string
    {
        $file = $rootDir . '/public' . $relativePath;

        return (string) (is_file($file) ? filemtime($file) : time());
    }
}
