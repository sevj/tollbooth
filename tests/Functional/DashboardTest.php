<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Tollbooth\Auth\ApiKey\ApiKeyFormat;
use Tollbooth\Auth\Doctrine\DoctrineApiKeyStore;
use Tollbooth\Changes\CurrentContract;
use Tollbooth\Changes\SnapshotStore;
use Tollbooth\Dashboard\Keys\FormGuard;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;
use Tollbooth\RateLimit\Doctrine\DoctrinePlanStore;
use Tollbooth\RateLimit\Plans;
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
        self::assertStringNotContainsString('id="tab-stats"', $html);
        self::assertStringNotContainsString('Needs attention', $html);

        $client->request('GET', '/_tollbooth/endpoints/default?filter=failing&sort=calls', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<span class="r">success</span>', $html);
        self::assertStringContainsString('get_thing</small>', $html);
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
        self::assertStringContainsString('by <span class="v"><a href="/_tollbooth/consumers/default/acme">Acme ERP</a></span>', $html);
        self::assertStringContainsString('mostly <span class="v">422 · name: This value should not be blank.</span>', $html);
        self::assertStringContainsString('class="row failing"', $html);
        self::assertStringContainsString('<span class="e bad">96.2%</span>', $html);
        self::assertStringContainsString('<span class="n">calls</span><span class="e">errors</span>', $html);
    }

    public function testTheEndpointListSortsAndFiltersByTraffic(): void
    {
        $client = $this->monitoredClient();
        $storage = $this->storage();
        for ($i = 0; $i < 30; ++$i) {
            $this->record($storage, self::NOW - 600 + $i, 'tollbooth_get_thing', 200, 12.0);
        }
        for ($i = 0; $i < 25; ++$i) {
            $this->record($storage, self::NOW - 300 + $i, 'tollbooth_create_thing', 422, 30.0);
        }

        $client->request('GET', '/_tollbooth/endpoints/default?range=1h', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<a class="nav on" href="/_tollbooth/endpoints/default" aria-current="page">', $html);
        self::assertLessThan(strpos($html, 'create_thing</small>'), strpos($html, 'get_thing</small>'), 'The most called endpoint comes first.');

        $client->request('GET', '/_tollbooth/endpoints/default?range=1h&filter=failing');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<span class="r bad">100%</span>', $html);
        self::assertStringNotContainsString('get_thing</small>', $html);

        $client->request('GET', '/_tollbooth/endpoints/default?range=1h&filter=idle&sort=path&dir=asc');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('create_thing</small>', $html);
        self::assertStringContainsString('aria-sort="ascending"', $html);
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
        self::assertStringContainsString('<a href="/_tollbooth/consumers/default/acme">Acme ERP</a>', $html);
        self::assertStringContainsString('curl -X POST &#039;http://localhost/api/things&#039;', $html);
        self::assertStringContainsString('where the time goes', $html);
        self::assertStringContainsString('<small>refuses with</small><span class="bad">422</span></span><span class="fs"><span class="bad">13%</span><small>of calls</small>', $html);
        self::assertStringContainsString('role="tabpanel" id="pane-stats" aria-labelledby="tab-stats" hidden', $html);
    }

    public function testTheEndpointPageWorksWithoutMonitoring(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/endpoints/default/get_thing', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<span class="ep-method GET">GET</span>/things/{id}</h1>', $html);
        self::assertStringContainsString('Turn on tollbooth.monitoring', $html);
        self::assertStringNotContainsString('id="tab-stats"', $html);

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
        self::assertStringContainsString('Your configuration: plain, names as declared. <a href="/_tollbooth/models/default/ThingView?view=diagram&amp;depth=all&amp;envelope=plain&amp;case=preserve#preview-title">Back to your configuration</a>', $html);
        self::assertStringContainsString('class="seg cfg" title="your configuration"', $html);

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

    public function testTheRequestsScreenListsFiltersAndDetailsCalls(): void
    {
        $client = $this->monitoredClient();
        $storage = $this->storage();
        $this->record($storage, self::NOW - 120, 'tollbooth_get_thing', 200, 12.0, steps: ['auth' => 1.0, 'handler' => 8.0]);
        $this->record($storage, self::NOW - 60, 'tollbooth_create_thing', 422, 30.0, 'name: This value should not be blank.', 'acme');

        $client->request('GET', '/_tollbooth/requests/default', ['q' => 'consumer:acme'], server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('1 matching · 1 shown', $html);
        self::assertStringContainsString('<span class="sc c4">422</span>', $html);
        self::assertStringNotContainsString('<span class="sc c2">200</span>', $html);
        self::assertStringContainsString('stopped at Hydrate', $html);
        self::assertStringContainsString('<span class="at">"field"</span>: <span class="s">"name"</span>', $html);

        $client->request('GET', '/_tollbooth/requests/default', ['q' => 'status:2xx duration:>10']);
        self::assertStringContainsString('<span class="sc c2">200</span>', (string) $client->getResponse()->getContent());

        $client->request('GET', '/_tollbooth/requests/default/feed', ['after' => '000000000000']);
        $feed = $this->json($client);
        self::assertIsString($feed['html']);
        self::assertSame(2, substr_count($feed['html'], 'class="rq'));

        $client->request('GET', '/_tollbooth/requests/default.ndjson');
        self::assertResponseIsSuccessful();
        $lines = array_filter(explode("\n", (string) $client->getInternalResponse()->getContent()));
        self::assertCount(2, $lines);
        self::assertStringContainsString('"consumer":"Acme ERP"', (string) reset($lines));
    }

    public function testTheConsumersScreensShowWhoCallsWhat(): void
    {
        $client = $this->monitoredClient();
        $storage = $this->storage();
        for ($i = 0; $i < 3; ++$i) {
            $this->record($storage, self::NOW - 300 + $i, 'tollbooth_get_thing', 200, 10.0, consumer: 'acme');
        }
        $this->record($storage, self::NOW - 100, 'tollbooth_list_things', 429, 1.0, 'Rate limit "minute" reached.', 'acme');
        $this->record($storage, self::NOW - 50, 'tollbooth_get_thing', 200, 10.0);

        $client->request('GET', '/_tollbooth/consumers/default', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<a href="/_tollbooth/consumers/default/acme">Acme ERP</a>', $html);
        self::assertStringContainsString('anonymous · 1 IP', $html);
        self::assertStringContainsString('Acme ERP <tspan class="pct">80%</tspan>', $html);
        self::assertMatchesRegularExpression('~<a href="/_tollbooth/consumers/default/acme"><text [^>]+>Acme ERP ~', $html);

        $client->request('GET', '/_tollbooth/consumers/default?show=throttled');
        self::assertStringNotContainsString('anonymous · 1 IP', (string) $client->getResponse()->getContent());

        $client->request('GET', '/_tollbooth/consumers/default/acme', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<h1 class="ep-title">Acme ERP</h1>', $html);
        self::assertStringContainsString('<div class="stat"><div class="th">Calls 24h</div><div class="v">4</div></div>', $html);
        self::assertStringContainsString('/things/{id}</a><span class="r">3</span>', $html);

        $client->request('GET', '/_tollbooth/consumers/default/nobody');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheChangesScreenComparesTheCodeWithTheLastSnapshot(): void
    {
        (new Filesystem())->remove(TestKernel::contractDirectory());
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/changes/default', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No contract snapshot yet', (string) $client->getResponse()->getContent());

        $store = self::getContainer()->get(SnapshotStore::class);
        self::assertInstanceOf(SnapshotStore::class, $store);
        $contract = self::getContainer()->get(CurrentContract::class);
        self::assertInstanceOf(CurrentContract::class, $contract);
        $documents = $contract->documents();
        $document = $documents['default'];
        self::assertIsArray($document['paths'] ?? null);
        $document['paths']['/gone'] = ['get' => ['operationId' => 'tollbooth_gone', 'responses' => ['200' => ['description' => 'Success.']]]];
        $store->save('1.2.0', ['default' => $document], new \DateTimeImmutable('2026-11-01'));

        $client->request('GET', '/_tollbooth/changes/default', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<div class="stat"><div class="th">breaking</div><div class="v kc-breaking">1</div></div>', $html);
        self::assertStringContainsString('<span class="el">GET /gone</span>', $html);
        self::assertStringContainsString('## 2.0.0 (unreleased)', $html);
        self::assertStringContainsString('<span class="count" style="color: #FF8A7A">1</span>', $html);
        (new Filesystem())->remove(TestKernel::contractDirectory());
    }

    public function testTheHealthScreenRunsTheChecks(): void
    {
        $client = $this->monitoredClient();
        $client->request('GET', '/_tollbooth/status/default', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<span class="n">metadata</span>', $html);
        self::assertStringContainsString('application · HealthCheckInterface', $html);
        self::assertStringContainsString('/_tollbooth/health/ready', $html);
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
        $this->keyStore()->setup();
        $plans = self::getContainer()->get(DoctrinePlanStore::class);
        self::assertInstanceOf(DoctrinePlanStore::class, $plans);
        $plans->setup();
        $cache = self::getContainer()->get('tollbooth.test_limits');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        return $client;
    }

    /**
     * @return array<string>
     */
    private static function capture(string $pattern, string $subject): array
    {
        self::assertSame(1, preg_match($pattern, $subject, $matches));

        return $matches;
    }

    private function keyStore(): DoctrineApiKeyStore
    {
        $store = self::getContainer()->get(DoctrineApiKeyStore::class);
        self::assertInstanceOf(DoctrineApiKeyStore::class, $store);

        return $store;
    }

    public function testKeysAreCreatedRotatedAndRevokedFromTheDashboard(): void
    {
        $client = $this->monitoredClient();
        $guard = new FormGuard('test');

        $client->request('GET', '/_tollbooth/consumers/default/keys/new', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/_tollbooth/consumers/default/keys/new', ['_token' => $guard->token('create'), 'name' => 'bad name!', 'scopes' => 'orders:read', 'expires' => '2020-01-01', 'allowed_ips' => '10.0.0.0/40'], server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseStatusCodeSame(422);
        $html = (string) $client->getResponse()->getContent();
        foreach (['Use letters, digits', 'The date must be in the future.', 'An address or range is not valid.'] as $message) {
            self::assertStringContainsString($message, $html);
        }

        $client->request('POST', '/_tollbooth/consumers/default/keys/new', ['_token' => $guard->token('create'), 'name' => 'acme-erp', 'scopes' => 'orders:read, orders:write', 'expires' => '2027-03-01', 'allowed_ips' => '203.0.113.0/24'], server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $first = self::capture('/tb_live_([a-z0-9]{12})_([A-Za-z0-9]{32})/', (string) $client->getResponse()->getContent());
        $key = $this->keyStore()->find($first[1]);
        self::assertNotNull($key);
        self::assertSame(['acme-erp', ['orders:read', 'orders:write'], ['203.0.113.0/24'], '2027-03-01'], [$key->name, $key->scopes, $key->allowedIps, $key->expiresAt?->format('Y-m-d')]);
        self::assertTrue(ApiKeyFormat::verify($first[2], $key->hash));

        $client->request('POST', '/_tollbooth/consumers/default/keys/new', ['_token' => $guard->token('create'), 'name' => 'ACME-ERP'], server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertStringContainsString('A key already has this name.', (string) $client->getResponse()->getContent());

        $client->request('GET', '/_tollbooth/consumers/default/'.$key->id, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertStringContainsString('Rotate key', (string) $client->getResponse()->getContent());

        $client->request('POST', '/_tollbooth/consumers/default/'.$key->id.'/rotate', ['_token' => $guard->token('rotate', $key->id)]);
        self::assertResponseIsSuccessful();
        $second = self::capture('/tb_live_'.$key->id.'_([A-Za-z0-9]{32})/', (string) $client->getResponse()->getContent());
        $rotated = $this->keyStore()->find($key->id);
        self::assertNotNull($rotated);
        self::assertTrue(ApiKeyFormat::verify($second[1], $rotated->hash));
        self::assertFalse(ApiKeyFormat::verify($first[2], $rotated->hash));

        $client->request('POST', '/_tollbooth/consumers/default/'.$key->id.'/revoke', ['_token' => $guard->token('revoke', $key->id)], server: ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/_tollbooth/consumers/default/'.$key->id.'/revoke', ['_token' => $guard->token('rotate', $key->id)]);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/_tollbooth/consumers/default/'.$key->id.'/revoke', ['_token' => $guard->token('revoke', $key->id)], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/_tollbooth/consumers/default?revoked=acme-erp', 303);
        self::assertNull($this->keyStore()->find($key->id));
    }

    public function testPlansAreCreatedEditedAndDeletedFromTheConfigurationScreen(): void
    {
        $client = $this->monitoredClient();
        $guard = new FormGuard('test');

        $client->request('GET', '/_tollbooth/configuration/default', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<h2 id="plans-title">Rate limit plans<span class="m">0</span></h2>', $html);
        self::assertStringContainsString('<span>tollbooth.monitoring.calls_retention_days</span><span>7</span>', $html);
        self::assertStringNotContainsString('probe-secret', $html);

        $client->request('POST', '/_tollbooth/configuration/default/plans/new', ['_token' => $guard->token('plan_create'), 'name' => 'Partner!', 'limits' => "60/minute\nlots/day"], server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseStatusCodeSame(422);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Use only lowercase letters, digits, - and _.', $html);
        self::assertStringContainsString('&quot;lots/day&quot; is not a limit.', $html);

        $client->request('POST', '/_tollbooth/configuration/default/plans/new', ['_token' => $guard->token('plan_create'), 'name' => 'partner', 'limits' => "burst: 10/10 seconds\n600/minute"]);
        self::assertResponseRedirects('/_tollbooth/configuration/default?saved=partner#plans-title', 303);
        self::assertSame(['partner' => [['name' => 'burst', 'limit' => 10, 'window' => 10, 'scope' => 'plan'], ['name' => 'minute', 'limit' => 600, 'window' => 60, 'scope' => 'plan']]], $this->plans()->all());

        $client->request('GET', '/_tollbooth/configuration/default/plans/partner');
        self::assertStringContainsString(">burst: 10/10 seconds\n600/minute</textarea>", (string) $client->getResponse()->getContent());
        $client->request('POST', '/_tollbooth/configuration/default/plans/partner', ['_token' => $guard->token('plan_edit', 'partner'), 'limits' => '1000/day']);
        self::assertResponseRedirects('/_tollbooth/configuration/default?saved=partner#plans-title', 303);
        self::assertSame(['partner' => [['name' => 'day', 'limit' => 1000, 'window' => 86400, 'scope' => 'plan']]], $this->plans()->all());

        $client->request('GET', '/_tollbooth/consumers/default/keys/new');
        self::assertStringContainsString('<option value="partner">partner</option>', (string) $client->getResponse()->getContent());

        $client->request('POST', '/_tollbooth/configuration/default/plans/partner/delete', ['_token' => $guard->token('plan_edit', 'partner')]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/_tollbooth/configuration/default/plans/partner/delete', ['_token' => $guard->token('plan_delete', 'partner')]);
        self::assertResponseRedirects('/_tollbooth/configuration/default?deleted=partner#plans-title', 303);
        self::assertSame([], $this->plans()->all());

        $client->request('GET', '/_tollbooth/configuration/default/plans/new', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertStringContainsString('<input type="checkbox" name="default" value="1" checked>', (string) $client->getResponse()->getContent());
        $client->request('POST', '/_tollbooth/configuration/default/plans/new', ['_token' => $guard->token('plan_create'), 'name' => 'gold', 'limits' => '100/minute', 'default' => '1']);
        self::assertSame(['gold', 'gold'], [$this->plans()->defaultPlan(), $this->plans()->anonymousPlan()]);
        $client->request('GET', '/_tollbooth/configuration/default', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertStringNotContainsString('/plans/gold/delete', (string) $client->getResponse()->getContent());
        $client->request('POST', '/_tollbooth/configuration/default/plans/gold/delete', ['_token' => $guard->token('plan_delete', 'gold')]);
        self::assertResponseStatusCodeSame(409);
    }

    private function plans(): Plans
    {
        $plans = self::getContainer()->get(Plans::class);
        self::assertInstanceOf(Plans::class, $plans);
        $plans->reset();

        return $plans;
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
