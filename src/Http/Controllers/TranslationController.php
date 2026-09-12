<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Exception\ValidationException;
use Ytan\Service\TranslationRepository;
use Ytan\Service\TranslationUsageScanner;

/**
 * Backend for the /translate admin tool (see templates/translate.php,
 * public/js/translate.js) - lets an admin edit resources/i18n/{en,de}.json
 * through a browser instead of hand-editing the JSON files. Admin-only,
 * same requireAdmin() gate every other admin endpoint uses; additionally
 * only registered at all when TRANSLATE_TOOL_ENABLED=true (App.php) since
 * this endpoint's blast radius is larger than a typical admin action -
 * a bad write here lands inside a <script> tag on every page load for
 * every visitor, not just wherever one field happens to render.
 */
final class TranslationController extends BaseController
{
    public function __construct(
        private readonly TranslationRepository $translations,
        private readonly TranslationUsageScanner $scanner,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $en = $this->translations->load('en');
        $de = $this->translations->load('de');
        $usageForFoundKeys = $this->scanner->scan();

        // Every key from either locale gets a usage entry, even an empty
        // one - "used nowhere" is a real, useful state for a translator to
        // see (a candidate for deletion), not something to just omit.
        $allKeys = array_unique(array_merge(array_keys($en), array_keys($de)));
        $usage = [];
        foreach ($allKeys as $key) {
            $usage[$key] = $usageForFoundKeys[$key] ?? [];
        }

        return $this->json($response, ['data' => [
            'locales' => ['en' => $en, 'de' => $de],
            'usage' => $usage,
        ]]);
    }

    public function update(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);

        $en = $body['en'] ?? null;
        $de = $body['de'] ?? null;
        if (!is_array($en) || !is_array($de)) {
            throw new ValidationException('Both "en" and "de" translation maps are required.');
        }
        foreach ([$en, $de] as $map) {
            foreach ($map as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new ValidationException('Translation maps must be string key/value pairs.');
                }
            }
        }

        $force = ($body['force'] ?? false) === true;
        $this->translations->save($en, $de, $force);

        return $this->json($response, ['data' => ['saved' => true]]);
    }
}
