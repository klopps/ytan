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
use Ytan\Http\Controllers\AdminController;
use Ytan\Http\Controllers\AreaController;
use Ytan\Http\Controllers\AuthController;
use Ytan\Http\Controllers\GeocodingController;
use Ytan\Http\Controllers\PoiController;
use Ytan\Http\Controllers\RouteController;
use Ytan\Http\Controllers\SettingsController;
use Ytan\Http\Controllers\TourController;
use Ytan\Http\Controllers\TranslationController;
use Ytan\Http\Controllers\UserController;
use Ytan\Http\Controllers\WeatherController;
use Ytan\Http\Controllers\WsiController;
use Ytan\Http\Middleware\AuthMiddleware;
use Ytan\Http\Middleware\CorsMiddleware;
use Ytan\Service\AuthService;
use Ytan\Service\CaptchaService;
use Ytan\Service\CurlJsonHttpClient;
use Ytan\Service\GeocodingService;
use Ytan\Service\ImageReconciliationService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\MailService;
use Ytan\Service\TourNotificationService;
use Ytan\Service\TranslationRepository;
use Ytan\Service\TranslationUsageScanner;
use Ytan\Service\Translator;
use Ytan\Service\WeatherService;
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

        $supportedLocales = array_map('trim', explode(',', $_ENV['SUPPORTED_LOCALES'] ?? 'en,de'));
        $locale = Translator::resolveLocale(
            $supportedLocales,
            $_COOKIE['settings'] ?? null,
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
        );
        $translator = new Translator($rootDir . '/resources/i18n', $locale);

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
        $tourRepository = new TourRepository($pdo);
        $captchaService = new CaptchaService($_ENV['JWT_SECRET'] ?? 'insecure-dev-secret');
        $tourNotificationService = new TourNotificationService($userRepository, $mailService, $appUrl);
        // One ImageStorageService instance per entity type - the class
        // itself is entity-agnostic, only the storage subdirectory differs.
        $tourImageService = new ImageStorageService($rootDir . '/storage/tour-images');
        $poiImageService = new ImageStorageService($rootDir . '/storage/poi-images');
        $routeImageService = new ImageStorageService($rootDir . '/storage/route-images');
        $areaImageService = new ImageStorageService($rootDir . '/storage/area-images');
        $poiRepository = new PoiRepository($pdo);
        $routeRepository = new RouteRepository($pdo);
        $areaRepository = new AreaRepository($pdo);
        $poiController = new PoiController($poiRepository, $poiImageService);
        $routeController = new RouteController($routeRepository, $tourRepository, $captchaService, $tourNotificationService, $routeImageService);
        $tourController = new TourController($tourRepository, $tourImageService);
        $areaController = new AreaController($areaRepository, $areaImageService);
        $adminController = new AdminController(new ImageReconciliationService([
            'tour' => ['repository' => $tourRepository, 'images' => $tourImageService],
            'poi' => ['repository' => $poiRepository, 'images' => $poiImageService],
            'route' => ['repository' => $routeRepository, 'images' => $routeImageService],
            'area' => ['repository' => $areaRepository, 'images' => $areaImageService],
        ]));
        $authController = new AuthController($authService, $userRepository, $mailService, $appUrl);
        $userController = new UserController($userRepository, $mailService, $authService, $appUrl);
        $wsiController = new WsiController(new WsiRenderer($rootDir . '/public/images/wsi'));
        $settingsRepository = new SettingsRepository($pdo);
        $settingsController = new SettingsController($settingsRepository);
        $translationController = new TranslationController(
            new TranslationRepository($rootDir . '/resources/i18n'),
            new TranslationUsageScanner($rootDir)
        );
        $jsonHttpClient = new CurlJsonHttpClient();
        $weatherController = new WeatherController(
            new WeatherService($rootDir . '/storage/weather-cache', $jsonHttpClient)
        );
        $geocodingController = new GeocodingController(
            new GeocodingService($rootDir . '/storage/geocoding-cache', $jsonHttpClient, $locale)
        );

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
                if ($exception instanceof ApiException && $exception->getErrorCode() !== null) {
                    $payload['error']['code'] = $exception->getErrorCode();
                }
                if ($exception instanceof \Ytan\Exception\CaptchaRequiredException) {
                    $payload['error'] = array_merge($payload['error'], $exception->getPayload());
                }
                if ($exception instanceof \Ytan\Exception\TranslationKeyMismatchException) {
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
        $app->post('/api/v1/pois/{id}/images', [$poiController, 'uploadImage']);
        $app->get('/api/v1/pois/{id}/images', [$poiController, 'listImages']);
        $app->get('/api/v1/pois/{id}/images/{imageId}', [$poiController, 'showImage']);
        $app->delete('/api/v1/pois/{id}/images/{imageId}', [$poiController, 'deleteImage']);

        $app->get('/api/v1/routes', [$routeController, 'index']);
        $app->get('/api/v1/routes/{id}', [$routeController, 'show']);
        $app->post('/api/v1/routes', [$routeController, 'create']);
        $app->put('/api/v1/routes/{id}', [$routeController, 'update']);
        $app->delete('/api/v1/routes/{id}', [$routeController, 'delete']);
        $app->post('/api/v1/routes/{id}/images', [$routeController, 'uploadImage']);
        $app->get('/api/v1/routes/{id}/images', [$routeController, 'listImages']);
        $app->get('/api/v1/routes/{id}/images/{imageId}', [$routeController, 'showImage']);
        $app->delete('/api/v1/routes/{id}/images/{imageId}', [$routeController, 'deleteImage']);

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
        $app->post('/api/v1/areas/{id}/images', [$areaController, 'uploadImage']);
        $app->get('/api/v1/areas/{id}/images', [$areaController, 'listImages']);
        $app->get('/api/v1/areas/{id}/images/{imageId}', [$areaController, 'showImage']);
        $app->delete('/api/v1/areas/{id}/images/{imageId}', [$areaController, 'deleteImage']);

        $app->get('/api/v1/users', [$userController, 'index']);
        $app->get('/api/v1/users/{id}', [$userController, 'show']);
        $app->post('/api/v1/users', [$userController, 'create']);
        $app->put('/api/v1/users/{id}', [$userController, 'update']);
        $app->delete('/api/v1/users/{id}', [$userController, 'delete']);
        $app->put('/api/v1/users/{id}/password', [$userController, 'setPassword']);
        $app->post('/api/v1/users/{id}/send-reset', [$userController, 'sendResetEmail']);

        $app->get('/api/v1/wsi/{code}', [$wsiController, 'show']);

        $app->get('/api/v1/weather', [$weatherController, 'show']);
        $app->get('/api/v1/geocode/reverse', [$geocodingController, 'reverse']);

        $app->put('/api/v1/settings/google-search-requires-login', [$settingsController, 'updateGoogleSearchRequiresLogin']);

        $app->get('/', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $settingsRepository, $translator) {
            ob_start();
            $mapsApiKey = $_ENV['MAPS_API_KEY'] ?? '';
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $googleSearchRequiresLogin = $settingsRepository->googleSearchRequiresLogin();
            $appVersion = 'dev';
            $versionFile = $rootDir . '/VERSION';
            if (is_file($versionFile)) {
                $appVersion = trim(file_get_contents($versionFile));
            }
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
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

        $app->get('/about', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
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

        $app->get('/set-password', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            require $rootDir . '/templates/set-password.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/forgot-password', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            require $rootDir . '/templates/forgot-password.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        $app->get('/confirm-email', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            require $rootDir . '/templates/confirm-email.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        // Standalone admin tools menu (templates/admin.php, public/js/
        // admin.js) - same self-contained login-gate pattern as the
        // translation editor below (own login form, checks GET /auth/me's
        // is_admin client-side; never part of the main SPA's script chain).
        // Always registered, unlike the translate tool's extra opt-in flag
        // below - this page itself has no risky capability of its own, it
        // only links to tools that carry their own gating/flags. New admin
        // tools should be added as their own route + menu entry here, not
        // as a growing pile of unrelated features crammed into one page.
        $app->get('/admin', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $settingsRepository) {
            ob_start();
            $translateToolEnabled = ($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true';
            $googleSearchRequiresLogin = $settingsRepository->googleSearchRequiresLogin();
            require $rootDir . '/templates/admin.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        // User management, moved out of the main SPA's drawer
        // (templates/admin-users.php, public/js/admin-users.js) - loads a
        // slim version of the main app (i18n/toast/confirm-dialog, but no
        // map/POIs/routes/areas/tours) directly into the existing,
        // unchanged admin-user.js panel. No opt-in flag needed (same
        // reasoning as /admin itself) - the /api/v1/users* endpoints are
        // already independently admin-gated server-side.
        $app->get('/admin/users', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            require $rootDir . '/templates/admin-users.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        // Dev-only translation editor (templates/translate.php,
        // public/js/translate.js) - writes directly to
        // resources/i18n/{en,de}.json, which every page injects into a
        // <script> tag on every request. Admin-JWT-gated like every other
        // admin endpoint (TranslationController::requireAdmin()), but that
        // alone isn't enough given how much larger this endpoint's blast
        // radius is than a typical admin action - it's additionally kept
        // off entirely unless explicitly enabled, so a deployed production
        // site is unreachable here even with a compromised admin token
        // (JWTs are valid for 10 years by default - see JWT_TTL_SECONDS).
        // Reachable via the /admin menu above, hence the nested path.
        if (($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true') {
            $app->get('/admin/translate', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
                ob_start();
                require $rootDir . '/templates/translate.php';
                $res->getBody()->write(ob_get_clean());

                return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
            });

            $app->get('/api/v1/translations', [$translationController, 'index']);
            $app->put('/api/v1/translations', [$translationController, 'update']);
        }

        // Admin tool: finds photo files/DB rows left behind by a bug or a
        // failed request (AdminController, ImageReconciliationService) -
        // reachable via the /admin menu above. No opt-in flag needed (see
        // AdminController's own doc comment): unlike the translate tool,
        // this action by construction only ever touches already-orphaned
        // files/rows, so the standard requireAdmin() gate is enough.
        $app->get('/admin/image-cleanup', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/admin-image-cleanup.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });
        $app->get('/api/v1/admin/image-orphans', [$adminController, 'scanImageOrphans']);
        $app->post('/api/v1/admin/image-orphans/delete', [$adminController, 'deleteImageOrphans']);

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
