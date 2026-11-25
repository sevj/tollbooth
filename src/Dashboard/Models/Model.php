<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Models;

use Tollbooth\Core\Metadata\EndpointMetadata;

/**
 * @internal
 */
final class Model
{
    public const string OUTPUT = 'output';
    public const string INPUT = 'input';
    public const string ENUM = 'enum';

    /**
     * @var list<array{endpoint: EndpointMetadata, role: string}>
     */
    public array $usages = [];

    /**
     * @var array<string, true>
     */
    public array $referencedBy = [];

    public ?string $resourceType = null;

    /**
     * @param self::OUTPUT|self::INPUT|self::ENUM $kind
     * @param list<ModelField>                    $fields
     * @param list<int|string|null>               $values
     * @param array<mixed>                        $schema
     */
    public function __construct(
        public readonly string $name,
        public string $kind,
        public readonly array $fields = [],
        public readonly array $values = [],
        public readonly array $schema = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (ModelField $field): ?string => $field->ref, $this->fields))));
    }

    /**
     * @return list<string>
     */
    public function displayValues(): array
    {
        return array_map(static fn (int|string|null $value): string => (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $this->values);
    }

    public function uses(): int
    {
        return \count($this->referencedBy);
    }
}
