<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Service\ImageReconciliationService;

/**
 * Backend for the /admin/image-cleanup tool (templates/admin-image-
 * cleanup.php, public/js/admin-image-cleanup.js). Plain requireAdmin()
 * gate, same as any other admin action (deleting a user/tour, etc.) - no
 * extra opt-in flag like the /admin/translate tool, since this action by
 * construction only ever touches already-orphaned files/rows (see
 * ImageReconciliationService's own doc comment), never live data.
 */
final class AdminController extends BaseController
{
    public function __construct(private readonly ImageReconciliationService $images)
    {
    }

    public function scanImageOrphans(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->images->scan()]);
    }

    public function deleteImageOrphans(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $items = $this->jsonBody($request)['items'] ?? [];
        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            $entityId = (int) ($item['entity_id'] ?? 0);

            if (($item['kind'] ?? '') === 'file') {
                $this->images->deleteOrphanedFile($type, $entityId, (string) ($item['filename'] ?? ''));
            } elseif (($item['kind'] ?? '') === 'row') {
                $this->images->deleteDanglingRow($type, $entityId, (int) ($item['image_id'] ?? 0));
            }
        }

        return $this->json($response, ['data' => $this->images->scan()]);
    }
}
