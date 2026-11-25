<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\RateLimit;
use Tollbooth\Core\Metadata\EndpointMetadataFactory;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Core\Output\ResourceTypeResolver;
use Tollbooth\Docs\OpenApiGenerator;

final class OpenApiLimitsTest extends TestCase
{
    public function testLimitsAreDocumentedOnlyWhenRateLimitingRuns(): void
    {
        $registry = new EndpointRegistry([(new EndpointMetadataFactory())->create('app.search', new \ReflectionClass(SearchEndpoint::class))->toArray()]);
        $document = static fn (bool $enforced): array => (array) (new OpenApiGenerator($registry, new ResourceTypeResolver(), limitsEnforced: $enforced))->generate('default');

        $on = self::operation($document(true));
        $off = self::operation($document(false));

        self::assertArrayHasKey('429', (array) $on['responses']);
        self::assertArrayHasKey('x-rate-limits', $on);
        self::assertArrayNotHasKey('429', (array) $off['responses']);
        self::assertArrayNotHasKey('x-rate-limits', $off);
    }

    /**
     * @param array<mixed> $document
     *
     * @return array<mixed>
     */
    private static function operation(array $document): array
    {
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertIsArray($paths['/search'] ?? null);
        self::assertIsArray($paths['/search']['get'] ?? null);

        return $paths['/search']['get'];
    }
}

#[Endpoint('GET', '/search')]
#[RateLimit('30/minute')]
final class SearchEndpoint
{
    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        return [];
    }
}
