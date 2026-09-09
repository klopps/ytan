<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Http\Controllers\TourController;
use Ytan\Service\TourImageService;

/**
 * Covers TourController's private assertCanManageTour()/assertCanPublishTour()
 * rules indirectly through the public endpoints that use them - this is
 * exactly the "admin/tour_manage OR (owner AND the specific right)" logic
 * from Touren.md's rights section, so it's worth pinning down precisely.
 */
final class TourControllerPermissionsTest extends ControllerTestCase
{
    private TourController $controller;
    private TourRepository $tours;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tours = new TourRepository($this->pdo);
        $this->controller = new TourController($this->tours, new TourImageService(sys_get_temp_dir() . '/ytan-test-images'));
    }

    public function testCreateSucceedsWithTourCreateRight(): void
    {
        $userId = $this->createUser(['tour_create' => 1]);

        $response = $this->controller->create(
            $this->request('POST', '/tours', $this->authPayload($userId, ['tour_create' => true]), ['name' => 'New Tour']),
            $this->response()
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testCreateIsForbiddenWithoutTourCreateRight(): void
    {
        $userId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller->create(
            $this->request('POST', '/tours', $this->authPayload($userId), ['name' => 'New Tour']),
            $this->response()
        );
    }

    public function testCreateSucceedsForAdminWithoutTourCreateRight(): void
    {
        $userId = $this->createUser(['is_admin' => 1]);

        $response = $this->controller->create(
            $this->request('POST', '/tours', $this->authPayload($userId, ['is_admin' => true]), ['name' => 'New Tour']),
            $this->response()
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testOwnerWithTourCreateCanUpdateTheirOwnTour(): void
    {
        $ownerId = $this->createUser(['tour_create' => 1]);
        $tour = $this->tours->create($ownerId, ['name' => 'Mine']);

        $response = $this->controller->update(
            $this->request('PUT', '/tours/' . $tour['id'], $this->authPayload($ownerId, ['tour_create' => true]), ['name' => 'Renamed']),
            $this->response(),
            ['id' => (string) $tour['id']]
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Central rule from Touren.md: owning a tour is not enough by itself -
     * the owner must still currently hold tour_create to edit/delete it.
     */
    public function testOwnerWithoutTourCreateCannotUpdateTheirOwnTour(): void
    {
        $ownerId = $this->createUser(); // no tour_create
        $tour = $this->tours->create($ownerId, ['name' => 'Mine']);

        $this->expectException(ForbiddenException::class);
        $this->controller->update(
            $this->request('PUT', '/tours/' . $tour['id'], $this->authPayload($ownerId), ['name' => 'Renamed']),
            $this->response(),
            ['id' => (string) $tour['id']]
        );
    }

    public function testNonOwnerWithTourManageCanUpdateAnyTour(): void
    {
        $ownerId = $this->createUser();
        $managerId = $this->createUser(['tour_manage' => 1]);
        $tour = $this->tours->create($ownerId, ['name' => 'Someone Else\'s']);

        $response = $this->controller->update(
            $this->request('PUT', '/tours/' . $tour['id'], $this->authPayload($managerId, ['tour_manage' => true]), ['name' => 'Renamed by manager']),
            $this->response(),
            ['id' => (string) $tour['id']]
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNonOwnerWithoutTourManageCannotUpdateSomeoneElsesTour(): void
    {
        $ownerId = $this->createUser();
        $strangerId = $this->createUser(['tour_create' => 1]); // has the right, but for their OWN tours only
        $tour = $this->tours->create($ownerId, ['name' => 'Someone Else\'s']);

        $this->expectException(ForbiddenException::class);
        $this->controller->update(
            $this->request('PUT', '/tours/' . $tour['id'], $this->authPayload($strangerId, ['tour_create' => true]), ['name' => 'Hijacked']),
            $this->response(),
            ['id' => (string) $tour['id']]
        );
    }

    public function testOwnerWithTourPublishCanPublishTheirOwnTour(): void
    {
        $ownerId = $this->createUser(['tour_publish' => 1]);
        $tour = $this->tours->create($ownerId, ['name' => 'Publish Me']);

        $response = $this->controller->publish(
            $this->request('PUT', '/tours/' . $tour['id'] . '/publish', $this->authPayload($ownerId, ['tour_publish' => true]), ['public' => true]),
            $this->response(),
            ['id' => (string) $tour['id']]
        );

        $result = $this->decode($response);
        $this->assertSame(1, (int) $result['data']['public']);
    }

    /**
     * tour_create lets you edit a tour's metadata, but publishing is a
     * separate right on purpose (Touren.md) - a tour_create-only owner
     * must not be able to publish through this endpoint either.
     */
    public function testOwnerWithOnlyTourCreateCannotPublish(): void
    {
        $ownerId = $this->createUser(['tour_create' => 1]);
        $tour = $this->tours->create($ownerId, ['name' => 'Not Publishable']);

        $this->expectException(ForbiddenException::class);
        $this->controller->publish(
            $this->request('PUT', '/tours/' . $tour['id'] . '/publish', $this->authPayload($ownerId, ['tour_create' => true]), ['public' => true]),
            $this->response(),
            ['id' => (string) $tour['id']]
        );
    }

    public function testTourManageCanPublishAnyonesTour(): void
    {
        $ownerId = $this->createUser();
        $managerId = $this->createUser(['tour_manage' => 1]);
        $tour = $this->tours->create($ownerId, ['name' => 'Managed Publish']);

        $response = $this->controller->publish(
            $this->request('PUT', '/tours/' . $tour['id'] . '/publish', $this->authPayload($managerId, ['tour_manage' => true]), ['public' => true]),
            $this->response(),
            ['id' => (string) $tour['id']]
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCopyRequiresTourCopyRight(): void
    {
        $ownerId = $this->createUser();
        $tour = $this->tours->create($ownerId, ['name' => 'Copyable']);
        $this->tours->setPublished((int) $tour['id'], true, $ownerId);
        $copierId = $this->createUser(); // no tour_copy

        $this->expectException(ForbiddenException::class);
        $this->controller->copy(
            $this->request('POST', '/tours/' . $tour['id'] . '/copy', $this->authPayload($copierId)),
            $this->response(),
            ['id' => (string) $tour['id']]
        );
    }

    public function testCopyOfAPrivateTourIsForbiddenWithoutViewAccess(): void
    {
        $ownerId = $this->createUser();
        $tour = $this->tours->create($ownerId, ['name' => 'Private']); // never published
        $copierId = $this->createUser(['tour_copy' => 1]);

        $this->expectException(ForbiddenException::class);
        $this->controller->copy(
            $this->request('POST', '/tours/' . $tour['id'] . '/copy', $this->authPayload($copierId, ['tour_copy' => true])),
            $this->response(),
            ['id' => (string) $tour['id']]
        );
    }

    public function testCopyOfAPublicTourSucceedsWithTourCopyRight(): void
    {
        $ownerId = $this->createUser();
        $tour = $this->tours->create($ownerId, ['name' => 'Public Original']);
        $this->tours->setPublished((int) $tour['id'], true, $ownerId);
        $copierId = $this->createUser(['tour_copy' => 1]);

        $response = $this->controller->copy(
            $this->request('POST', '/tours/' . $tour['id'] . '/copy', $this->authPayload($copierId, ['tour_copy' => true])),
            $this->response(),
            ['id' => (string) $tour['id']]
        );

        $result = $this->decode($response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($copierId, (int) $result['data']['user_id']);
        $this->assertSame(0, (int) $result['data']['public']);
    }
}
