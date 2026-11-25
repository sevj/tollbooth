<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;
use Tollbooth\Tests\Fixtures\TestKernel;

final class MonitoringTest extends FunctionalTestCase
{
    private const int NOW = 1795615325;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        (new Filesystem())->remove(TestKernel::monitoringDatabase());
        (new Filesystem())->mkdir(\dirname(TestKernel::monitoringDatabase()));
        $this->client = self::createClient(['environment' => 'monitoring']);
        $this->storage()->setup();
    }

    public function testEveryCallIsRecordedWithItsSteps(): void
    {
        $this->client->request('GET', '/api/things/7?note=secret-token');

        $calls = $this->storage()->recent(new CallQuery());
        self::assertCount(1, $calls);
        $call = $calls[0];
        self::assertSame(['tollbooth_get_thing', 'default', 'GET', '/api/things/7', 200], [$call->endpoint, $call->group, $call->method, $call->path, $call->status]);
        self::assertSame(['ip:127.0.0.1', 'anonymous', '127.0.0.1'], [$call->consumerId, $call->authMethod, $call->ip]);
        self::assertSame(self::NOW * 1000, $call->occurredAt);
        self::assertGreaterThan(0, $call->duration);
        self::assertSame(\strlen((string) $this->client->getResponse()->getContent()), $call->responseSize);
        self::assertSame(['auth', 'hydrate', 'handler', 'serialize'], array_keys($call->steps));
        self::assertNull($call->error);
    }

    public function testErrorsAreDescribedWithoutLeakingServerMessages(): void
    {
        $this->client->jsonRequest('POST', '/api/things', ['name' => '', 'quantity' => 1]);
        $this->client->request('GET', '/api/things/404');
        $this->client->request('GET', '/api/explode');

        $errors = array_map(
            static fn (CallRecord $call): array => [$call->status, $call->error],
            $this->storage()->recent(new CallQuery(limit: 10)),
        );
        usort($errors, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        self::assertSame([
            [404, 'Thing 404 does not exist.'],
            [422, 'name: This value should not be blank.'],
            [500, 'RuntimeException'],
        ], $errors);
    }

    public function testRecentCallsAreFiltered(): void
    {
        $this->client->request('GET', '/api/things/1');
        $this->client->request('GET', '/api/things/404');
        $this->client->request('DELETE', '/api/things/1');

        self::assertCount(1, $this->storage()->recent(new CallQuery(statusClass: 4)));
        self::assertCount(1, $this->storage()->recent(new CallQuery(endpoint: 'tollbooth_delete_thing')));
        self::assertCount(3, $this->storage()->recent(new CallQuery(consumerId: 'ip:127.0.0.1')));
        self::assertCount(0, $this->storage()->recent(new CallQuery(consumerId: 'acme')));
    }

    public function testFinishedMinutesAreRolledUpAndTheCurrentOnesReadRaw(): void
    {
        $storage = $this->storage();
        $minute = intdiv(self::NOW, 60) * 60;
        $this->record($storage, $minute - 180 + 10, 'list', 200, 20.0);
        $this->record($storage, $minute - 180 + 40, 'list', 429, 2.0);
        $this->record($storage, $minute - 120 + 5, 'create', 500, 300.0);
        $this->record($storage, $minute + 1, 'list', 200, 40.0);

        self::assertSame(2, $storage->rollup(self::NOW));
        self::assertSame(0, $storage->rollup(self::NOW));

        $series = $storage->series($minute - 180, $minute + 60);
        self::assertSame([$minute - 180, $minute - 120, $minute], array_keys($series));
        self::assertSame([2, 1, 0], [$series[$minute - 180]->calls, $series[$minute - 180]->throttled, $series[$minute - 180]->serverErrors]);
        self::assertSame([1, 1], [$series[$minute - 120]->calls, $series[$minute - 120]->serverErrors]);
        self::assertSame(1, $series[$minute]->calls);

        $totals = $storage->totals($minute - 180, $minute + 60, 'endpoint');
        self::assertSame(['list', 'create'], array_map(static fn ($stats): string => $stats->key, $totals));
        self::assertSame(3, $totals[0]->calls);
        self::assertSame(297.5, $totals[1]->percentile(0.95));
        self::assertSame(300.0, $totals[1]->durationMax);
    }

    public function testCallsAreBrokenDownByStatusAndErrorsAreGrouped(): void
    {
        $storage = $this->storage();
        $this->record($storage, self::NOW - 300, 'list', 200, 10.0);
        $this->record($storage, self::NOW - 200, 'list', 200, 10.0);
        $this->record($storage, self::NOW - 100, 'list', 404, 10.0, 'acme');
        $this->record($storage, self::NOW - 50, 'list', 404, 10.0);
        $this->record($storage, self::NOW - 40, 'list', 500, 10.0);
        $this->record($storage, self::NOW - 30, 'create', 404, 10.0);

        self::assertSame([200 => 2, 404 => 2, 500 => 1], $storage->statuses(self::NOW - 3600, self::NOW + 60, new CallQuery(endpoint: 'list')));

        $errors = $storage->errors(self::NOW - 3600, self::NOW + 60, new CallQuery(endpoint: 'list'));
        self::assertCount(2, $errors);
        self::assertSame([404, null, 2, 2, (self::NOW - 50) * 1000], [$errors[0]->status, $errors[0]->error, $errors[0]->calls, $errors[0]->consumers, $errors[0]->lastSeenAt]);
        self::assertSame([500, 1, 1], [$errors[1]->status, $errors[1]->calls, $errors[1]->consumers]);
    }

    public function testConsumerTotalsCarryTheirName(): void
    {
        $storage = $this->storage();
        $this->record($storage, self::NOW - 600, 'list', 200, 10.0, 'acme');
        $this->record($storage, self::NOW - 5, 'list', 200, 10.0, 'acme');
        $storage->rollup(self::NOW);

        $totals = $storage->totals(self::NOW - 3600, self::NOW + 60, 'consumer');
        self::assertCount(1, $totals);
        self::assertSame(['acme', 'Acme ERP', 2], [$totals[0]->key, $totals[0]->label, $totals[0]->calls]);
    }

    public function testMaintenanceCommandPurgesPastRetention(): void
    {
        $storage = $this->storage();
        $this->record($storage, self::NOW - 8 * 86400, 'list', 200, 10.0);
        $this->record($storage, self::NOW - 60, 'list', 200, 10.0);

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('tollbooth:monitoring:maintain'));
        self::assertSame(0, $tester->execute([]));

        self::assertStringContainsString('1 call and 0 aggregate rows purged.', $tester->getDisplay());
        self::assertCount(1, $storage->recent(new CallQuery()));
    }

    public function testSetupIsIdempotentAndCanPrintItsSql(): void
    {
        self::assertSame([], $this->storage()->setup());

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('tollbooth:monitoring:setup'));
        $tester->execute(['--dump-sql' => true]);
        self::assertStringContainsString('up to date', $tester->getDisplay());
    }

    private function storage(): DoctrineCallStorage
    {
        $storage = self::getContainer()->get(DoctrineCallStorage::class);
        self::assertInstanceOf(DoctrineCallStorage::class, $storage);

        return $storage;
    }

    private function record(DoctrineCallStorage $storage, int $at, string $endpoint, int $status, float $duration, string $consumer = 'ip:127.0.0.1'): void
    {
        $storage->record(new CallRecord(
            id: CallRecord::newId($at * 1000),
            occurredAt: $at * 1000,
            endpoint: $endpoint,
            group: 'default',
            method: 'GET',
            path: '/'.$endpoint,
            status: $status,
            duration: $duration,
            consumerId: $consumer,
            consumerName: 'acme' === $consumer ? 'Acme ERP' : $consumer,
            authMethod: 'api_key',
        ));
    }
}
