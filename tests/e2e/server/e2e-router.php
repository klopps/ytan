<?php

declare(strict_types=1);

/**
 * php -S router wrapper used ONLY by the Playwright e2e suite
 * (playwright.config.js's webServer) - never referenced by production
 * deploys or by the plain `php -S localhost:8000 -t public public/index.php`
 * command from CLAUDE.md.
 *
 * Forces the app onto its own dedicated ytan_e2e database (see
 * seed/reset-e2e-db.php, which creates it on first run by cloning
 * PHPUnit's ytan_test structure) instead of .env's real dev database,
 * so everything Playwright creates through the UI (POIs, routes, areas,
 * tours, the seeded e2e test user) never touches real dev data. This is
 * deliberately a SEPARATE database from ytan_test itself, not shared
 * with it - leftover e2e rows there previously broke PHPUnit tests that
 * assume a pristine table (colliding auto-increment ids, skewed
 * count()-based assertions) - see reset-e2e-db.php's doc comment.
 *
 * This can't be done via a plain OS environment variable override
 * (`DB_DATABASE=ytan_test php -S ...`) - verified empirically: App.php's
 * Dotenv::createImmutable()->load() populates $_ENV from .env's file
 * value whenever $_ENV itself doesn't already hold the key, which on
 * this box is true even when the OS env var is set (getenv() sees it,
 * $_ENV doesn't - a PHP variables_order quirk). Setting $_ENV directly
 * here, before public/index.php (and therefore Dotenv) ever runs, means
 * Dotenv's own "don't overwrite an existing value" check finds the key
 * already present and leaves it alone.
 */
$_ENV['DB_DATABASE'] = 'ytan_e2e';
$_SERVER['DB_DATABASE'] = 'ytan_e2e';
putenv('DB_DATABASE=ytan_e2e');

// return, not just require: public/index.php's own cli-server router
// logic (PHP_SAPI === 'cli-server' passthrough for static files) does
// `return false;` for requests that map to a real file - that needs to
// propagate all the way out of THIS script too, or the built-in server
// never falls back to serving js/css/images directly from public/.
return require __DIR__ . '/../../../public/index.php';
