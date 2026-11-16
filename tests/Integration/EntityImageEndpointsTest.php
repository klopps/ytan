<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Slim\Psr7\UploadedFile;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\AreaController;
use Ytan\Http\Controllers\PoiController;
use Ytan\Http\Controllers\RouteController;
use Ytan\Service\CaptchaService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\MailService;
use Ytan\Service\TourNotificationService;
use Ytan\Tests\RemovesDirectories;

/**
 * The photo endpoints POIs, areas and routes share (EntityImageEndpoints):
 * owner uploads, lists, sees and deletes; strangers see a public entity's
 * photos but not a private one's and can't upload; the per-entity maximum.
 */
final class EntityImageEndpointsTest extends ControllerTestCase
{
    use RemovesDirectories;

    private string $dir;
    private ImageStorageService $images;
    private int $owner;
    private int $other;
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ytan-entity-images-' . uniqid();
        $this->images = new ImageStorageService($this->dir);
        $this->owner = $this->createUser();
        $this->other = $this->createUser();
        // poitype is a lookup table the structure-only test DB has no rows in
        $this->pdo->exec("INSERT IGNORE INTO poitype (id, name) VALUES (2, 'Test Type')");
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return ['poi' => ['poi'], 'area' => ['area'], 'route' => ['route']];
    }

    /** @return array{0: object, 1: int} controller and the id of a new entity of the kind */
    private function make(string $kind, bool $public): array
    {
        return match ($kind) {
            'poi' => [
                new PoiController(new PoiRepository($this->pdo), $this->images),
                (int) (new PoiRepository($this->pdo))->create($this->owner, [
                    'poitype_id' => 2, 'name' => 'P', 'latitude' => 54.3, 'longitude' => 10.1, 'public' => $public ? 1 : 0,
                ])['id'],
            ],
            'area' => [
                new AreaController(new AreaRepository($this->pdo), $this->images),
                (int) (new AreaRepository($this->pdo))->create($this->owner, ['name' => 'A', 'public' => $public ? 1 : 0])['id'],
            ],
            'route' => [
                new RouteController(
                    new RouteRepository($this->pdo),
                    new TourRepository($this->pdo),
                    new CaptchaService('unit-test-secret-at-least-32-bytes-long'),
                    new TourNotificationService(new UserRepository($this->pdo), new MailService('', 587, '', '', 'noreply@example.test', 'tls', 'YTAN'), 'http://localhost'),
                    $this->images
                ),
                $this->createRoute($this->owner, ['public' => $public ? 1 : 0]),
            ],
        };
    }

    private function upload(object $controller, int $id, int $userId)
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        $tmp = tempnam(sys_get_temp_dir(), 'ytan-upload');
        file_put_contents($tmp, $png);
        $this->tmpFiles[] = $tmp;
        $request = $this->request('POST', "/x/$id/images", $this->authPayload($userId))
            ->withUploadedFiles(['image' => new UploadedFile($tmp, 'p.png', 'image/png', strlen($png), UPLOAD_ERR_OK)]);

        return $this->decode($controller->uploadImage($request, $this->response(), ['id' => (string) $id]));
    }

    /** @dataProvider kinds */
    public function testOwnerUploadsListsShowsAndDeletes(string $kind): void
    {
        [$controller, $id] = $this->make($kind, false);

        $uploaded = $this->upload($controller, $id, $this->owner);
        $this->assertSame(201, $uploaded['status']);
        $this->assertCount(1, $uploaded['data']);
        $imageId = (int) $uploaded['data'][0]['id'];

        $listed = $this->decode($controller->listImages($this->request('GET', '/x', $this->authPayload($this->owner)), $this->response(), ['id' => (string) $id]));
        $this->assertCount(1, $listed['data']);

        $shown = $controller->showImage($this->request('GET', '/x', $this->authPayload($this->owner)), $this->response(), ['id' => (string) $id, 'imageId' => (string) $imageId]);
        $this->assertSame('image/png', $shown->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", (string) $shown->getBody());

        $deleted = $this->decode($controller->deleteImage($this->request('DELETE', '/x', $this->authPayload($this->owner)), $this->response(), ['id' => (string) $id, 'imageId' => (string) $imageId]));
        $this->assertSame([], $deleted['data']);

        $this->expectException(NotFoundException::class);
        $controller->showImage($this->request('GET', '/x', $this->authPayload($this->owner)), $this->response(), ['id' => (string) $id, 'imageId' => (string) $imageId]);
    }

    /** @dataProvider kinds */
    public function testStrangersSeePublicPhotosOnly(string $kind): void
    {
        [$controller, $publicId] = $this->make($kind, true);
        $this->upload($controller, $publicId, $this->owner);
        $listed = $this->decode($controller->listImages($this->request('GET', '/x'), $this->response(), ['id' => (string) $publicId]));
        $this->assertCount(1, $listed['data'], 'anonymous visitors see a public entity\'s photos');

        [, $privateId] = $this->make($kind, false);
        $this->expectException(ForbiddenException::class);
        $controller->listImages($this->request('GET', '/x', $this->authPayload($this->other)), $this->response(), ['id' => (string) $privateId]);
    }

    /** @dataProvider kinds */
    public function testOnlyTheOwnerMayUpload(string $kind): void
    {
        [$controller, $id] = $this->make($kind, true);

        $this->expectException(ForbiddenException::class);
        $this->upload($controller, $id, $this->other);
    }

    /** @dataProvider kinds */
    public function testFivePhotosAtMost(string $kind): void
    {
        [$controller, $id] = $this->make($kind, false);
        for ($i = 0; $i < 5; $i++) {
            $this->upload($controller, $id, $this->owner);
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already has the maximum of 5 photos');
        $this->upload($controller, $id, $this->owner);
    }
}
