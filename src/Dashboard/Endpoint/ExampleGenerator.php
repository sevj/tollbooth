<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

/**
 * @internal
 */
final readonly class ExampleGenerator
{
    private const int MAX_DEPTH = 6;

    public function __construct(
        private Schemas $schemas,
    ) {
    }

    public function example(mixed $node, string $name = '', int $depth = 0): mixed
    {
        [$schema] = $this->schemas->unwrap($node);
        $schema = $this->schemas->resolve($schema);
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        if (\array_key_exists('const', $schema)) {
            return $schema['const'];
        }
        if (\array_key_exists('example', $schema)) {
            return $schema['example'];
        }
        if (\is_array($schema['enum'] ?? null) && [] !== $schema['enum']) {
            return $schema['enum'][0];
        }
        if (\array_key_exists('default', $schema) && !\in_array($schema['default'], [null, '', []], true)) {
            return $schema['default'];
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $combinator) {
            if (\is_array($schema[$combinator] ?? null)) {
                $merged = [];
                foreach ($schema[$combinator] as $part) {
                    $value = $this->example($part, $name, $depth + 1);
                    if (!\is_array($value)) {
                        return $value;
                    }
                    $merged = [...$merged, ...$value];
                    if ('allOf' !== $combinator) {
                        break;
                    }
                }

                return $merged;
            }
        }

        $type = $schema['type'] ?? (\is_array($schema['properties'] ?? null) ? 'object' : null);
        if (\is_array($type)) {
            $type = array_values(array_filter($type, static fn (mixed $item): bool => 'null' !== $item))[0] ?? null;
        }

        return match ($type) {
            'object' => $this->object($schema, $depth),
            'array' => $this->array($schema, $name, $depth),
            'integer' => self::number($schema, 1),
            'number' => self::number($schema, 9.99),
            'boolean' => true,
            'string' => $this->string($schema, $name),
            default => null,
        };
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array<string, mixed>|\stdClass
     */
    private function object(array $schema, int $depth): array|\stdClass
    {
        $object = [];
        foreach (\is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $property => $definition) {
            $object[(string) $property] = $this->example($definition, (string) $property, $depth + 1);
        }

        return [] === $object ? new \stdClass() : $object;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<mixed>
     */
    private function array(array $schema, string $name, int $depth): array
    {
        $count = max(1, \is_int($schema['minItems'] ?? null) ? $schema['minItems'] : 1);

        return array_fill(0, min($count, 3), $this->example($schema['items'] ?? [], $name, $depth + 1));
    }

    /**
     * @param array<mixed> $schema
     */
    private static function number(array $schema, int|float $fallback): int|float
    {
        foreach (['minimum', 'exclusiveMinimum'] as $keyword) {
            $bound = $schema[$keyword] ?? null;
            if (\is_int($bound) || \is_float($bound)) {
                $value = 'exclusiveMinimum' === $keyword ? $bound + 1 : $bound;

                return max($value, $fallback);
            }
        }

        return $fallback;
    }

    /**
     * @param array<mixed> $schema
     */
    private function string(array $schema, string $name): string
    {
        $value = match ($schema['format'] ?? null) {
            'date-time' => '2026-11-25T09:41:12+00:00',
            'date' => '2026-11-25',
            'time' => '09:41:12',
            'email' => 'ada@example.com',
            'uri', 'url' => 'https://example.com',
            'uuid' => '0193b6a2-7c1e-7d4a-9f2c-1a2b3c4d5e6f',
            default => null,
        };
        if (null !== $value) {
            return $value;
        }

        $value = 'id' === $name || str_ends_with($name, 'Id') || str_ends_with($name, '_id') ? '42' : 'string';
        $min = \is_int($schema['minLength'] ?? null) ? $schema['minLength'] : 0;
        $max = \is_int($schema['maxLength'] ?? null) ? $schema['maxLength'] : null;
        if (\strlen($value) < $min) {
            $value = str_pad($value, $min, 'x');
        }

        return null === $max ? $value : substr($value, 0, max(1, $max));
    }
}
