<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;
use Tollbooth\Tests\Fixtures\TestKernel;

final class DashboardTest extends FunctionalTestCase
{
    private const int NOW = 1795615325;

    public function testTheHomePageOpensTheMapOfTheFirstGroup(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth');

        self::assertResponseRedirects('/_tollbooth/map/default');
    }

    public function testTheMapShowsResourcesWithoutTrafficWhenMonitoringIsOff(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/map/default', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<h1>API map</h1>', $html);
        self::assertStringContainsString('<strong>Things</strong>', $html);
        self::assertStringContainsString('/things/{id}', $html);
        self::assertStringContainsString('Turn on tollbooth.monitoring', $html);
        self::assertStringNotContainsString('Needs attention', $html);
    }

    public function testTheMapOverlaysTrafficAndListsWhatNeedsAttention(): void
    {
        $client = $this->monitoredClient();
        $storage = $this->storage();
        for ($i = 0; $i < 30; ++$i) {
            $this->record($storage, self::NOW - 600 + $i, 'tollbooth_get_thing', 200, 12.0);
        }
        for ($i = 0; $i < 25; ++$i) {
            $this->record($storage, self::NOW - 300 + $i, 'tollbooth_create_thing', 422, 30.0, 'name: This value should not be blank.');
        }
        $this->record($storage, self::NOW - 200, 'tollbooth_create_thing', 201, 30.0);
        $this->record($storage, self::NOW - 100, 'tollbooth_legacy_thing', 200, 8.0, consumer: 'acme');

        $client->request('GET', '/_tollbooth/map/default?range=1h', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<strong>57</strong>', $html);
        self::assertStringContainsString('Needs attention<span class="m">2</span>', $html);
        self::assertStringContainsString('sunset on <span class="v">2027-03-01</span>', $html);
        self::assertStringContainsString('by <span class="v">Acme ERP</span>', $html);
        self::assertStringContainsString('mostly <span class="v">422 · name: This value should not be blank.</span>', $html);
        self::assertStringContainsString('class="row failing"', $html);
    }

    public function testTheEndpointPageShowsItsContractAndItsTraffic(): void
    {
        $client = $this->monitoredClient();
        $storage = $this->storage();
        for ($i = 0; $i < 20; ++$i) {
            $this->record($storage, self::NOW - 600 + $i, 'tollbooth_create_thing', 201, 30.0 + $i, steps: ['auth' => 1.0, 'hydrate' => 2.0, 'handler' => 20.0, 'serialize' => 3.0]);
        }
        for ($i = 0; $i < 3; ++$i) {
            $this->record($storage, self::NOW - 60 + $i, 'tollbooth_create_thing', 422, 12.0, 'name: This value should not be blank.', 'acme');
        }

        $client->request('GET', '/_tollbooth/endpoints/default/create_thing?range=1h', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<span class="ep-method POST">POST</span>/things</h1>', $html);
        self::assertStringContainsString('<span class="at">#[</span><span class="at">Endpoint</span>', $html);
        self::assertStringContainsString('body → CreateThingInput', $html);
        self::assertStringContainsString('<div class="stat"><div class="th">Calls 1h</div><div class="v">23</div></div>', $html);
        self::assertStringContainsString('<div class="msg">name: This value should not be blank.</div><div class="sub">×3 · 1 consumer', $html);
        self::assertStringContainsString('Acme ERP', $html);
        self::assertStringContainsString('curl -X POST &#039;http://localhost/api/things&#039;', $html);
        self::assertStringContainsString('where the time goes', $html);
    }

    public function testTheEndpointPageWorksWithoutMonitoring(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/endpoints/default/get_thing', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<span class="ep-method GET">GET</span>/things/{id}</h1>', $html);
        self::assertStringContainsString('Turn on tollbooth.monitoring', $html);

        $client->request('GET', '/_tollbooth/endpoints/default/nope');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheModelsScreenDrawsAModelAndItsNeighbours(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/models/default');
        self::assertResponseRedirects();

        foreach (['', '?view=table', '?depth=all&envelope=json_api&case=camel_case'] as $query) {
            $client->request('GET', '/_tollbooth/models/default/ThingView'.$query, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
            self::assertResponseIsSuccessful();
        }
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<text class="md-title" x="12" y="25">ThingView</text>', $html);
        self::assertStringContainsString('<text class="md-title" x="12" y="25">Color</text>', $html);
        self::assertStringContainsString('<span class="at">"unitCount"</span>', $html);
        self::assertStringContainsString('<span class="at">"type"</span>: <span class="s">"things"</span>', $html);

        $client->request('GET', '/_tollbooth/models/default/Color?view=table');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<span>&quot;red&quot;</span>', (string) $client->getResponse()->getContent());

        $client->request('GET', '/_tollbooth/models/default/ThingView.svg');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml; charset=UTF-8');
        self::assertStringStartsWith('<?xml', (string) $client->getResponse()->getContent());

        $client->request('GET', '/_tollbooth/models/default/Nope');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheDashboardSpeaksTheLanguageOfTheBrowser(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/map/default', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.5']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<h1>Carte de l&#039;API</h1>', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('lang="fr"', (string) $client->getResponse()->getContent());
    }

    public function testTheMapExportsToMermaid(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/map/default.mmd');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="default-map.mmd"');
        $mermaid = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("flowchart LR\n", $mermaid);
        self::assertStringContainsString('["GET /things/{id}"]', $mermaid);
    }

    public function testUnknownGroupsAreNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/map/nope');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAssetsShipWithTheBundleAndAreCachedByVersion(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/assets/dashboard.css?v=1');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/css; charset=UTF-8');
        self::assertStringContainsString('immutable', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->request('GET', '/_tollbooth/assets/missing.css');
        self::assertResponseStatusCodeSame(404);
    }

    private function monitoredClient(): KernelBrowser
    {
        (new Filesystem())->remove(TestKernel::monitoringDatabase());
        (new Filesystem())->mkdir(\dirname(TestKernel::monitoringDatabase()));
        $client = self::createClient(['environment' => 'monitoring']);
        $this->storage()->setup();

        return $client;
    }

    private function storage(): DoctrineCallStorage
    {
        $storage = self::getContainer()->get(DoctrineCallStorage::class);
        self::assertInstanceOf(DoctrineCallStorage::class, $storage);

        return $storage;
    }

    /**
     * @param array<string, float> $steps
     */
    private function record(DoctrineCallStorage $storage, int $at, string $endpoint, int $status, float $duration, ?string $error = null, string $consumer = 'ip:127.0.0.1', array $steps = []): void
    {
        $storage->record(new CallRecord(
            id: CallRecord::newId($at * 1000),
            occurredAt: $at * 1000,
            endpoint: $endpoint,
            group: 'default',
            method: 'GET',
            path: '/',
            status: $status,
            duration: $duration,
            consumerId: $consumer,
            consumerName: 'acme' === $consumer ? 'Acme ERP' : $consumer,
            authMethod: 'api_key',
            error: $error,
            steps: $steps,
        ));
    }
}
