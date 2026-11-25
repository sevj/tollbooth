<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

use Tollbooth\Core\Metadata\EndpointMetadata;

/**
 * @internal
 */
final readonly class Attention
{
    public const string DEPRECATED = 'deprecated';
    public const string ERRORS = 'errors';
    public const string THROTTLING = 'throttling';
    public const string SLOW = 'slow';

    /**
     * @param self::*                                $kind
     * @param array<string, string|int|float>        $parameters
     * @param list<array{id: string, label: string}> $consumers
     */
    public function __construct(
        public string $kind,
        public EndpointMetadata $endpoint,
        public string $path,
        public string $message,
        public array $parameters = [],
        public array $consumers = [],
    ) {
    }
}
