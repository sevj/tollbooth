<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

/**
 * @internal
 */
final readonly class Field
{
    /**
     * @param list<string> $rules
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $rules,
        public string $prefix = '',
        public ?string $in = null,
        public ?string $model = null,
    ) {
    }
}
