<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;

/**
 * The photo endpoints of a POI, an area and a route (upload, list, delete,
 * show) - until 2026-11-15 the same ~80 lines in each of the three
 * controllers. Tours have their own (other rights, sortable photos).
 *
 * The using controller has an ImageStorageService in $this->images and
 * names its repository, its entity label and who may see the photos.
 * Editing is the owner or an admin; seeing follows the entity itself
 * (public, own, admin) - a private entity's photos are never served to
 * anybody else, which is why they go through these endpoints and not a
 * static URL.
 */
trait EntityImageEndpoints
{
    /**
     * The entity's repository: findById(), countImages(), addImage(),
     * getImages(), findImage(), removeImage().
     */
    abstract protected function imageRepository(): object;

    /** For the "maximum of n photos" message: 'POI', 'area', 'route'. */
    abstract protected function imageEntityLabel(): string;

    /** Whether the caller may see this entity (and so its photos). */
    abstract protected function canViewEntity(Request $request, array $entity): bool;

    protected function maxImages(): int
    {
        return 5;
    }

    public function uploadImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $entity = $this->imageRepository()->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $entity['user_id']);

        if ($this->imageRepository()->countImages($id) >= $this->maxImages()) {
            throw new ValidationException('This ' . $this->imageEntityLabel() . ' already has the maximum of ' . $this->maxImages() . ' photos.');
        }

        $file = $request->getUploadedFiles()['image'] ?? null;
        if ($file === null) {
            throw new ValidationException('No image uploaded.');
        }

        $stored = $this->images->store($id, $file);
        $images = $this->imageRepository()->addImage($id, $stored['filename'], $stored['mime_type'], $stored['size_bytes']);

        return $this->json($response, ['data' => $images], 201);
    }

    public function listImages(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $this->assertCanViewImages($request, $this->imageRepository()->findById($id));

        return $this->json($response, ['data' => $this->imageRepository()->getImages($id)]);
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $entity = $this->imageRepository()->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $entity['user_id']);

        $image = $this->imageRepository()->findImage($id, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $this->images->delete($id, $image['filename']);
        $this->imageRepository()->removeImage($id, (int) $args['imageId']);

        return $this->json($response, ['data' => $this->imageRepository()->getImages($id)]);
    }

    public function showImage(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $this->assertCanViewImages($request, $this->imageRepository()->findById($id));

        $image = $this->imageRepository()->findImage($id, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $file = $this->images->read($id, $image['filename']);
        if ($file === null) {
            throw new NotFoundException('Image not found.');
        }

        $response->getBody()->write($file['contents']);

        return $response->withHeader('Content-Type', $image['mime_type']);
    }

    private function assertCanViewImages(Request $request, array $entity): void
    {
        if (!$this->canViewEntity($request, $entity)) {
            throw new ForbiddenException();
        }
    }
}
