<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Models;

/**
 * @internal
 */
final readonly class ModelField
{
    /**
     * @param list<string> $rules
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $ref = null,
        public bool $list = false,
        public bool $nullable = false,
        public bool $required = false,
        public array $rules = [],
    ) {
    }

    public function label(): string
    {
        return $this->name.($this->list ? '[]' : '');
    }
}
