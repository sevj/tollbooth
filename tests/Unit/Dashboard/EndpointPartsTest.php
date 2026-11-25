<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\Endpoint\Operation;
use Tollbooth\Dashboard\Endpoint\PhpHighlighter;
use Tollbooth\Dashboard\Endpoint\Pipeline;
use Tollbooth\Dashboard\Endpoint\PipelineStep;
use Tollbooth\Dashboard\Endpoint\Snippets;
use Tollbooth\Dashboard\Endpoint\TimeBreakdown;
use Tollbooth\Dashboard\Twig\Format;
use Tollbooth\Monitoring\CallRecord;

final class EndpointPartsTest extends TestCase
{
    public function testAttributesKeywordsStringsAndTypesAreHighlighted(): void
    {
        $html = PhpHighlighter::highlight("#[Endpoint('POST', '/orders', tags: ['a'])]\nfinal class CreateOrder\n{\n    // <b>\n}");

        self::assertSame(
            '<span class="at">#[</span><span class="at">Endpoint</span>(<span class="s">&#039;POST&#039;</span>, <span class="s">&#039;/orders&#039;</span>, tags: [<span class="s">&#039;a&#039;</span>])<span class="at">]</span>'
            ."\n".'<span class="kw">final</span> <span class="kw">class</span> <span class="ty">CreateOrder</span>'."\n{\n    ".'<span class="mu">// &lt;b&gt;</span>'."\n}",
            $html,
        );
    }

    public function testJsonIsHighlightedAndEscaped(): void
    {
        self::assertSame(
            '{<span class="at">"a"</span>: <span class="s">"&lt;x&gt;"</span>, <span class="at">"b"</span>: <span class="nu">-1.5</span>, <span class="at">"c"</span>: <span class="kw">null</span>}',
            Format::json('{"a": "<x>", "b": -1.5, "c": null}'),
        );
    }

    public function testRelativeTimesAreRounded(): void
    {
        self::assertSame(['unit' => 'now', 'count' => 0], Format::ago(1_000_000_000_000, 1_000_000_030));
        self::assertSame(['unit' => 'minutes', 'count' => 3], Format::ago(1_000_000_000_000, 1_000_000_200));
        self::assertSame(['unit' => 'hours', 'count' => 2], Format::ago(1_000_000_000_000, 1_000_007_300));
        self::assertSame(['unit' => 'days', 'count' => 1], Format::ago(1_000_000_000_000, 1_000_090_000));
    }

    public function testTheTimeOfCallsAroundTheP95IsSplitByStep(): void
    {
        $calls = [];
        for ($i = 1; $i <= 100; ++$i) {
            $calls[] = self::call($i, ['auth' => 1.0, 'handler' => $i - 3.0]);
        }
        $calls[] = self::call(5000, [], 500);

        $time = TimeBreakdown::aroundP95($calls);

        self::assertNotNull($time);
        self::assertSame(100, $time->sample);
        self::assertSame(95.0, $time->total);
        self::assertSame(['kernel' => 2.0, 'auth' => 1.0, 'rate_limit' => 0.0, 'hydrate' => 0.0, 'handler' => 92.0, 'serialize' => 0.0], $time->segments);
        self::assertNull(TimeBreakdown::aroundP95([self::call(10, ['auth' => 1.0])]));
    }

    public function testEachPipelineStepCountsTheStatusesItAnswers(): void
    {
        $endpoint = new EndpointMetadata('create_order', 'POST', '/orders', 201, 's', self::class, __FILE__, auth: ['api_key'], scopes: ['orders:write'], limits: [['name' => 'x.minute', 'limit' => 600, 'window' => 60, 'scope' => 'endpoint']]);
        $steps = Pipeline::steps($endpoint, null, [201 => 90, 422 => 5, 429 => 3, 409 => 2], true);

        self::assertSame(
            [
                ['auth', [401, 403], 0.0],
                ['rate_limit', [429], 0.03],
                ['hydrate', [400, 415], 0.0],
                ['validate', [422], 0.05],
                ['handler', [409], 0.02],
                ['serialize', [201], 0.9],
            ],
            array_map(static fn (PipelineStep $step): array => [$step->key, $step->codes, $step->share], $steps),
        );
        self::assertSame(['api_key', ['key' => 'scope', 'parameters' => ['%scopes%' => 'orders:write']]], $steps[0]->lines);
        self::assertSame(['600/minute', ['key' => 'plan_extra', 'parameters' => []]], $steps[1]->lines);
    }

    public function testSnippetsCallTheEndpointWithItsCredentialsAndAnExampleBody(): void
    {
        $endpoint = new EndpointMetadata('create_order', 'POST', '/orders/{id}/lines', 201, 's', self::class, __FILE__, auth: ['api_key']);
        $operation = new Operation([], 'LineInput', [], [], "{\n    \"qty\": 1\n}", null, null, 201, null);
        $snippets = Snippets::for($endpoint, $operation, 'https://shop.test/api', 'X-Api-Key');

        self::assertSame(
            "curl -X POST 'https://shop.test/api/orders/42/lines' \\\n  -H 'X-Api-Key: tb_live_…' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"qty\":1}'",
            $snippets->curl(),
        );
        self::assertSame(
            "\$response = \$client->request('POST', 'https://shop.test/api/orders/42/lines', [\n    'headers' => [\n        'X-Api-Key' => 'tb_live_…',\n    ],\n    'json' => [\n        'qty' => 1,\n    ],\n]);",
            $snippets->php(),
        );
        self::assertStringContainsString("  body: JSON.stringify({\n      \"qty\": 1\n  }),", $snippets->javascript());
    }

    /**
     * @param array<string, float> $steps
     */
    private static function call(float $duration, array $steps, int $status = 200): CallRecord
    {
        return new CallRecord('id', 0, 'e', 'g', 'GET', '/', $status, $duration, 'c', 'c', 'api_key', steps: $steps);
    }
}
