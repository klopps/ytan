<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\User\UserRepository;
use Ytan\Tests\TestCase;

final class UserRepositoryTest extends TestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
    }

    public function testCreateSetsAllFieldsIncludingTourRights(): void
    {
        $user = $this->users->create([
            'username' => 'newbie',
            'email' => 'newbie@example.test',
            'firstname' => 'New',
            'lastname' => 'Bie',
            'is_admin' => 0,
            'tour_create' => 1,
            'tour_copy' => 1,
        ]);

        $this->assertSame('newbie', $user['username']);
        $this->assertSame(1, (int) $user['tour_create']);
        $this->assertSame(0, (int) $user['tour_publish']);
        $this->assertSame(1, (int) $user['tour_copy']);
    }

    /**
     * Regression test: an earlier version of update() always wrote
     * $data['firstname'] ?? null / $data['lastname'] ?? null etc. directly,
     * so a caller that only sent e.g. {tour_manage: 1} silently blanked out
     * every other field. This happened for real once during manual testing
     * (a user's firstname/lastname were wiped by a partial admin-panel-style
     * request) - update() now merges onto the existing row first.
     */
    public function testUpdateWithAPartialPayloadPreservesUntouchedFields(): void
    {
        $id = $this->createUser([
            'username' => 'keepme',
            'email' => 'keepme@example.test',
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'is_admin' => 0,
        ]);

        // Only tour_manage is sent - everything else must survive untouched.
        $updated = $this->users->update($id, ['tour_manage' => 1]);

        $this->assertSame('keepme', $updated['username']);
        $this->assertSame('keepme@example.test', $updated['email']);
        $this->assertSame('Max', $updated['firstname']);
        $this->assertSame('Mustermann', $updated['lastname']);
        $this->assertSame(1, (int) $updated['tour_manage']);
    }

    public function testUpdateWithAFullPayloadOverwritesEveryGivenField(): void
    {
        $id = $this->createUser(['firstname' => 'Old', 'lastname' => 'Name']);

        $updated = $this->users->update($id, [
            'username' => 'renamed',
            'email' => 'renamed@example.test',
            'firstname' => 'New',
            'lastname' => 'Name',
            'is_admin' => 1,
        ]);

        $this->assertSame('renamed', $updated['username']);
        $this->assertSame('New', $updated['firstname']);
        $this->assertSame(1, (int) $updated['is_admin']);
    }

    public function testFindAllFiltersByASingleRight(): void
    {
        $this->createUser(['username' => 'a', 'tour_manage' => 1]);
        $this->createUser(['username' => 'b', 'tour_manage' => 0]);

        $result = $this->users->findAll(['tour_manage' => 1]);

        $usernames = array_map(fn (array $u) => $u['username'], $result);
        $this->assertContains('a', $usernames);
        $this->assertNotContains('b', $usernames);
    }

    public function testFindAllFiltersCombineWithAnd(): void
    {
        $this->createUser(['username' => 'both', 'tour_manage' => 1, 'tour_publish' => 1]);
        $this->createUser(['username' => 'only-manage', 'tour_manage' => 1, 'tour_publish' => 0]);

        $result = $this->users->findAll(['tour_manage' => 1, 'tour_publish' => 1]);

        $usernames = array_map(fn (array $u) => $u['username'], $result);
        $this->assertSame(['both'], $usernames);
    }

    public function testFindAllLimitAndOffsetPageThroughResultsOrderedByUsername(): void
    {
        $this->createUser(['username' => 'charlie']);
        $this->createUser(['username' => 'alice']);
        $this->createUser(['username' => 'bob']);

        $page1 = array_column($this->users->findAll([], 2, 0), 'username');
        $page2 = array_column($this->users->findAll([], 2, 2), 'username');

        $this->assertSame(['alice', 'bob'], $page1);
        $this->assertSame(['charlie'], $page2);
        $this->assertSame(3, $this->users->countAll());
    }

    public function testCountAllRespectsTheSameFiltersAsFindAll(): void
    {
        $this->createUser(['username' => 'a', 'tour_manage' => 1]);
        $this->createUser(['username' => 'b', 'tour_manage' => 0]);
        $this->createUser(['username' => 'c', 'tour_manage' => 1]);

        $this->assertSame(2, $this->users->countAll(['tour_manage' => 1]));
    }
}
