<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

/**
 * @internal
 */
final readonly class PipelineStep
{
    /**
     * @param list<string|array{key: string, parameters: array<string, string|int>}> $lines
     * @param list<int>                                                              $codes
     */
    public function __construct(
        public string $key,
        public array $lines,
        public array $codes,
        public ?float $share = null,
        public bool $success = false,
        public bool $off = false,
    ) {
    }
}
