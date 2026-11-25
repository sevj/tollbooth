<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Changes;

use PHPUnit\Framework\TestCase;
use Tollbooth\Changes\Impact;
use Tollbooth\Monitoring\CallReaderInterface;

final class ImpactTest extends TestCase
{
    public function testAnUnreachableMonitoringDatabaseLeavesTheImpactUnknown(): void
    {
        $calls = $this->createStub(CallReaderInterface::class);
        $calls->method('totals')->willThrowException(new \RuntimeException('Connection refused'));

        self::assertNull((new Impact($calls))->of(['op'], ['op' => 'tollbooth_get_thing']));
    }
}
