<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\AccountDeletionController;
use Ytan\Http\Controllers\UserController;
use Ytan\Service\AccountDeletionService;
use Ytan\Service\AuthService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\MailService;
use Ytan\Service\RequestThrottle;

/**
 * Account deletion (todo.md "Kontolöschung"): private content goes, public
 * content moves to the successor admin ("ytan" first, else the smallest id),
 * self-service only via password + mailed token.
 */
final class AccountDeletionTest extends ControllerTestCase
{
    private string $storage;
    private UserRepository $users;
    private AccountDeletionService $service;
    private AccountDeletionController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/ytan-account-deletion-' . uniqid();
        $this->users = new UserRepository($this->pdo);
        $stores = [];
        foreach (['tour', 'route', 'area', 'poi'] as $entity) {
            $stores[$entity] = new ImageStorageService($this->storage . '/' . $entity);
        }
        $this->service = new AccountDeletionService($this->pdo, new TourRepository($this->pdo), $stores);
        $mail = new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
        $this->controller = new AccountDeletionController($this->users, $this->service, $mail, 'http://localhost', new RequestThrottle($this->pdo));
        $this->pdo->exec("INSERT IGNORE INTO poitype (id, name) VALUES (2, 'Anlandestelle')");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->storage . '/*/*/*') ?: [] as $file) {
            unlink($file);
        }
    }

    private function poi(int $userId, int $public): int
    {
        $this->pdo->prepare("INSERT INTO poi (poitype_id, user_id, name, description, public, latitude, longitude) VALUES (2, ?, 'P', '', ?, 54.3, 10.1)")
            ->execute([$userId, $public]);

        return (int) $this->pdo->lastInsertId();
    }

    private function area(int $userId, int $public): int
    {
        $this->pdo->prepare("INSERT INTO area (user_id, name, description, points, public, color, opacity, zindex) VALUES (?, 'A', '', '[]', ?, '#000000', 0.5, 1)")
            ->execute([$userId, $public]);

        return (int) $this->pdo->lastInsertId();
    }

    private function tour(int $userId, int $public, array $routeIds = []): int
    {
        $tours = new TourRepository($this->pdo);
        $id = (int) $tours->create($userId, ['name' => 'T', 'description' => ''])['id'];
        $this->pdo->prepare('UPDATE tour SET public = ? WHERE id = ?')->execute([$public, $id]);
        foreach ($routeIds as $routeId) {
            $tours->addRoute($id, $routeId);
        }

        return $id;
    }

    private function owner(string $table, int $id): ?int
    {
        $stmt = $this->pdo->prepare("SELECT user_id FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function withPassword(int $userId, string $password): void
    {
        $this->pdo->prepare('UPDATE user SET password = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    public function testSuccessorIsTheAdminNamedYtanElseTheSmallestAdminId(): void
    {
        $first = $this->createUser(['is_admin' => 1]);
        $user = $this->createUser();
        $this->assertSame($first, $this->service->successor($user)['id']);

        $ytan = $this->createUser(['is_admin' => 1, 'username' => 'YTAN']);
        $this->assertSame($ytan, $this->service->successor($user)['id']);
        $this->assertSame($first, $this->service->successor($ytan)['id'], 'never the deleted user itself');
    }

    public function testPrivateContentIsDeletedAndPublicContentMovesToTheSuccessor(): void
    {
        $admin = $this->createUser(['is_admin' => 1, 'username' => 'ytan']);
        $user = $this->createUser();
        $other = $this->createUser();

        $privatePoi = $this->poi($user, 0);
        $publicPoi = $this->poi($user, 1);
        $privateArea = $this->area($user, 0);
        $publicArea = $this->area($user, 1);
        $privateRoute = $this->createRoute($user, ['length' => 3000]);
        $publicRoute = $this->createRoute($user, ['public' => 1, 'length' => 2000, 'recorded_at' => '2026-07-01 10:00:00', 'recording_duration_seconds' => 3600]);
        $privateTour = $this->tour($user, 0, [$publicRoute]);
        $publicTour = $this->tour($user, 1, [$publicRoute, $privateRoute]);
        $othersRoute = $this->createRoute($other);

        $result = $this->service->delete($user);

        $this->assertNull($this->users->findById($user));
        foreach ([['poi', $privatePoi], ['area', $privateArea], ['route', $privateRoute], ['tour', $privateTour]] as [$table, $id]) {
            $this->assertNull($this->owner($table, $id), "$table $id deleted");
        }
        foreach ([['poi', $publicPoi], ['area', $publicArea], ['route', $publicRoute], ['tour', $publicTour]] as [$table, $id]) {
            $this->assertSame($admin, $this->owner($table, $id), "$table $id transferred");
        }
        $this->assertSame($other, $this->owner('route', $othersRoute));

        $route = $this->pdo->query("SELECT recorded_at, recording_duration_seconds FROM route WHERE id = $publicRoute")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($route['recorded_at']);
        $this->assertNull($route['recording_duration_seconds']);
        $this->assertSame(2000, (int) $this->pdo->query("SELECT total_length FROM tour WHERE id = $publicTour")->fetchColumn());

        $this->assertSame('ytan', $result['successor']);
        $this->assertSame(['tour' => 1, 'route' => 1, 'area' => 1, 'poi' => 1], $result['deleted']);
        $this->assertSame(['tour' => 1, 'route' => 1, 'area' => 1, 'poi' => 1], $result['transferred']);
    }

    public function testPhotoFilesOfDeletedContentAreRemoved(): void
    {
        $this->createUser(['is_admin' => 1]);
        $user = $this->createUser();
        $privatePoi = $this->poi($user, 0);
        $publicPoi = $this->poi($user, 1);
        foreach ([$privatePoi => 'gone.jpg', $publicPoi => 'kept.jpg'] as $poiId => $file) {
            @mkdir($this->storage . "/poi/$poiId", 0775, true);
            file_put_contents($this->storage . "/poi/$poiId/$file", 'x');
            $this->pdo->prepare("INSERT INTO poi_image (poi_id, filename, sort_order, mime_type, size_bytes, uploaded_at) VALUES (?, ?, 0, 'image/jpeg', 1, NOW())")
                ->execute([$poiId, $file]);
        }

        $this->service->delete($user);

        $this->assertFileDoesNotExist($this->storage . "/poi/$privatePoi/gone.jpg");
        $this->assertFileExists($this->storage . "/poi/$publicPoi/kept.jpg");
    }

    public function testTheLastAdminCannotBeDeleted(): void
    {
        $admin = $this->createUser(['is_admin' => 1]);
        $this->pdo->prepare('UPDATE user SET is_admin = 0 WHERE is_admin = 1 AND id <> ?')->execute([$admin]);

        try {
            $this->service->delete($admin);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('account.last_admin', $e->getErrorCode());
        }
        $this->assertNotNull($this->users->findById($admin));
    }

    public function testRequestNeedsTheRightPassword(): void
    {
        $this->createUser(['is_admin' => 1]);
        $user = $this->createUser();
        $this->withPassword($user, 'correct-horse');

        $this->expectException(UnauthorizedException::class);
        $this->controller->requestDeletion($this->request('POST', '/', $this->authPayload($user), ['password' => 'wrong']), $this->response());
    }

    public function testRequestWithoutDeliverableMailLeavesNothingPending(): void
    {
        $this->createUser(['is_admin' => 1]);
        $user = $this->createUser();
        $this->withPassword($user, 'correct-horse');

        try {
            $this->controller->requestDeletion($this->request('POST', '/', $this->authPayload($user), ['password' => 'correct-horse']), $this->response());
            $this->fail('expected the unreachable mail server to fail');
        } catch (ApiException $e) {
            $this->assertSame('account.mail_failed', $e->getErrorCode());
        }
        $this->assertNull($this->users->findActivePendingToken($user, AccountDeletionController::TOKEN_PURPOSE));
    }

    public function testPendingRequestCanBeWithdrawnAndThenTheLinkNoLongerWorks(): void
    {
        $this->createUser(['is_admin' => 1]);
        $user = $this->createUser();
        $token = $this->users->createToken($user, AccountDeletionController::TOKEN_PURPOSE, AccountDeletionController::TOKEN_TTL_SECONDS);

        $status = $this->decode($this->controller->status($this->request('GET', '/', $this->authPayload($user)), $this->response()));
        $this->assertNotNull($status['data']['pending_expires_at']);
        $this->assertNull($status['data']['blocked']);

        $this->controller->cancelDeletion($this->request('DELETE', '/', $this->authPayload($user)), $this->response());

        $this->expectException(ValidationException::class);
        $this->controller->confirmDeletion($this->request('POST', '/', null, ['token' => $token]), $this->response());
    }

    public function testConfirmingTheMailedTokenDeletesTheAccount(): void
    {
        $this->createUser(['is_admin' => 1]);
        $user = $this->createUser();
        $route = $this->createRoute($user);
        $token = $this->users->createToken($user, AccountDeletionController::TOKEN_PURPOSE, AccountDeletionController::TOKEN_TTL_SECONDS);

        $answer = $this->decode($this->controller->confirmDeletion($this->request('POST', '/', null, ['token' => $token]), $this->response()));

        $this->assertNull($this->users->findById($user));
        $this->assertNull($this->owner('route', $route));
        $this->assertSame(1, $answer['data']['deleted']['route']);
    }

    public function testExpiredOrForeignTokensAreRefused(): void
    {
        $this->createUser(['is_admin' => 1]);
        $user = $this->createUser();
        $resetToken = $this->users->createToken($user, 'reset', 3600);
        $expired = $this->users->createToken($user, AccountDeletionController::TOKEN_PURPOSE, 3600);
        $this->pdo->exec("UPDATE user_token SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE purpose = 'account_delete'");

        foreach ([$resetToken, $expired] as $token) {
            try {
                $this->controller->confirmDeletion($this->request('POST', '/', null, ['token' => $token]), $this->response());
                $this->fail('expected the token to be refused');
            } catch (ValidationException $e) {
                $this->assertSame('auth.link_expired', $e->getErrorCode());
            }
        }
        $this->assertNotNull($this->users->findById($user));
    }

    public function testAdminDeletionUsesTheSameRules(): void
    {
        $admin = $this->createUser(['is_admin' => 1]);
        $user = $this->createUser();
        $publicRoute = $this->createRoute($user, ['public' => 1]);
        $mail = new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
        $controller = new UserController(
            $this->users,
            $mail,
            new AuthService($this->users, 'unit-test-secret-at-least-32-bytes-long', 3600, $mail, 'http://localhost'),
            'http://localhost',
            $this->service,
        );

        $controller->delete($this->request('DELETE', '/', $this->authPayload($admin, ['is_admin' => true])), $this->response(), ['id' => (string) $user]);

        $this->assertNull($this->users->findById($user));
        $this->assertSame($admin, $this->owner('route', $publicRoute));
    }
}
