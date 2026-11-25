<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tollbooth\Dashboard\Consumers\ConsumerPage;
use Tollbooth\Dashboard\Consumers\ConsumerRow;
use Tollbooth\Dashboard\Consumers\FlowDiagram;
use Tollbooth\Monitoring\Stats;

final class ConsumersTest extends TestCase
{
    public function testFlowsJoinEachConsumerToTheResourcesItCalls(): void
    {
        $flow = FlowDiagram::build([
            'mobile' => ['Products' => 60, 'Orders' => 20],
            'erp' => ['Orders' => 20],
        ]);

        self::assertSame(100, $flow->total);
        self::assertSame(['mobile', 'erp'], array_column($flow->sources, 'label'));
        self::assertSame(['Products', 'Orders'], array_column($flow->targets, 'label'));
        self::assertSame([0.8, 0.2], array_column($flow->sources, 'share'));
        self::assertSame([0.0, 258.0], array_column($flow->sources, 'y'));
        self::assertSame(['#F0B429', '#C3A8FF'], array_column($flow->sources, 'color'));
        self::assertCount(3, $flow->flows);
        self::assertSame('M12,0 C320,0 320,0 628,0 L628,186 C320,186 320,186 12,186Z', $flow->flows[0]['path']);
        self::assertSame('M12,186 C320,186 320,196 628,196 L628,258 C320,258 320,248 12,248Z', $flow->flows[1]['path']);
        self::assertSame('M12,258 C320,258 320,258 628,258 L628,320 C320,320 320,320 12,320Z', $flow->flows[2]['path']);
        self::assertSame(0, FlowDiagram::build([])->total);
    }

    public function testSmallNodesKeepTheirLabelsApart(): void
    {
        $flow = FlowDiagram::build(['a' => ['x' => 1000], 'b' => ['x' => 1], 'c' => ['x' => 1]]);

        $labels = array_column($flow->sources, 'labelY');
        self::assertGreaterThanOrEqual(15, $labels[2] - $labels[1]);
    }

    public function testQuotasAndLimitsDecideWhoIsNearTheirLimit(): void
    {
        $stats = new Stats('k', 'k', 100, 0, 0, 3, 0.0, 0.0, []);

        self::assertTrue((new ConsumerRow('k', 'k', stats: $stats, today: 850, dayLimit: 1000))->nearLimit());
        self::assertTrue((new ConsumerRow('k', 'k', stats: $stats, today: 10, dayLimit: 1000, minuteLimit: 60, peak: 58))->nearLimit());
        self::assertFalse((new ConsumerRow('k', 'k', stats: $stats, today: 10, dayLimit: 1000, minuteLimit: 60, peak: 20))->nearLimit());
        self::assertTrue((new ConsumerRow('k', 'k', stats: $stats))->throttled());
        self::assertTrue((new ConsumerRow('k', 'k'))->idle());
        self::assertSame(1.0, (new ConsumerRow('k', 'k', today: 1500, dayLimit: 1000))->quota());
    }

    public function testTheQuotaChartProjectsWhenTheQuotaRunsOut(): void
    {
        $now = 1_795_615_325;
        $dayStart = intdiv($now, 86400) * 86400;
        $page = new ConsumerPage(
            new ConsumerRow('k', 'k', today: 500, dayLimit: 1000, minuteLimit: 10),
            [],
            [100, 300, 500],
            $dayStart + 43200,
            [['calls' => 4, 'throttled' => 0], ['calls' => 12, 'throttled' => 2]],
            [],
            $now,
        );

        $chart = $page->quotaChart();
        self::assertSame(1080, $chart['max']);
        self::assertSame('0,150 3.1,136.1 6.3,108.3 9.4,80.6', $chart['line']);
        self::assertSame(11.1, $chart['cap']);
        self::assertSame(150.0, $chart['exhaustedX']);

        $minutes = $page->minuteChart();
        self::assertSame(14, $minutes['max']);
        self::assertTrue($minutes['bars'][1]['over']);
        self::assertSame(1, $page->throttledMinutes());
    }
}
