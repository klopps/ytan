<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Exception\ForbiddenException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\TranslationController;
use Ytan\Service\TranslationRepository;
use Ytan\Service\TranslationUsageScanner;

/**
 * Covers TranslationController's admin-gating and read/write round-trip
 * for the /translate dev tool. Always points TranslationRepository/
 * TranslationUsageScanner at temp fixture directories - never the real
 * resources/i18n/*.json or the real public/js/templates trees, per the
 * project's TranslatorTest precedent for i18n-adjacent tests.
 */
final class TranslationControllerTest extends ControllerTestCase
{
    private string $resourcesDir;
    private string $rootDir;
    private TranslationController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resourcesDir = sys_get_temp_dir() . '/ytan-translation-controller-test-' . uniqid();
        mkdir($this->resourcesDir);
        file_put_contents($this->resourcesDir . '/en.json', json_encode(['greeting' => 'Hello']));
        file_put_contents($this->resourcesDir . '/de.json', json_encode(['greeting' => 'Hallo']));

        $this->rootDir = sys_get_temp_dir() . '/ytan-translation-controller-scan-' . uniqid();
        mkdir($this->rootDir . '/public/js', 0777, true);
        mkdir($this->rootDir . '/templates', 0777, true);
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('greeting');\n");

        $this->controller = new TranslationController(
            new TranslationRepository($this->resourcesDir),
            new TranslationUsageScanner($this->rootDir)
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->resourcesDir);
        $this->rmrf($this->rootDir);
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testIndexReturnsLocalesAndUsageForAnAdmin(): void
    {
        $response = $this->controller->index(
            $this->request('GET', '/api/v1/translations', $this->authPayload(1, ['is_admin' => true])),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $this->assertSame(['greeting' => 'Hello'], $decoded['data']['locales']['en']);
        $this->assertSame(['greeting' => 'Hallo'], $decoded['data']['locales']['de']);
        $this->assertSame([['file' => 'public/js/fake.js', 'line' => 1]], $decoded['data']['usage']['greeting']);
    }

    public function testIndexRejectsANonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller->index(
            $this->request('GET', '/api/v1/translations', $this->authPayload(1, ['is_admin' => false])),
            $this->response()
        );
    }

    public function testIndexRejectsAnUnauthenticatedRequest(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->controller->index(
            $this->request('GET', '/api/v1/translations', null),
            $this->response()
        );
    }

    public function testUpdateSavesMatchingKeySetsAndPersistsToDisk(): void
    {
        $response = $this->controller->update(
            $this->request('PUT', '/api/v1/translations', $this->authPayload(1, ['is_admin' => true]), [
                'en' => ['greeting' => 'Hello!'],
                'de' => ['greeting' => 'Hallo!'],
            ]),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $this->assertTrue($decoded['data']['saved']);

        $repository = new TranslationRepository($this->resourcesDir);
        $this->assertSame(['greeting' => 'Hello!'], $repository->load('en'));
        $this->assertSame(['greeting' => 'Hallo!'], $repository->load('de'));
    }

    public function testUpdateRejectsANonAdminAndWritesNothing(): void
    {
        $originalEn = file_get_contents($this->resourcesDir . '/en.json');

        try {
            $this->controller->update(
                $this->request('PUT', '/api/v1/translations', $this->authPayload(1, ['is_admin' => false]), [
                    'en' => ['greeting' => 'Changed'],
                    'de' => ['greeting' => 'Hallo'],
                ]),
                $this->response()
            );
            $this->fail('Expected ForbiddenException.');
        } catch (ForbiddenException) {
            // expected
        }

        $this->assertSame($originalEn, file_get_contents($this->resourcesDir . '/en.json'));
    }

    public function testUpdateRejectsMismatchedKeySetsWithoutForceAndWritesNothing(): void
    {
        $originalEn = file_get_contents($this->resourcesDir . '/en.json');

        try {
            $this->controller->update(
                $this->request('PUT', '/api/v1/translations', $this->authPayload(1, ['is_admin' => true]), [
                    'en' => ['greeting' => 'Hello', 'extra' => 'Extra'],
                    'de' => ['greeting' => 'Hallo'],
                ]),
                $this->response()
            );
            $this->fail('Expected a mismatch exception.');
        } catch (ValidationException $e) {
            $this->assertSame('translation.key_mismatch', $e->getErrorCode());
        }

        $this->assertSame($originalEn, file_get_contents($this->resourcesDir . '/en.json'));
    }

    public function testUpdateAcceptsMismatchedKeySetsWithForce(): void
    {
        $response = $this->controller->update(
            $this->request('PUT', '/api/v1/translations', $this->authPayload(1, ['is_admin' => true]), [
                'en' => ['greeting' => 'Hello', 'extra' => 'Extra'],
                'de' => ['greeting' => 'Hallo'],
                'force' => true,
            ]),
            $this->response()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateRejectsAMalformedBody(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->update(
            $this->request('PUT', '/api/v1/translations', $this->authPayload(1, ['is_admin' => true]), [
                'en' => 'not-an-array',
                'de' => ['greeting' => 'Hallo'],
            ]),
            $this->response()
        );
    }

    public function testUpdateRejectsAValueContainingAScriptBreakout(): void
    {
        $originalEn = file_get_contents($this->resourcesDir . '/en.json');

        try {
            $this->controller->update(
                $this->request('PUT', '/api/v1/translations', $this->authPayload(1, ['is_admin' => true]), [
                    'en' => ['greeting' => 'Hi</script><script>alert(1)</script>'],
                    'de' => ['greeting' => 'Hallo'],
                ]),
                $this->response()
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($originalEn, file_get_contents($this->resourcesDir . '/en.json'));
    }
}
