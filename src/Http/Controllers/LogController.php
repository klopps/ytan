<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\Log\LogFileService;

/**
 * Admin tool /admin/logs (templates/admin-logs.php, public/js/admin-logs.js):
 * lists the log files LogFileService knows, shows one (tail + what was
 * appended since an offset, for auto-follow) and downloads one file as it
 * is or several as a ZIP. Admins only.
 */
final class LogController extends BaseController
{
    public function __construct(private readonly LogFileService $logs)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->logs->list()]);
    }

    /** GET /admin/logs/read?id=...[&from=OFFSET][&bytes=N] */
    public function read(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $query = $request->getQueryParams();
        $id = (string) ($query['id'] ?? '');
        if ($this->logs->resolve($id) === null) {
            throw new NotFoundException('Log file not found.');
        }
        $from = isset($query['from']) && $query['from'] !== '' ? max(0, (int) $query['from']) : null;
        $bytes = isset($query['bytes']) ? (int) $query['bytes'] : 262144;

        return $this->json($response, ['data' => $this->logs->read($id, $from, $bytes)]);
    }

    /** GET /admin/logs/download?ids=a,b,... - one file as it is, several as logs.zip. */
    public function download(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $ids = array_values(array_filter(
            explode(',', (string) ($request->getQueryParams()['ids'] ?? '')),
            fn ($id) => $this->logs->resolve($id) !== null
        ));
        if ($ids === []) {
            throw new ValidationException('No log file selected.');
        }

        if (count($ids) === 1) {
            $path = (string) $this->logs->resolve($ids[0]);
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new NotFoundException('Log file not readable.');
            }

            return $this->attachment($response, new Stream($handle), basename($path));
        }

        $zipPath = $this->logs->zip($ids);
        // Copied into a temp stream so the file can be deleted now (Windows can't delete an open file).
        $handle = fopen('php://temp', 'w+b');
        $zip = fopen($zipPath, 'rb');
        if ($handle === false || $zip === false) {
            @unlink($zipPath);
            throw new NotFoundException('ZIP file not readable.');
        }
        stream_copy_to_stream($zip, $handle);
        fclose($zip);
        @unlink($zipPath);
        rewind($handle);

        return $this->attachment($response, new Stream($handle), 'ytan-logs-' . date('Ymd-His') . '.zip');
    }

    private function attachment(Response $response, Stream $body, string $filename): Response
    {
        // application/octet-stream: CompressionMiddleware leaves it alone (no buffering of a big log).
        return $response
            ->withBody($body)
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $filename) . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
