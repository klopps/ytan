<?php

declare(strict_types=1);

namespace Ytan\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Server\MiddlewareInterface;
use Ytan\Exception\AppUpdateRequiredException;

/**
 * Backend-authoritative half of "App-Backend-Kompatibilität" (todo.md): a
 * feature can be introduced server-side that a native app build predating
 * it can't support, so an outdated installed APK must not be able to make
 * any change until it's updated - viewing/browsing must keep working
 * regardless (see the frontend counterpart's client-side gating for why).
 *
 * Only the native Capacitor shell ever sends X-App-Version at all (its
 * versionCode, from android/app/build.gradle - see capacitor-bridge.js's
 * getAppVersionCode()); a plain browser tab always runs the latest JS
 * fetched from this same server, so it never has an "outdated frontend"
 * case and never sends this header - such a request is treated as
 * compatible unconditionally, the same fail-open convention capacitor-
 * bridge.js already uses when a native capability can't be determined.
 *
 * MIN_APP_VERSION_CODE (env, default 0 = disabled) is bumped by hand only
 * once a shipped feature actually requires it - most of the time this
 * middleware is a no-op.
 */
final class AppVersionMiddleware implements MiddlewareInterface
{
    private const BLOCKED_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    public function __construct(private readonly int $minAppVersionCode)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('X-App-Version');
        $appVersionCode = ($header !== '') ? (int) $header : null;

        $outdated = $this->minAppVersionCode > 0
            && $appVersionCode !== null
            && $appVersionCode < $this->minAppVersionCode;

        if ($outdated && in_array($request->getMethod(), self::BLOCKED_METHODS, true)) {
            throw new AppUpdateRequiredException();
        }

        $response = $handler->handle($request);

        // Tagged on every response (including GET) so the frontend learns
        // about an incompatible app immediately on the next API call
        // rather than only after a write attempt fails.
        return $outdated ? $response->withHeader('X-App-Update-Required', '1') : $response;
    }
}
