<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Tour\TourRepository;
use Ytan\Tests\TestCase;

final class TourRepositoryTest extends TestCase
{
    private TourRepository $tours;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tours = new TourRepository($this->pdo);
    }

    public function testCreateAndFindById(): void
    {
        $userId = $this->createUser(['username' => 'owner']);

        $tour = $this->tours->create($userId, ['name' => 'My Tour', 'description' => 'A description']);

        $this->assertSame('My Tour', $tour['name']);
        $this->assertSame('A description', $tour['description']);
        $this->assertSame(0, (int) $tour['public']);
        $this->assertSame('owner', $tour['creator_username']);
        $this->assertSame(0, (int) $tour['total_length']);

        $found = $this->tours->findById((int) $tour['id']);
        $this->assertSame($tour['name'], $found['name']);
    }

    /**
     * update() must only ever touch name/description - publishing has its
     * own right (tour_publish) and must not be settable through the same
     * form a tour_create-only user can reach.
     */
    public function testUpdateNeverTouchesThePublicFlag(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Original', 'description' => '']);
        $this->tours->setPublished((int) $tour['id'], true, $userId);

        $updated = $this->tours->update((int) $tour['id'], ['name' => 'Renamed', 'description' => 'New desc']);

        $this->assertSame('Renamed', $updated['name']);
        $this->assertSame(1, (int) $updated['public'], 'update() must not have unpublished the tour');
    }

    public function testSetPublishedRecordsWhoAndWhen(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Publish Me']);

        $published = $this->tours->setPublished((int) $tour['id'], true, $userId);
        $this->assertSame(1, (int) $published['public']);
        $this->assertSame($userId, (int) $published['published_by']);
        $this->assertNotNull($published['published_at']);

        $unpublished = $this->tours->setPublished((int) $tour['id'], false, null);
        $this->assertSame(0, (int) $unpublished['public']);
    }

    public function testAddRouteRecalculatesTotalLength(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Route Math']);
        $route1 = $this->createRoute($userId, ['length' => 1000]);
        $route2 = $this->createRoute($userId, ['length' => 2500]);

        $this->tours->addRoute((int) $tour['id'], $route1);
        $afterFirst = $this->tours->addRoute((int) $tour['id'], $route2);

        $this->assertSame(3500, (int) $afterFirst['total_length']);
    }

    public function testAddingTheSameRouteTwiceIsANoOp(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'No Dupes']);
        $route = $this->createRoute($userId, ['length' => 1000]);

        $this->tours->addRoute((int) $tour['id'], $route);
        $this->tours->addRoute((int) $tour['id'], $route);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tour_route WHERE tour_id = ?');
        $stmt->execute([$tour['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testRemoveRouteRecalculatesTotalLength(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Remove Me']);
        $route1 = $this->createRoute($userId, ['length' => 1000]);
        $route2 = $this->createRoute($userId, ['length' => 2500]);
        $this->tours->addRoute((int) $tour['id'], $route1);
        $this->tours->addRoute((int) $tour['id'], $route2);

        $after = $this->tours->removeRoute((int) $tour['id'], $route1);

        $this->assertSame(2500, (int) $after['total_length']);
    }

    public function testReorderRoutesPersistsTheGivenOrder(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Order']);
        $routeA = $this->createRoute($userId, ['name' => 'A']);
        $routeB = $this->createRoute($userId, ['name' => 'B']);
        $this->tours->addRoute((int) $tour['id'], $routeA);
        $this->tours->addRoute((int) $tour['id'], $routeB);

        $this->tours->reorderRoutes((int) $tour['id'], [$routeB, $routeA]);

        $stmt = $this->pdo->prepare('SELECT route_id FROM tour_route WHERE tour_id = ? ORDER BY sort_order');
        $stmt->execute([$tour['id']]);
        $this->assertSame([$routeB, $routeA], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * A route's length changing later (RouteController::update()) is a
     * third trigger for recalculation, exercised by that controller - here
     * we just confirm recalculateTotalLength() itself picks up the new
     * value when called again.
     */
    public function testRecalculateTotalLengthPicksUpAChangedRouteLength(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Recalc']);
        $route = $this->createRoute($userId, ['length' => 1000]);
        $this->tours->addRoute((int) $tour['id'], $route);

        $this->pdo->prepare('UPDATE route SET length = ? WHERE id = ?')->execute([9999, $route]);
        $this->tours->recalculateTotalLength((int) $tour['id']);

        $this->assertSame(9999, (int) $this->tours->findById((int) $tour['id'])['total_length']);
    }

    public function testFindByRouteReturnsToursContainingIt(): void
    {
        $userId = $this->createUser();
        $tourA = $this->tours->create($userId, ['name' => 'A']);
        $tourB = $this->tours->create($userId, ['name' => 'B']);
        $tourC = $this->tours->create($userId, ['name' => 'C']);
        $route = $this->createRoute($userId);
        $this->tours->addRoute((int) $tourA['id'], $route);
        $this->tours->addRoute((int) $tourB['id'], $route);

        $found = $this->tours->findByRoute($route);

        $foundIds = array_map(fn (array $t) => (int) $t['id'], $found);
        sort($foundIds);
        $this->assertSame([(int) $tourA['id'], (int) $tourB['id']], $foundIds);
        $this->assertNotContains((int) $tourC['id'], $foundIds);
    }

    public function testSetTagsTrimsDedupesCaseInsensitivelyAndDropsOverlong(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Tagged']);

        $this->tours->setTags((int) $tour['id'], [
            ' Coastal ',
            'coastal', // duplicate, different case - dropped
            '',        // empty - dropped
            str_repeat('x', 51), // over 50 chars - dropped
            'multi-day',
        ]);

        $tags = $this->tours->getTags((int) $tour['id']);
        sort($tags);
        $this->assertSame(['Coastal', 'multi-day'], $tags);
    }

    public function testSetTagsReplacesThePreviousSet(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Retag']);

        $this->tours->setTags((int) $tour['id'], ['one', 'two']);
        $this->tours->setTags((int) $tour['id'], ['three']);

        $this->assertSame(['three'], $this->tours->getTags((int) $tour['id']));
    }

    public function testGetTagsForToursMatchesIndividualGetTags(): void
    {
        $userId = $this->createUser();
        $tourA = $this->tours->create($userId, ['name' => 'A']);
        $tourB = $this->tours->create($userId, ['name' => 'B']);
        $this->tours->setTags((int) $tourA['id'], ['alpha']);
        $this->tours->setTags((int) $tourB['id'], ['beta', 'gamma']);

        $bulk = $this->tours->getTagsForTours([(int) $tourA['id'], (int) $tourB['id']]);

        $this->assertSame(['alpha'], $bulk[(int) $tourA['id']]);
        $this->assertSame(['beta', 'gamma'], $bulk[(int) $tourB['id']]);
    }

    public function testCopyDuplicatesMetadataRoutesAndTagsButForcesPrivate(): void
    {
        $userId = $this->createUser();
        $copierId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Original', 'description' => 'Desc']);
        $this->tours->setPublished((int) $tour['id'], true, $userId);
        $this->tours->setTags((int) $tour['id'], ['coastal']);
        $route = $this->createRoute($userId, ['length' => 1500]);
        $this->tours->addRoute((int) $tour['id'], $route);

        $copy = $this->tours->copy((int) $tour['id'], $copierId);

        $this->assertSame('Original (Copy)', $copy['name']);
        $this->assertSame('Desc', $copy['description']);
        $this->assertSame($copierId, (int) $copy['user_id']);
        $this->assertSame(0, (int) $copy['public'], 'a copy must never inherit the source tour\'s public status');
        $this->assertSame(1500, (int) $copy['total_length']);
        $this->assertSame(['coastal'], $this->tours->getTags((int) $copy['id']));

        $stmt = $this->pdo->prepare('SELECT route_id FROM tour_route WHERE tour_id = ?');
        $stmt->execute([$copy['id']]);
        $this->assertSame([$route], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testCopyNumbersSubsequentDuplicatesButLeavesTheFirstCopyBare(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Original']);

        $first = $this->tours->copy((int) $tour['id'], $userId);
        $second = $this->tours->copy((int) $tour['id'], $userId);
        $third = $this->tours->copy((int) $tour['id'], $userId);

        $this->assertSame('Original (Copy)', $first['name']);
        $this->assertSame('Original (Copy 2)', $second['name']);
        $this->assertSame('Original (Copy 3)', $third['name']);
    }

    public function testCopyNameUniquenessIsScopedToTheNewOwnerNotGlobal(): void
    {
        $owner = $this->createUser();
        $otherOwner = $this->createUser();
        $tour = $this->tours->create($owner, ['name' => 'Original']);

        // otherOwner already has a tour named exactly what owner's first
        // copy would be called - since that name only needs to be unique
        // within its own owner's tours, this must not force a number onto
        // owner's copy.
        $this->tours->create($otherOwner, ['name' => 'Original (Copy)']);

        $copy = $this->tours->copy((int) $tour['id'], $owner);

        $this->assertSame('Original (Copy)', $copy['name']);
    }

    public function testSearchScopeMineOnlyReturnsTheOwnersTours(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $mine = $this->tours->create($owner, ['name' => 'Mine']);
        $this->tours->create($other, ['name' => 'Not Mine']);

        $result = $this->tours->search('mine', $owner);

        $this->assertCount(1, $result);
        $this->assertSame((int) $mine['id'], (int) $result[0]['id']);
    }

    public function testSearchScopeMinePublicIncludesOthersPublicTours(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $this->tours->create($owner, ['name' => 'Mine']);
        $othersTour = $this->tours->create($other, ['name' => 'Theirs']);
        $this->tours->setPublished((int) $othersTour['id'], true, $other);

        $result = $this->tours->search('mine_public', $owner);

        $names = array_map(fn (array $t) => $t['name'], $result);
        sort($names);
        $this->assertSame(['Mine', 'Theirs'], $names);
    }

    public function testSearchScopePublicExcludesPrivateTours(): void
    {
        $owner = $this->createUser();
        $public = $this->tours->create($owner, ['name' => 'Public One']);
        $this->tours->setPublished((int) $public['id'], true, $owner);
        $this->tours->create($owner, ['name' => 'Private One']);

        $result = $this->tours->search('public', null);

        $names = array_map(fn (array $t) => $t['name'], $result);
        $this->assertContains('Public One', $names);
        $this->assertNotContains('Private One', $names);
    }

    public function testSearchFiltersByNameDescriptionOrCreator(): void
    {
        $owner = $this->createUser(['username' => 'findme']);
        $this->tours->create($owner, ['name' => 'Alpha Tour', 'description' => '']);
        $this->tours->create($owner, ['name' => 'Beta Tour', 'description' => 'mentions alpha here']);
        $this->tours->create($owner, ['name' => 'Gamma Tour', 'description' => '']);

        $byName = $this->tours->search('mine', $owner, ['search' => 'Alpha']);
        $this->assertCount(2, $byName); // name match + description match

        $byCreator = $this->tours->search('public', null, ['search' => 'findme']);
        $this->assertCount(0, $byCreator); // none of these are public
    }

    public function testSearchFiltersByLengthRange(): void
    {
        $owner = $this->createUser();
        $short = $this->tours->create($owner, ['name' => 'Short']);
        $long = $this->tours->create($owner, ['name' => 'Long']);
        $route1 = $this->createRoute($owner, ['length' => 5000]);
        $route2 = $this->createRoute($owner, ['length' => 50000]);
        $this->tours->addRoute((int) $short['id'], $route1);
        $this->tours->addRoute((int) $long['id'], $route2);

        $result = $this->tours->search('mine', $owner, ['min_length' => 10000]);

        $this->assertCount(1, $result);
        $this->assertSame('Long', $result[0]['name']);
    }

    public function testSearchLimitAndOffsetPageThroughResultsOrderedByName(): void
    {
        $owner = $this->createUser();
        $this->tours->create($owner, ['name' => 'Charlie']);
        $this->tours->create($owner, ['name' => 'Alpha']);
        $this->tours->create($owner, ['name' => 'Bravo']);

        $page1 = array_column($this->tours->search('mine', $owner, [], 2, 0), 'name');
        $page2 = array_column($this->tours->search('mine', $owner, [], 2, 2), 'name');

        $this->assertSame(['Alpha', 'Bravo'], $page1);
        $this->assertSame(['Charlie'], $page2);
    }

    public function testCountSearchMatchesSearchRegardlessOfLimit(): void
    {
        $owner = $this->createUser();
        $this->tours->create($owner, ['name' => 'Alpha Tour']);
        $this->tours->create($owner, ['name' => 'Beta Tour']);
        $this->tours->create($owner, ['name' => 'Gamma Tour']);

        $this->assertSame(3, $this->tours->countSearch('mine', $owner));
        $this->assertCount(2, $this->tours->search('mine', $owner, [], 2, 0));

        $this->assertSame(1, $this->tours->countSearch('mine', $owner, ['search' => 'Alpha']));
    }

    /**
     * The image-count cap (TourController::MAX_IMAGES_PER_TOUR, mirrored
     * client-side by TOUR_PHOTO_MAX_COUNT in config.js) is enforced only in
     * the controller, on new uploads - TourRepository::addImage() itself has
     * no cap, and update() never touches tour_image at all. A tour that
     * already has more images than the currently configured maximum (e.g.
     * after that constant was lowered) must therefore never lose any of them
     * through an unrelated edit/save - this test makes that guarantee
     * explicit and regression-proof, since it previously only held "by
     * architecture" rather than by an actual assertion anywhere.
     */
    public function testUpdateNeverDropsImagesEvenWhenOverTheConfiguredMax(): void
    {
        $userId = $this->createUser();
        $tour = $this->tours->create($userId, ['name' => 'Over The Limit']);
        $tourId = (int) $tour['id'];

        // One more than TourController::MAX_IMAGES_PER_TOUR (9) - simulates
        // a tour that already had more images before the cap was lowered, or
        // was seeded some other way that bypassed the controller's check.
        for ($i = 0; $i < 10; $i++) {
            $this->tours->addImage($tourId, "photo-$i.jpg", 'image/jpeg', 12345);
        }
        $this->assertSame(10, $this->tours->countImages($tourId));

        $this->tours->update($tourId, ['name' => 'Renamed', 'description' => 'New desc']);

        $this->assertSame(10, $this->tours->countImages($tourId), 'update() must not have dropped any images');
        $this->assertCount(10, $this->tours->getImages($tourId));
    }
}
