<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Metadata\EndpointMetadataFactory;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Core\Output\ResourceTypeResolver;
use Tollbooth\Docs\OpenApiGenerator;

final class OpenApiResponsesTest extends TestCase
{
    public function testANullableResultAlsoDocumentsTheEmptyAnswer(): void
    {
        $registry = new EndpointRegistry([(new EndpointMetadataFactory())->create('app.latest', new \ReflectionClass(LatestNote::class))->toArray()]);
        $paths = (array) ((new OpenApiGenerator($registry, new ResourceTypeResolver(), etag: false))->generate('default')['paths'] ?? []);
        $operation = (array) ((array) ($paths['/notes/latest'] ?? []))['get'];
        $responses = (array) $operation['responses'];

        self::assertSame(['200', '204', '401'], array_map(strval(...), array_keys($responses)));
        self::assertSame(['$ref' => '#/components/schemas/LatestNoteView'], ((array) ((array) ((array) $responses['200'])['content'])['application/json'])['schema']);
    }
}

#[Endpoint('GET', '/notes/latest')]
final class LatestNote
{
    public function __invoke(): ?LatestNoteView
    {
        return 1 === random_int(0, 1) ? new LatestNoteView() : null;
    }
}

final readonly class LatestNoteView
{
    public function __construct(public string $title = '')
    {
    }
}
