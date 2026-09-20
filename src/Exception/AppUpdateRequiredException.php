<?php

declare(strict_types=1);

namespace Ytan\Exception;

/**
 * Thrown by AppVersionMiddleware for a mutating request (POST/PUT/DELETE)
 * whose X-App-Version header (the native Capacitor app's own installed
 * versionCode, see public/js/capacitor-bridge.js's getAppVersionCode())
 * is below the currently configured MIN_APP_VERSION_CODE - i.e. a feature
 * already live on the server needs native app support an old, still-
 * installed APK build doesn't have (todo.md "App-Backend-Kompatibilität").
 * 426 Upgrade Required is the one HTTP status whose name matches this
 * exactly. Read-only requests are never blocked - see AppVersionMiddleware.
 */
class AppUpdateRequiredException extends ApiException
{
    public function __construct(
        string $message = 'This app version is no longer supported. Please update the app to make changes.'
    ) {
        parent::__construct($message, 426, 'app.update_required');
    }
}
