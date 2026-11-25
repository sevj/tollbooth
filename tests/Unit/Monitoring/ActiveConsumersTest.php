<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Monitoring;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;

final class ActiveConsumersTest extends TestCase
{
    public function testDistinctConsumersAreCountedPerBucketInOneQueryPerTable(): void
    {
        $storage = new DoctrineCallStorage(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $storage->setup();
        $from = 1_795_600_800;
        foreach ([[0, 'acme'], [60, 'acme'], [120, 'globex'], [3700, 'acme'], [7300, 'initech'], [7400, 'acme']] as [$offset, $consumer]) {
            $at = ($from + $offset) * 1000;
            $storage->record(new CallRecord(CallRecord::newId($at), $at, 'get_thing', 'shop', 'GET', '/things', 200, 10.0, $consumer, $consumer, 'api_key'));
        }
        $storage->record(new CallRecord(CallRecord::newId($from * 1000), $from * 1000, 'get_thing', 'other', 'GET', '/things', 200, 10.0, 'hooli', 'hooli', 'api_key'));

        self::assertSame([2, 1, 2], $storage->activeConsumers($from, $from + 3 * 3600, 3, new CallQuery(group: 'shop')));

        $storage->rollup($from + 3600 + 120);
        $storage->reset();
        self::assertSame([2, 1, 2], $storage->activeConsumers($from, $from + 3 * 3600, 3, new CallQuery(group: 'shop')), 'Rolled up minutes count the same.');
    }
}
