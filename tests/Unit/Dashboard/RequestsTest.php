<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\Requests\CallDetail;
use Tollbooth\Dashboard\Requests\RequestFilter;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\RateLimitHeaders;

final class RequestsTest extends TestCase
{
    public function testFiltersAreReadFromTheQueryAndWrittenBack(): void
    {
        $filter = RequestFilter::parse('  status:4xx consumer:acme-erp duration:>100 orders last:24h ');

        self::assertSame(4, $filter->statusClass());
        self::assertNull($filter->status());
        self::assertSame(100.0, $filter->minDuration());
        self::assertSame('acme-erp', $filter->get('consumer'));
        self::assertSame(86400, $filter->seconds());
        self::assertSame('orders', $filter->text);
        self::assertSame('status:4xx consumer:acme-erp duration:>100 last:24h orders', (string) $filter);
        self::assertSame('endpoint:list status:4xx duration:>100 last:24h orders', RequestFilter::parse($filter->with('endpoint', 'list'))->with('consumer', null));
        self::assertSame(429, RequestFilter::parse('status:429')->status());
        self::assertSame(3600, RequestFilter::parse('last:forever')->seconds());
    }

    public function testRateLimitHeadersAreReadBack(): void
    {
        self::assertSame(
            [
                ['name' => 'minute', 'limit' => 600, 'window' => 60, 'remaining' => 0, 'reset' => 8],
                ['name' => 'day', 'limit' => 200000, 'window' => 86400, 'remaining' => 46020, 'reset' => 35869],
            ],
            RateLimitHeaders::parse('"minute";q=600;w=60, "day";q=200000;w=86400', '"minute";r=0;t=8, "day";r=46020;t=35869'),
        );
        self::assertNull(RateLimitHeaders::parse(null, null));
    }

    public function testAThrottledCallStopsAtTheLimiterAndExplainsItself(): void
    {
        $call = new CallRecord('0193', 1_795_615_331_482, 'tollbooth_list', 'default', 'GET', '/api/products', 429, 1.2, 'k1', 'acme-erp', 'api_key',
            steps: ['auth' => 0.4, 'rate_limit' => 0.3],
            limiters: [['name' => 'minute', 'limit' => 600, 'window' => 60, 'remaining' => 0, 'reset' => 8], ['name' => 'day', 'limit' => 1000, 'window' => 86400, 'remaining' => 250, 'reset' => 3600]],
        );
        $detail = new CallDetail($call, new EndpointMetadata('list', 'GET', '/products', 200, 's', self::class, __FILE__, envelope: 'json_api'));

        self::assertSame('rate_limit', $detail->stoppedAt());
        self::assertSame(
            [['auth', 'ok'], ['rate_limit', 'stopped'], ['hydrate', 'skipped'], ['handler', 'skipped'], ['serialize', 'skipped']],
            array_map(static fn (array $step): array => [$step['key'], $step['state']], $detail->pipeline()),
        );
        self::assertSame([true, 1.0, 1_795_615_339], [$detail->limiters()[0]['exhausted'], $detail->limiters()[0]['used'], $detail->limiters()[0]['resetsAt']]);
        self::assertSame(0.75, $detail->limiters()[1]['used']);
        self::assertContains(['Retry-After', '8', true], $detail->headers());
        self::assertContains(['Content-Type', 'application/problem+json', false], $detail->headers());
        self::assertJsonStringEqualsJsonString(
            '{"type": "about:blank", "title": "Too Many Requests", "status": 429, "limiters": [{"name": "minute", "limit": 600, "remaining": 0, "reset": 8}, {"name": "day", "limit": 1000, "remaining": 250, "reset": 3600}]}',
            (string) $detail->problem(),
        );
    }

    public function testValidationErrorsAreShownAsViolations(): void
    {
        $call = new CallRecord('0194', 0, 'e', 'g', 'POST', '/orders', 422, 20.0, 'c', 'c', 'api_key', error: 'items[0].quantity: This value should be positive.', steps: ['auth' => 1.0, 'hydrate' => 3.0]);
        $detail = new CallDetail($call, null);

        self::assertSame('hydrate', $detail->stoppedAt());
        self::assertSame('none', $detail->pipeline()[1]['state']);
        self::assertJsonStringEqualsJsonString(
            '{"type": "about:blank", "title": "Validation Failed", "status": 422, "violations": [{"field": "items[0].quantity", "message": "This value should be positive."}]}',
            (string) $detail->problem(),
        );
        self::assertNull((new CallDetail(new CallRecord('0195', 0, 'e', 'g', 'GET', '/', 200, 1.0, 'c', 'c', 'api_key'), null))->problem());
    }
}
