<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\Map\ResourceGrouper;

final class ResourceGrouperTest extends TestCase
{
    public function testEndpointsAreGroupedByTheirFirstSegmentOnceTheGroupPrefixIsRemoved(): void
    {
        $grouped = ResourceGrouper::group([
            self::endpoint('DELETE', '/v2/orders/{id}'),
            self::endpoint('GET', '/v2/stock-movements'),
            self::endpoint('POST', '/v2/orders'),
            self::endpoint('GET', '/v2/orders'),
            self::endpoint('GET', '/v2'),
        ], ['/v2']);

        self::assertSame(['/', 'orders', 'stock-movements'], array_keys($grouped));
        self::assertSame(
            ['GET /v2/orders', 'POST /v2/orders', 'DELETE /v2/orders/{id}'],
            array_map(static fn (EndpointMetadata $endpoint): string => $endpoint->method.' '.$endpoint->path, $grouped['orders']),
        );
        self::assertSame('Stock movements', ResourceGrouper::title('stock-movements'));
    }

    public function testGroupsMatchedByPathLoseThatPathToo(): void
    {
        self::assertSame('/tickets/{id}', ResourceGrouper::localPath('/backoffice/tickets/{id}', ['/backoffice']));
        self::assertSame('/backoffices', ResourceGrouper::localPath('/backoffices', ['/backoffice']));
        self::assertSame('tickets', ResourceGrouper::resourceOf('/tickets/{id}'));
        self::assertSame('/', ResourceGrouper::resourceOf('/{id}'));
    }

    private static function endpoint(string $method, string $path): EndpointMetadata
    {
        return new EndpointMetadata(strtolower($method.$path), $method, $path, 200, 'service', self::class, __FILE__);
    }
}
