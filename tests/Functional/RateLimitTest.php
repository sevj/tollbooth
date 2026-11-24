<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tollbooth\Tests\Fixtures\TestKeys;

final class RateLimitTest extends FunctionalTestCase
{
    public function testEveryLimitIsReportedOnASuccessfulResponse(): void
    {
        $client = $this->client();
        $this->call($client, '/api/things/7');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('RateLimit-Policy', '"burst";q=3;w=10, "minute";q=5;w=60');
        self::assertResponseHeaderSame('RateLimit', '"burst";r=2;t=5, "minute";r=4;t=55');
    }

    public function testLegacyHeadersDescribeTheTightestLimit(): void
    {
        $client = $this->client();
        $this->call($client, '/api/things/7');

        self::assertResponseHeaderSame('X-RateLimit-Limit', '3');
        self::assertResponseHeaderSame('X-RateLimit-Remaining', '2');
        self::assertResponseHeaderSame('X-RateLimit-Reset', '5');
    }

    public function testExhaustedLimitAnswersTooManyRequestsWithTheLimiters(): void
    {
        $client = $this->client();
        for ($i = 0; $i < 3; ++$i) {
            $this->call($client, '/api/things/7');
            self::assertResponseStatusCodeSame(200);
        }

        $this->call($client, '/api/things/7');

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('Retry-After', '5');
        self::assertResponseHeaderSame('RateLimit', '"burst";r=0;t=5, "minute";r=2;t=55');
        self::assertSame([
            'type' => 'about:blank',
            'title' => 'Too Many Requests',
            'status' => 429,
            'detail' => 'Rate limit "burst" reached: 3 requests per 10 seconds.',
            'limiters' => [
                ['name' => 'burst', 'limit' => 3, 'remaining' => 0, 'reset' => 5],
                ['name' => 'minute', 'limit' => 5, 'remaining' => 2, 'reset' => 55],
            ],
        ], $this->json($client));
    }

    public function testEndpointLimitComesOnTopOfThePlan(): void
    {
        $client = $this->client();
        $this->call($client, '/api/search');
        self::assertResponseHeaderSame('RateLimit-Policy', '"burst";q=3;w=10, "minute";q=5;w=60, "search.minute";q=2;w=60');

        $this->call($client, '/api/search');
        $this->call($client, '/api/search');

        self::assertResponseStatusCodeSame(429);
        self::assertSame('Rate limit "search.minute" reached: 2 requests per minute.', $this->json($client)['detail']);
        self::assertResponseHeaderSame('Retry-After', '55');
    }

    public function testAnonymousConsumersUseTheAnonymousPlan(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/open');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('RateLimit-Policy', '"minute";q=2;w=60');
    }

    public function testErrorResponsesCarryTheHeadersToo(): void
    {
        $client = $this->client();
        $client->jsonRequest('POST', '/api/things', ['name' => ''], ['HTTP_X_API_KEY' => TestKeys::READER]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('RateLimit', '"burst";r=2;t=5, "minute";r=4;t=55');
    }

    public function testUnauthenticatedCallsAreNotCounted(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/things/7');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseNotHasHeader('RateLimit');
    }

    private function client(): KernelBrowser
    {
        $client = self::createClient(['environment' => 'rate_limit']);
        $pool = self::getContainer()->get('tollbooth.test_limits');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        return $client;
    }

    private function call(KernelBrowser $client, string $path): void
    {
        $client->request('GET', $path, server: ['HTTP_X_API_KEY' => TestKeys::READER]);
    }
}
