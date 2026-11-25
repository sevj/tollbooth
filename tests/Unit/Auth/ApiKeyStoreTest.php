<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Auth;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Auth\Doctrine\DoctrineApiKeyStore;

final class ApiKeyStoreTest extends TestCase
{
    public function testAKeyRevokedElsewhereIsForgottenBetweenRequests(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $worker = new DoctrineApiKeyStore($connection);
        $worker->setup();
        $worker->save(new ApiKey('acme00000001', 'acme', 'h'));
        self::assertNotNull($worker->find('acme00000001'));

        (new DoctrineApiKeyStore($connection))->remove('acme00000001');
        self::assertNotNull($worker->find('acme00000001'), 'Lookups are memoized within a request.');

        $worker->reset();
        self::assertNull($worker->find('acme00000001'));
    }
}
