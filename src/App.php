<?php

declare(strict_types=1);

namespace Ytan;

use Dotenv\Dotenv;
use League\CommonMark\GithubFlavoredMarkdownConverter;
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
use Ytan\Domain\Weather\WeatherForecastRepository;
use Ytan\Exception\ApiException;
use Ytan\Http\Controllers\AdminController;
use Ytan\Http\Controllers\AreaController;
use Ytan\Http\Controllers\AuthController;
use Ytan\Http\Controllers\GeocodingController;
use Ytan\Http\Controllers\GpxExportController;
use Ytan\Http\Controllers\GpxImportController;
use Ytan\Http\Controllers\PoiController;
use Ytan\Http\Controllers\RouteController;
use Ytan\Http\Controllers\RouteSplitController;
use Ytan\Http\Controllers\SettingsController;
use Ytan\Http\Controllers\TourController;
use Ytan\Http\Controllers\TranslationController;
use Ytan\Http\Controllers\UserController;
use Ytan\Http\Controllers\RadarController;
use Ytan\Http\Controllers\RadarSettingsController;
use Ytan\Http\Controllers\WeatherController;
use Ytan\Http\Controllers\WsiController;
use Ytan\Http\Middleware\AppVersionMiddleware;
use Ytan\Http\Controllers\LighthouseDuplicateController;
use Ytan\Http\Controllers\LighthouseImportController;
use Ytan\Domain\Poi\LighthouseDuplicateRepository;
use Ytan\Service\DataSourceText;
use Ytan\Service\LighthouseDuplicateService;
use Ytan\Http\Middleware\AuthMiddleware;
use Ytan\Http\Middleware\CompressionMiddleware;
use Ytan\Http\Middleware\ContentSecurityPolicyMiddleware;
use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Service\LighthouseImportService;
use Ytan\Service\LighthouseList\OsmLightMapper;
use Ytan\Service\LighthouseList\NgaMsiLightMapper;
use Ytan\Service\NgaMsi\CurlNgaMsiClient;
use Ytan\Service\NgaMsiImportService;
use Ytan\Service\OsmImportService;
use Ytan\Service\Overpass\CurlOverpassClient;
use Ytan\Http\Middleware\CorsMiddleware;
use Ytan\Service\GpxImportService;
use Ytan\Service\AuthService;
use Ytan\Service\RequestThrottle;
use Ytan\Service\JwtSecret;
use Ytan\Service\CaptchaService;
use Ytan\Service\CurlJsonHttpClient;
use Ytan\Service\GeocodingService;
use Ytan\Service\ImageReconciliationService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\MailService;
use Ytan\Service\StaticMapImageService;
use Ytan\Service\TourDocumentService;
use Ytan\Service\TourNotificationService;
use Ytan\Service\TranslationRepository;
use Ytan\Service\TranslationUsageScanner;
use Ytan\Service\Translator;
use Ytan\Service\Weather\OpenMeteoWeatherProvider;
use Ytan\Service\Weather\SmhiWeatherProvider;
use Ytan\Service\Weather\WeatherRegionResolver;
use Ytan\Service\WeatherService;
use Ytan\Service\RadarTileService;
use Ytan\Service\WsiRenderer;
use Ytan\Domain\Watch\WatchLinkRepository;
use Ytan\Service\UserGuideService;
use Ytan\Service\WatchKeyVault;
use Ytan\Http\Controllers\WatchController;

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
            // "settings" = the cookie's name before 2026-10-16 (public/js/settings.js).
            $_COOKIE['ytan_settings'] ?? $_COOKIE['settings'] ?? null,
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
        );
        $translator = new Translator($rootDir . '/resources/i18n', $locale);

        // Throws if JWT_SECRET is missing (no public fallback secret any more).
        $jwtKey = JwtSecret::key($_ENV['JWT_SECRET'] ?? null);

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
            $jwtKey,
            (int) ($_ENV['JWT_TTL_SECONDS'] ?? 86400),
            $mailService,
            $appUrl
        );

        // Everything below is built on demand (LazyContainer): a request only
        // creates the controller of its route and what that needs. Shared
        // pieces are registered by class name, the per-entity image stores
        // by 'images.<entity>'.
        $c = new LazyContainer();
        $c->set(UserRepository::class, fn () => $userRepository);
        $c->set(MailService::class, fn () => $mailService);

        $c->set(TourRepository::class, fn () => new TourRepository($pdo));
        $c->set(PoiRepository::class, fn () => new PoiRepository($pdo));
        $c->set(RouteRepository::class, fn () => new RouteRepository($pdo));
        $c->set(AreaRepository::class, fn () => new AreaRepository($pdo));
        $c->set(SettingsRepository::class, fn () => new SettingsRepository($pdo));
        $c->set(LighthouseImportRepository::class, fn () => new LighthouseImportRepository($pdo));
        $c->set(RequestThrottle::class, fn () => new RequestThrottle($pdo));
        $c->set(CaptchaService::class, fn () => new CaptchaService($jwtKey));
        $c->set(TourNotificationService::class, fn ($c) => new TourNotificationService($c->get(UserRepository::class), $c->get(MailService::class), $appUrl));
        // One ImageStorageService instance per entity type - the class
        // itself is entity-agnostic, only the storage subdirectory differs.
        foreach (['tour', 'poi', 'route', 'area'] as $entity) {
            $c->set("images.$entity", fn () => new ImageStorageService($rootDir . "/storage/$entity-images"));
        }
        $c->set(CurlJsonHttpClient::class, fn () => new CurlJsonHttpClient());
        $c->set(GeocodingService::class, fn ($c) => new GeocodingService($rootDir . '/storage/geocoding-cache', $c->get(CurlJsonHttpClient::class), $locale));

        $c->set(PoiController::class, fn ($c) => new PoiController($c->get(PoiRepository::class), $c->get('images.poi')));
        $c->set(RouteController::class, fn ($c) => new RouteController(
            $c->get(RouteRepository::class),
            $c->get(TourRepository::class),
            $c->get(CaptchaService::class),
            $c->get(TourNotificationService::class),
            $c->get('images.route')
        ));
        $c->set(TourController::class, fn ($c) => new TourController(
            $c->get(TourRepository::class),
            $c->get('images.tour'),
            new TourDocumentService(
                $c->get(TourRepository::class),
                $c->get(RouteRepository::class),
                $c->get(PoiRepository::class),
                $c->get(AreaRepository::class),
                $c->get('images.poi'),
                $c->get('images.area'),
                new StaticMapImageService(
                    $rootDir . '/storage/static-maps-cache',
                    $_ENV['MAPS_STATIC_API_KEY'] ?? ''
                ),
                new GithubFlavoredMarkdownConverter(),
                $rootDir . '/public/markers',
                // Fixed to German rather than the request-resolved $locale above -
                // the rest of the document's text (e.g. "Länge") is hardcoded
                // German too, so this keeps it internally consistent.
                new Translator($rootDir . '/resources/i18n', 'de'),
                $rootDir . '/public/images/ytan-logo.png',
                $appUrl
            )
        ));
        $c->set(AreaController::class, fn ($c) => new AreaController($c->get(AreaRepository::class), $c->get('images.area')));
        $c->set(LighthouseImportController::class, fn ($c) => new LighthouseImportController(
            $c->get(LighthouseImportRepository::class),
            new LighthouseImportService($c->get(LighthouseImportRepository::class), $c->get(PoiRepository::class)),
            new OsmImportService(
                CurlOverpassClient::fromSetting((string) ($_ENV['OVERPASS_ENDPOINTS'] ?? '')),
                $c->get(LighthouseImportRepository::class),
                new OsmLightMapper(),
                $rootDir . '/storage/overpass-last-request'
            ),
            new NgaMsiImportService(
                new CurlNgaMsiClient(),
                $c->get(LighthouseImportRepository::class),
                new NgaMsiLightMapper(),
                $rootDir . '/storage/nga-msi-last-request'
            )
        ));
        $c->set(LighthouseDuplicateController::class, fn ($c) => new LighthouseDuplicateController(
            new LighthouseDuplicateService(
                $c->get(PoiRepository::class),
                new LighthouseDuplicateRepository($pdo),
                $c->get('images.poi'),
                $pdo
            )
        ));
        $c->set(AdminController::class, fn ($c) => new AdminController(
            new ImageReconciliationService([
                'tour' => ['repository' => $c->get(TourRepository::class), 'images' => $c->get('images.tour')],
                'poi' => ['repository' => $c->get(PoiRepository::class), 'images' => $c->get('images.poi')],
                'route' => ['repository' => $c->get(RouteRepository::class), 'images' => $c->get('images.route')],
                'area' => ['repository' => $c->get(AreaRepository::class), 'images' => $c->get('images.area')],
            ]),
            $c->get(PoiRepository::class),
            $c->get(RouteRepository::class),
            $c->get(AreaRepository::class),
            $c->get(TourRepository::class),
            $c->get(UserRepository::class),
        ));
        $c->set(AuthController::class, fn ($c) => new AuthController($authService, $userRepository, $mailService, $appUrl, $c->get(RequestThrottle::class)));
        $c->set(UserController::class, fn () => new UserController($userRepository, $mailService, $authService, $appUrl));
        $c->set(RadarController::class, fn () => new RadarController(new RadarTileService($rootDir . '/storage/radar')));
        $c->set(WsiController::class, fn () => new WsiController(new WsiRenderer($rootDir . '/public/images/wsi')));
        $c->set(SettingsController::class, fn ($c) => new SettingsController($c->get(SettingsRepository::class)));
        $c->set(RadarSettingsController::class, fn ($c) => new RadarSettingsController($c->get(SettingsRepository::class), $rootDir . '/storage/radar'));
        $c->set(GpxExportController::class, fn ($c) => new GpxExportController($c->get(RouteRepository::class), $c->get(TourRepository::class), $c->get(SettingsRepository::class), $appName));
        $c->set(RouteSplitController::class, fn ($c) => new RouteSplitController($c->get(RouteRepository::class), $c->get(TourRepository::class), $c->get('images.route'), $pdo));
        $c->set(GpxImportController::class, fn ($c) => new GpxImportController($c->get(RouteRepository::class), $c->get(TourRepository::class), $c->get(PoiRepository::class), new GpxImportService(), $pdo));
        $c->set(TranslationController::class, fn () => new TranslationController(
            new TranslationRepository($rootDir . '/resources/i18n'),
            new TranslationUsageScanner($rootDir)
        ));
        $c->set(WeatherController::class, fn ($c) => new WeatherController(
            new WeatherService(
                new WeatherForecastRepository($pdo),
                new WeatherRegionResolver($c->get(GeocodingService::class)),
                new OpenMeteoWeatherProvider($c->get(CurlJsonHttpClient::class)),
                new SmhiWeatherProvider($c->get(CurlJsonHttpClient::class)),
            ),
            $c->get(RequestThrottle::class)
        ));
        $c->set(GeocodingController::class, fn ($c) => new GeocodingController($c->get(GeocodingService::class), $c->get(RequestThrottle::class)));
        $c->set(WatchController::class, fn ($c) => new WatchController(new WatchLinkRepository($pdo), $c->get(RouteRepository::class), new WatchKeyVault((string) $_ENV['JWT_SECRET'])));

        // Route callables [SomeController::class, 'method'] are resolved
        // through $c, i.e. only for the route that matched.
        AppFactory::setContainer($c);
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
        // No cross-origin access unless .env lists origins (the app itself is same-origin).
        $app->add(new CorsMiddleware($_ENV['ALLOWED_ORIGINS'] ?? ''));
        $app->add(new AppVersionMiddleware((int) ($_ENV['MIN_APP_VERSION_CODE'] ?? 0)));
        $app->add(new AuthMiddleware($authService));
        $app->add(new ContentSecurityPolicyMiddleware($baseUrl));
        // Added after (= runs around) the middlewares above, so it compresses their final body.
        $app->add(new CompressionMiddleware());
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
                if ($exception instanceof \Ytan\Exception\ConflictException) {
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

        // Content-Security-Policy violation reports (report-uri of
        // ContentSecurityPolicy): written to the PHP error log, shortened
        // and rate-limited per IP; always 204, also when throttled.
        $app->post('/api/v1/csp-report', function (Request $req, Response $res) use ($c) {
            try {
                $c->get(RequestThrottle::class)->hit(RequestThrottle::CSP_REPORT, (string) ($req->getServerParams()['REMOTE_ADDR'] ?? ''));
                $report = mb_substr(preg_replace('/\s+/', ' ', (string) $req->getBody()), 0, 2000);
                error_log('YTAN CSP violation: ' . $report);
            } catch (\Ytan\Exception\TooManyRequestsException) {
                // dropped
            }

            return $res->withStatus(204);
        });

        $app->post('/api/v1/auth/login', [AuthController::class, 'login']);
        $app->get('/api/v1/auth/me', [AuthController::class, 'me']);
        $app->get('/api/v1/auth/default-speed', [AuthController::class, 'defaultSpeed']);
        $app->put('/api/v1/auth/default-speed', [AuthController::class, 'setDefaultSpeed']);
        $app->post('/api/v1/auth/forgot-password', [AuthController::class, 'forgotPassword']);
        $app->post('/api/v1/auth/set-password', [AuthController::class, 'setPassword']);
        $app->put('/api/v1/auth/password', [AuthController::class, 'changePassword']);
        $app->post('/api/v1/auth/logout-all', [AuthController::class, 'logoutEverywhere']);
        $app->put('/api/v1/auth/profile', [AuthController::class, 'updateProfile']);
        $app->delete('/api/v1/auth/email-change', [AuthController::class, 'cancelEmailChange']);
        $app->post('/api/v1/auth/confirm-email-change', [AuthController::class, 'confirmEmailChange']);

        $app->get('/api/v1/pois', [PoiController::class, 'index']);
        $app->get('/api/v1/pois/bounds', [PoiController::class, 'bounds']);
        $app->get('/api/v1/pois/{id}', [PoiController::class, 'show']);
        $app->post('/api/v1/pois', [PoiController::class, 'create']);
        $app->put('/api/v1/pois/{id}', [PoiController::class, 'update']);
        $app->delete('/api/v1/pois/{id}', [PoiController::class, 'delete']);
        $app->post('/api/v1/pois/{id}/images', [PoiController::class, 'uploadImage']);
        $app->get('/api/v1/pois/{id}/images', [PoiController::class, 'listImages']);
        $app->get('/api/v1/pois/{id}/images/{imageId}', [PoiController::class, 'showImage']);
        $app->delete('/api/v1/pois/{id}/images/{imageId}', [PoiController::class, 'deleteImage']);

        $app->get('/api/v1/routes', [RouteController::class, 'index']);
        $app->get('/api/v1/routes/{id}', [RouteController::class, 'show']);
        $app->post('/api/v1/routes', [RouteController::class, 'create']);
        $app->put('/api/v1/routes/{id}', [RouteController::class, 'update']);
        $app->delete('/api/v1/routes/{id}', [RouteController::class, 'delete']);
        $app->post('/api/v1/routes/{id}/images', [RouteController::class, 'uploadImage']);
        $app->get('/api/v1/routes/{id}/images', [RouteController::class, 'listImages']);
        $app->get('/api/v1/routes/{id}/gpx', [GpxExportController::class, 'routeGpx']);
        $app->post('/api/v1/routes/{id}/split', [RouteSplitController::class, 'split']);
        $app->get('/api/v1/routes/{id}/images/{imageId}', [RouteController::class, 'showImage']);
        $app->delete('/api/v1/routes/{id}/images/{imageId}', [RouteController::class, 'deleteImage']);

        $app->get('/api/v1/tours', [TourController::class, 'index']);
        $app->get('/api/v1/tours/{id}', [TourController::class, 'show']);
        $app->post('/api/v1/tours', [TourController::class, 'create']);
        $app->put('/api/v1/tours/{id}', [TourController::class, 'update']);
        $app->delete('/api/v1/tours/{id}', [TourController::class, 'delete']);
        $app->post('/api/v1/tours/{id}/routes', [TourController::class, 'addRoute']);
        $app->delete('/api/v1/tours/{id}/routes/{routeId}', [TourController::class, 'removeRoute']);
        $app->put('/api/v1/tours/{id}/routes/order', [TourController::class, 'reorderRoutes']);
        $app->put('/api/v1/tours/{id}/publish', [TourController::class, 'publish']);
        $app->post('/api/v1/tours/{id}/copy', [TourController::class, 'copy']);
        $app->post('/api/v1/tours/{id}/images', [TourController::class, 'uploadImage']);
        $app->get('/api/v1/tours/{id}/images/{imageId}', [TourController::class, 'showImage']);
        $app->delete('/api/v1/tours/{id}/images/{imageId}', [TourController::class, 'deleteImage']);
        $app->put('/api/v1/tours/{id}/images/order', [TourController::class, 'reorderImages']);
        $app->get('/api/v1/tours/{id}/document', [TourController::class, 'document']);
        $app->get('/api/v1/tours/{id}/gpx', [GpxExportController::class, 'tourGpx']);
        $app->post('/api/v1/gpx/preview', [GpxImportController::class, 'preview']);
        $app->post('/api/v1/gpx/import', [GpxImportController::class, 'import']);

        $app->get('/api/v1/areas', [AreaController::class, 'index']);
        $app->get('/api/v1/areas/{id}', [AreaController::class, 'show']);
        $app->post('/api/v1/areas', [AreaController::class, 'create']);
        $app->put('/api/v1/areas/{id}', [AreaController::class, 'update']);
        $app->delete('/api/v1/areas/{id}', [AreaController::class, 'delete']);
        $app->post('/api/v1/areas/{id}/images', [AreaController::class, 'uploadImage']);
        $app->get('/api/v1/areas/{id}/images', [AreaController::class, 'listImages']);
        $app->get('/api/v1/areas/{id}/images/{imageId}', [AreaController::class, 'showImage']);
        $app->delete('/api/v1/areas/{id}/images/{imageId}', [AreaController::class, 'deleteImage']);

        $app->get('/api/v1/users', [UserController::class, 'index']);
        $app->get('/api/v1/users/{id}', [UserController::class, 'show']);
        $app->post('/api/v1/users', [UserController::class, 'create']);
        $app->put('/api/v1/users/{id}', [UserController::class, 'update']);
        $app->delete('/api/v1/users/{id}', [UserController::class, 'delete']);
        $app->put('/api/v1/users/{id}/password', [UserController::class, 'setPassword']);
        $app->post('/api/v1/users/{id}/send-reset', [UserController::class, 'sendResetEmail']);

        $app->get('/api/v1/wsi/{code}', [WsiController::class, 'show']);

        $app->get('/api/v1/weather', [WeatherController::class, 'show']);
        $app->get('/api/v1/radar/manifest', [RadarController::class, 'manifest']);
        $app->get('/api/v1/radar/tiles/{frame:[of][0-9-]+}/{z:[0-9]+}/{x:[0-9]+}/{y:[0-9]+}.png', [RadarController::class, 'tile']);
        $app->get('/api/v1/geocode/reverse', [GeocodingController::class, 'reverse']);

        $app->get('/api/v1/watch', [WatchController::class, 'status']);
        $app->post('/api/v1/watch/token', [WatchController::class, 'createToken']);
        $app->get('/api/v1/watch/settings-file', [WatchController::class, 'settingsFile']);
        $app->delete('/api/v1/watch/token', [WatchController::class, 'deleteToken']);
        $app->put('/api/v1/watch/route', [WatchController::class, 'setRoute']);
        $app->delete('/api/v1/watch/route', [WatchController::class, 'clearRoute']);
        $app->put('/api/v1/watch/colors', [WatchController::class, 'setColors']);
        $app->delete('/api/v1/watch/colors', [WatchController::class, 'resetColors']);
        $app->get('/api/v1/watch/device/route', [WatchController::class, 'deviceRoute']);
        $app->get('/api/v1/watch/payload', [WatchController::class, 'payload']);

        $app->put('/api/v1/settings/google-search-requires-login', [SettingsController::class, 'updateGoogleSearchRequiresLogin']);
        $app->put('/api/v1/settings/gpx-export-public', [SettingsController::class, 'updateGpxExportPublic']);
        $app->put('/api/v1/settings/track-distance-filter', [SettingsController::class, 'updateTrackDistanceFilterPresets']);
        $app->put('/api/v1/settings/route-label-font-size-range', [SettingsController::class, 'updateRouteLabelFontSizeRange']);
        $app->get('/api/v1/settings/radar', [RadarSettingsController::class, 'show']);
        $app->put('/api/v1/settings/radar', [RadarSettingsController::class, 'update']);

        $app->get('/', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $c, $translator) {
            ob_start();
            $mapsApiKey = $_ENV['MAPS_API_KEY'] ?? '';
            $mapsId = $_ENV['MAPS_ID'] ?? '';
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $googleSearchRequiresLogin = $c->get(SettingsRepository::class)->googleSearchRequiresLogin();
            $gpxExportPublic = $c->get(SettingsRepository::class)->gpxExportPublic();
            $trackDistanceFilterPresets = $c->get(SettingsRepository::class)->trackDistanceFilterPresets();
            $routeLabelFontSizeRange = $c->get(SettingsRepository::class)->routeLabelFontSizeRange();
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

        $app->get('/about', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator, $c) {
            ob_start();
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            $dataSources = array_map(
                fn (array $source) => DataSourceText::localize($source, $t),
                $c->get(LighthouseImportRepository::class)->usedDataSources()
            );
            require $rootDir . '/templates/about.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        // End-user documentation (docs/user/{locale}/*.md, UserGuideService).
        // ?embed=1 is the in-app panel's iframe: same page without its own
        // header/back link.
        $app->get('/help', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            $guide = new UserGuideService($rootDir . '/docs/user', new GithubFlavoredMarkdownConverter());
            $chapters = $guide->chapters($translator->locale(), $baseUrl);
            $keywordIndex = UserGuideService::keywordIndex($chapters);
            $guideInFallbackLocale = $guide->servedLocale($translator->locale()) !== $translator->locale();
            $embed = ($req->getQueryParams()['embed'] ?? '') === '1';
            ob_start();
            // The page's own texts (title, search, cover, ...) follow the language the guide is
            // actually written in; only the "not available in your language" note is in the UI language.
            $guideTranslator = $guideInFallbackLocale
                ? new Translator($rootDir . '/resources/i18n', $guide->servedLocale($translator->locale()))
                : $translator;
            $tNote = fn (string $key) => $translator->t($key);
            $t = fn (string $key, array $vars = []) => $guideTranslator->t($key, $vars);
            require $rootDir . '/templates/help.php';
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

        // All five /admin/* pages below share one AdminLTE shell
        // (templates/partials/admin-shell-header.php/-footer.php) - each
        // route computes $logLevel/$t/$translateToolEnabled (needed by the
        // shell's sidebar on every page, not just this one) plus its own
        // page-specific data, then requires its own thin template. New
        // admin tools should be added as their own route + template + one
        // entry in admin-shell-header.php's $adminNavItems array, not as a
        // growing pile of unrelated features crammed into one page.
        $app->get('/admin', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            $translateToolEnabled = ($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true';
            require $rootDir . '/templates/admin.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });
        $app->get('/api/v1/admin/dashboard-stats', [AdminController::class, 'dashboardStats']);

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
            $translateToolEnabled = ($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true';
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
            $app->get('/admin/translate', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
                ob_start();
                $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
                $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
                $translateToolEnabled = ($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true';
                require $rootDir . '/templates/translate.php';
                $res->getBody()->write(ob_get_clean());

                return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
            });

            $app->get('/api/v1/translations', [TranslationController::class, 'index']);
            $app->put('/api/v1/translations', [TranslationController::class, 'update']);
        }

        // Admin tool: finds photo files/DB rows left behind by a bug or a
        // failed request (AdminController, ImageReconciliationService) -
        // reachable via the /admin menu above. No opt-in flag needed (see
        // AdminController's own doc comment): unlike the translate tool,
        // this action by construction only ever touches already-orphaned
        // files/rows, so the standard requireAdmin() gate is enough.
        $app->get('/admin/image-cleanup', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            $translateToolEnabled = ($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true';
            require $rootDir . '/templates/admin-image-cleanup.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });
        $app->get('/api/v1/admin/image-orphans', [AdminController::class, 'scanImageOrphans']);
        $app->post('/api/v1/admin/image-orphans/delete', [AdminController::class, 'deleteImageOrphans']);

        // Admin tool: imports lighthouses from the staged entries of an official
        // lighthouse list (LighthouseImportController; the list itself is read
        // into the staging table by bin/import-lighthouse-list.php).
        $app->get('/admin/lighthouse-import', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            $translateToolEnabled = ($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true';
            $mapsApiKey = $_ENV['MAPS_API_KEY'] ?? '';
            require $rootDir . '/templates/admin-lighthouse-import.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });
        $app->get('/api/v1/admin/lighthouse-import', [LighthouseImportController::class, 'index']);
        $app->get('/api/v1/admin/lighthouse-import/ids', [LighthouseImportController::class, 'ids']);
        $app->post('/api/v1/admin/lighthouse-import/osm', [LighthouseImportController::class, 'loadOsm']);
        $app->post('/api/v1/admin/lighthouse-import/nga', [LighthouseImportController::class, 'loadNga']);
        $app->post('/api/v1/admin/lighthouse-import', [LighthouseImportController::class, 'import']);
        $app->get('/api/v1/admin/lighthouse-import/export', [LighthouseImportController::class, 'exportFile']);
        $app->post('/api/v1/admin/lighthouse-import/file', [LighthouseImportController::class, 'importFile']);

        // Admin tool: finds lighthouses that lie close together and merges the
        // ones the admin says are the same (LighthouseDuplicateController).
        $app->get('/admin/lighthouse-duplicates', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            $translateToolEnabled = ($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true';
            require $rootDir . '/templates/admin-lighthouse-duplicates.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });
        $app->get('/api/v1/admin/lighthouse-duplicates', [LighthouseDuplicateController::class, 'index']);
        $app->post('/api/v1/admin/lighthouse-duplicates/merge', [LighthouseDuplicateController::class, 'merge']);

        // Site-wide settings (currently just "Google search requires
        // login") - its own page so future settings have somewhere to go
        // without growing the dashboard into a dumping ground.
        $app->get('/admin/settings', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator, $c) {
            ob_start();
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            $translateToolEnabled = ($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true';
            $googleSearchRequiresLogin = $c->get(SettingsRepository::class)->googleSearchRequiresLogin();
            $gpxExportPublic = $c->get(SettingsRepository::class)->gpxExportPublic();
            $trackDistanceFilterPresets = $c->get(SettingsRepository::class)->trackDistanceFilterPresets();
            $routeLabelFontSizeRange = $c->get(SettingsRepository::class)->routeLabelFontSizeRange();
            require $rootDir . '/templates/admin-settings.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        // Rain radar: the radar job's settings and its last runs (RadarSettingsController).
        $app->get('/admin/radar', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator, $c) {
            ob_start();
            $logLevel = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 3 : 1;
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            $translateToolEnabled = ($_ENV['TRANSLATE_TOOL_ENABLED'] ?? 'false') === 'true';
            $radarSettings = $c->get(SettingsRepository::class)->radarSettings();
            $mapsApiKey = $_ENV['MAPS_API_KEY'] ?? '';
            require $rootDir . '/templates/admin-radar.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        // Gestaltungs-Vergleichsseite (todo.md: "Standards für alle
        // Gestaltungselemente festlegen") - deliberately its OWN template,
        // not one of the AdminLTE-shell pages above (see
        // templates/admin-styleguide.php's own doc comment for why).
        $app->get('/admin/styleguide', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
            require $rootDir . '/templates/admin-styleguide.php';
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
