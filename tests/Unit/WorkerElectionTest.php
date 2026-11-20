<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ValidationException;
use Ytan\Service\JobPlacement;

/**
 * Who works on a job (JobPlacement::activeWorker): the shared test vectors
 * (tests/testdata/worker-election.json, the same file tools/tests/test_worker.py runs
 * against ytan_jobs.active_worker()), plus the registry and the lists.
 */
final class WorkerElectionTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, int}> */
    public static function vectors(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../testdata/worker-election.json'), true);
        $cases = [];
        foreach ($data['cases'] as $case) {
            $cases[$case['name']] = [$case, (int) $data['now']];
        }

        return $cases;
    }

    /**
     * @dataProvider vectors
     * @param array<string, mixed> $case
     */
    public function testElectionVectors(array $case, int $now): void
    {
        $heartbeats = [];
        foreach ($case['workers'] as $id => $w) {
            $heartbeats[$id] = [
                'tick' => gmdate('Y-m-d\TH:i:s\Z', $now - (int) $w['age']),
                'capabilities' => $w['capabilities'] ?? [],
                'running' => isset($w['running']) ? ['job' => $w['running']] : null,
            ];
        }
        $serverTick = $case['server_age'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', $now - (int) $case['server_age']);

        $this->assertSame($case['expected'], JobPlacement::activeWorker($case['job'], $case['list'], $heartbeats, $serverTick, $now));
    }

    public function testNamesAreSlugsAndServerIsReserved(): void
    {
        foreach (['homeassistant', 'ytanworker', 'a', 'pi-4', '0x'] as $ok) {
            $this->assertTrue(JobPlacement::isValidId($ok), $ok);
        }
        foreach (['', 'server', 'Home', '-a', 'a b', 'a_b', str_repeat('a', 33), 'ä', 5, null] as $bad) {
            $this->assertFalse(JobPlacement::isValidId($bad), var_export($bad, true));
        }
    }

    public function testTheRegistryKeepsUniqueNamesAndRejectsBadOnes(): void
    {
        $registry = JobPlacement::normalizeRegistry([
            ['id' => 'pi', 'label' => '  Home Assistant ', 'note' => str_repeat('x', 300)],
            ['id' => 'nuc'],
        ]);
        $this->assertSame(['pi', 'nuc'], array_column($registry, 'id'));
        $this->assertSame('Home Assistant', $registry[0]['label']);
        $this->assertSame(200, mb_strlen($registry[0]['note']));
        $this->assertSame('', $registry[1]['label']);

        $this->expectCode('worker.name_taken', fn () => JobPlacement::normalizeRegistry([['id' => 'pi'], ['id' => 'pi']]));
    }

    public function testRegistryErrors(): void
    {
        $this->expectCode('worker.invalid_name', fn () => JobPlacement::normalizeRegistry([['id' => 'server']]));
        $this->expectCode('worker.invalid_name', fn () => JobPlacement::normalizeRegistry([['id' => 'Pi 4']]));
        $this->expectCode('worker.invalid_name', fn () => JobPlacement::normalizeRegistry(['pi']));
        $this->expectCode('worker.registry_invalid', fn () => JobPlacement::normalizeRegistry('pi'));
        $this->expectCode('worker.too_many', fn () => JobPlacement::normalizeRegistry(array_map(fn ($i) => ['id' => 'w' . $i], range(1, JobPlacement::MAX_WORKERS + 1))));
        // what is stored is read leniently: a broken entry is skipped, not fatal
        $this->assertSame(['ok'], array_column(JobPlacement::normalizeRegistry([['id' => 'ok'], ['id' => 'ok'], ['id' => 'BAD'], 'x'], false), 'id'));
    }

    public function testListsAcceptOnlyRegisteredWorkersAndTheServer(): void
    {
        $lists = JobPlacement::normalizeLists(['wind' => [['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => false]]], ['pi']);
        $this->assertSame([['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => false]], $lists['wind']);
        $this->assertSame([['id' => 'server', 'enabled' => true]], $lists['radar']); // no list = the server

        $this->expectCode('worker.unknown', fn () => JobPlacement::normalizeLists(['wind' => [['id' => 'ghost']]], ['pi']));
        $this->expectCode('worker.list_invalid', fn () => JobPlacement::normalizeLists(['wind' => [['id' => 'pi'], ['id' => 'pi']]], ['pi']));
        $this->expectCode('worker.list_invalid', fn () => JobPlacement::normalizeLists(['wind' => 'pi'], ['pi']));
        $this->expectCode('worker.list_invalid', fn () => JobPlacement::normalizeLists('x', ['pi']));
    }

    public function testStoredListsAreReadLeniently(): void
    {
        $lists = JobPlacement::normalizeLists(['wind' => [['id' => 'ghost'], ['id' => 'pi', 'enabled' => 'yes'], ['id' => 'pi']], 'bogus' => []], ['pi'], false);
        $this->assertSame([['id' => 'pi', 'enabled' => false]], $lists['wind']); // "yes" is not true; the duplicate and the ghost are dropped
        $this->assertSame(['radar', 'wind'], array_keys($lists));
        // a list with nothing left is the server again
        $this->assertSame([['id' => 'server', 'enabled' => true]], JobPlacement::normalizeLists(['wind' => [['id' => 'ghost']]], [], false)['wind']);
    }

    public function testTheOldExternalJobsKeyFollowsTheFirstEnabledEntry(): void
    {
        $lists = JobPlacement::normalizeLists([
            'radar' => [['id' => 'pi', 'enabled' => false], ['id' => 'server', 'enabled' => true]],
            'wind' => [['id' => 'server', 'enabled' => false], ['id' => 'pi', 'enabled' => true]],
        ], ['pi']);
        $this->assertSame(['wind'], JobPlacement::externalFromLists($lists));
        $this->assertSame([], JobPlacement::externalFromLists(JobPlacement::defaultLists()));
    }

    public function testTheSimpleSwitchPutsAWorkerOrTheServerFirst(): void
    {
        $lists = JobPlacement::withExternal(JobPlacement::defaultLists(), 'wind', true, ['pi', 'nuc']);
        $this->assertSame([['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => false]], $lists['wind']);

        $lists['wind'] = [['id' => 'server', 'enabled' => true], ['id' => 'nuc', 'enabled' => false]];
        $this->assertSame('nuc', JobPlacement::withExternal($lists, 'wind', true, ['pi', 'nuc'])['wind'][0]['id']); // the worker already in the list
        $back = JobPlacement::withExternal($lists, 'wind', false, ['pi', 'nuc']);
        $this->assertSame([['id' => 'server', 'enabled' => true], ['id' => 'nuc', 'enabled' => false]], $back['wind']);

        $this->expectCode('worker.none_registered', fn () => JobPlacement::withExternal(JobPlacement::defaultLists(), 'wind', true, []));
    }

    private function expectCode(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('expected ' . $code);
        } catch (ValidationException $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }
}
