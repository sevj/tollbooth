<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Tollbooth\Auth\Doctrine\DoctrineApiKeyStore;
use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;
use Tollbooth\Tests\Fixtures\Health\ExternalServiceCheck;
use Tollbooth\Tests\Fixtures\TestKernel;

final class HealthTest extends FunctionalTestCase
{
    private const int NOW = 1795615325;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        ExternalServiceCheck::$result = null;
        (new Filesystem())->remove(TestKernel::monitoringDatabase());
        (new Filesystem())->mkdir(\dirname(TestKernel::monitoringDatabase()));
        $this->client = self::createClient(['environment' => 'monitoring']);
        $this->storage()->setup();
        $keys = self::getContainer()->get(DoctrineApiKeyStore::class);
        self::assertInstanceOf(DoctrineApiKeyStore::class, $keys);
        $keys->setup();
        $plans = self::getContainer()->get(DoctrinePlanStore::class);
        self::assertInstanceOf(DoctrinePlanStore::class, $plans);
        $plans->setup();
        $plans->save('open', Limit::parseAll(['1000/minute'], 'plan'), default: true);
        $cache = self::getContainer()->get('tollbooth.test_limits');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
    }

    public function testLivenessOnlySaysTheProcessAnswers(): void
    {
        $this->client->request('GET', '/_tollbooth/health/live');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok'], $this->json($this->client));
    }

    public function testReadinessHidesDetailsWithoutTheToken(): void
    {
        $this->client->request('GET', '/_tollbooth/health/ready');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok', 'checked_at' => '2026-11-25T14:02:05Z'], $this->json($this->client));
    }

    public function testReadinessShowsTheChecksWithTheToken(): void
    {
        $this->client->request('GET', '/_tollbooth/health/ready', server: ['HTTP_AUTHORIZATION' => 'Bearer probe-secret']);

        $report = $this->json($this->client);
        self::assertIsArray($report['checks']);
        self::assertSame(
            [['metadata', 'tollbooth', 'ok'], ['rate_limit_storage', 'tollbooth', 'ok'], ['plan_storage', 'tollbooth', 'ok'], ['monitoring', 'tollbooth', 'ok'], ['api_key_storage', 'tollbooth', 'ok'], ['external_service', 'application', 'ok']],
            array_map(static fn (mixed $check): array => \is_array($check) ? [$check['name'] ?? null, $check['source'] ?? null, $check['status'] ?? null] : [], $report['checks']),
        );
        self::assertSame([], $report['findings']);
    }

    public function testAWarningDegradesWithoutFailingReadiness(): void
    {
        ExternalServiceCheck::$result = static fn (): CheckResult => CheckResult::warning('Answered in 2.4 s.');
        $this->client->request('GET', '/_tollbooth/health/ready');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('degraded', $this->json($this->client)['status']);
    }

    public function testAFailingCheckFailsReadiness(): void
    {
        ExternalServiceCheck::$result = static fn (): never => throw new \RuntimeException('Connection refused to 10.0.0.12');
        $this->client->request('GET', '/_tollbooth/health/ready', server: ['HTTP_AUTHORIZATION' => 'Bearer probe-secret']);

        self::assertResponseStatusCodeSame(503);
        $report = $this->json($this->client);
        self::assertSame('critical', $report['status']);
        self::assertIsArray($report['checks']);
        self::assertSame(['status' => 'critical', 'summary' => 'The check failed with RuntimeException.'], array_intersect_key((array) $report['checks'][5], ['status' => 0, 'summary' => 0]));
    }

    public function testWrongTokenIsIgnored(): void
    {
        $this->client->request('GET', '/_tollbooth/health/ready?token=guess');

        self::assertArrayNotHasKey('checks', $this->json($this->client));
    }

    public function testReportListsFindingsAndFacts(): void
    {
        $this->client->request('GET', '/_tollbooth/health.json');

        $report = $this->json($this->client);
        self::assertIsArray($report['findings']);
        $messages = array_column($report['findings'], 'message');
        self::assertContains('The health report at /_tollbooth/health.json is outside any firewall: anyone can read it.', $messages);
        self::assertContains('The documentation at /_tollbooth/docs is outside any firewall: anyone can read it.', $messages);
        self::assertNotEmpty(preg_grep('/^8 endpoints received no call in 30 days: /', $messages));
        self::assertIsArray($report['facts']);
        self::assertSame(['tollbooth', 'symfony', 'php', 'environment', 'conventions', 'rate_limit', 'monitoring'], array_keys($report['facts']));
        self::assertSame('monitoring', $report['facts']['environment']);
    }

    public function testAggregationBacklogIsAWarning(): void
    {
        $this->storage()->record(new CallRecord(CallRecord::newId((self::NOW - 3600) * 1000), (self::NOW - 3600) * 1000, 'x', 'default', 'GET', '/x', 200, 1.0, 'c', 'c', 'anonymous'));
        $this->client->request('GET', '/_tollbooth/health.json');

        $report = $this->json($this->client);
        self::assertSame('degraded', $report['status']);
        self::assertIsArray($report['checks']);
        self::assertIsArray($report['checks'][3]);
        self::assertSame('Aggregation is 58 minutes behind. Check auto_maintenance, or schedule tollbooth:monitoring:maintain.', $report['checks'][3]['summary']);
    }

    public function testDeprecatedEndpointsStillCalledAreFindings(): void
    {
        $this->client->request('GET', '/api/v1/things/7');
        $this->client->request('GET', '/_tollbooth/health.json');

        $report = $this->json($this->client);
        self::assertIsArray($report['findings']);
        self::assertContains('GET /v1/things/{id} is deprecated (sunset 2027-03-01) and was called 1 time in 7 days by ip:127.0.0.1.', array_column($report['findings'], 'message'));
    }

    public function testExpiredApiKeysAreFindings(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient(['environment' => 'auth']);
        $client->request('GET', '/_tollbooth/health.json');

        $report = $this->json($client);
        self::assertIsArray($report['findings']);
        self::assertContains('The API key of "expired" expired on 2020-01-01 and is refused.', array_column($report['findings'], 'message'));
    }

    public function testCommandPrintsTheChecks(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('tollbooth:health'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('external_service', $tester->getDisplay());
        self::assertStringContainsString('Status: ok', $tester->getDisplay());
    }

    private function storage(): DoctrineCallStorage
    {
        $storage = self::getContainer()->get(DoctrineCallStorage::class);
        self::assertInstanceOf(DoctrineCallStorage::class, $storage);

        return $storage;
    }
}
