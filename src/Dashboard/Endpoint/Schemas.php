<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

/**
 * @internal
 */
final readonly class Schemas
{
    private const string PREFIX = '#/components/';

    /**
     * @param array<mixed> $document
     */
    public function __construct(
        private array $document,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function resolve(mixed $node, int $depth = 0): array
    {
        if (!\is_array($node)) {
            return [];
        }
        $ref = $node['$ref'] ?? null;
        if (!\is_string($ref) || !str_starts_with($ref, self::PREFIX) || $depth > 10) {
            return $node;
        }

        $target = $this->document['components'] ?? null;
        foreach (explode('/', substr($ref, \strlen(self::PREFIX))) as $segment) {
            $target = \is_array($target) ? ($target[$segment] ?? null) : null;
        }

        return $this->resolve($target, $depth + 1);
    }

    /**
     * @return array{array<mixed>, bool}
     */
    public function unwrap(mixed $node): array
    {
        if (!\is_array($node)) {
            return [[], false];
        }
        foreach (['anyOf', 'oneOf'] as $combinator) {
            $parts = $node[$combinator] ?? null;
            if (\is_array($parts) && 2 === \count($parts)) {
                $others = array_values(array_filter($parts, static fn (mixed $part): bool => !\is_array($part) || 'null' !== ($part['type'] ?? null)));
                if (1 === \count($others)) {
                    return [\is_array($others[0]) ? $others[0] : [], true];
                }
            }
        }
        $type = $node['type'] ?? null;
        if (\is_array($type) && \in_array('null', $type, true)) {
            $rest = array_values(array_filter($type, static fn (mixed $item): bool => 'null' !== $item));
            $node['type'] = 1 === \count($rest) ? $rest[0] : $rest;

            return [$node, true];
        }

        return [$node, false];
    }

    public static function name(mixed $node): ?string
    {
        if (!\is_array($node) || !\is_string($node['$ref'] ?? null)) {
            return null;
        }
        $name = substr($node['$ref'], (int) strrpos($node['$ref'], '/') + 1);

        return str_ends_with($name, 'Attributes') ? substr($name, 0, -10) : $name;
    }

    public function type(mixed $node): string
    {
        [$schema, $nullable] = $this->unwrap($node);
        $prefix = $nullable ? '?' : '';
        $name = self::name($schema);
        if (null !== $name) {
            return $prefix.$name;
        }
        if (\is_array($schema['items'] ?? null)) {
            return $prefix.$this->type($schema['items']).'[]';
        }
        if (\is_string($schema['title'] ?? null) && \is_array($schema['enum'] ?? null)) {
            return $prefix.$schema['title'];
        }
        if (isset($schema['const'])) {
            return \is_scalar($schema['const']) ? (string) json_encode($schema['const'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : 'const';
        }
        $type = $schema['type'] ?? null;
        $type = \is_array($type) ? implode('|', array_filter($type, 'is_string')) : (\is_string($type) ? $type : 'mixed');
        $format = $schema['format'] ?? null;
        if ('string' === $type && \in_array($format, ['date-time', 'date', 'time'], true)) {
            $type = str_replace('-', '', $format);
        } elseif (\is_string($format)) {
            $type .= '<'.$format.'>';
        }

        return $prefix.match ($type) {
            'integer' => 'int',
            'boolean' => 'bool',
            'number' => 'float',
            default => $type,
        };
    }

    /**
     * @return list<string>
     */
    public function rules(mixed $node, bool $required = false, bool $withDefault = true): array
    {
        [$schema] = $this->unwrap($node);
        $resolved = $this->resolve($schema);
        $rules = $required ? ['required'] : [];

        $min = $resolved['minLength'] ?? null;
        $max = $resolved['maxLength'] ?? null;
        if (\is_int($min) && \is_int($max)) {
            $rules[] = $min === $max ? 'length '.$min : 'length '.$min.'–'.$max;
        } elseif (\is_int($min)) {
            $rules[] = $min <= 1 ? 'not blank' : 'length ≥ '.$min;
        } elseif (\is_int($max)) {
            $rules[] = 'length ≤ '.$max;
        }

        foreach (['exclusiveMinimum' => '> ', 'minimum' => '≥ ', 'exclusiveMaximum' => '< ', 'maximum' => '≤ '] as $keyword => $symbol) {
            if (\is_int($resolved[$keyword] ?? null) || \is_float($resolved[$keyword] ?? null)) {
                $rules[] = $symbol.$resolved[$keyword];
            }
        }
        foreach (['minItems' => 'min ', 'maxItems' => 'max '] as $keyword => $label) {
            if (\is_int($resolved[$keyword] ?? null)) {
                $rules[] = $label.$resolved[$keyword];
            }
        }
        if (\is_string($resolved['pattern'] ?? null)) {
            $rules[] = '/'.$resolved['pattern'].'/';
        }
        if (\is_array($resolved['enum'] ?? null)) {
            $values = array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : 'null', $resolved['enum']);
            $rules[] = implode(' | ', \array_slice($values, 0, 4)).(\count($values) > 4 ? ' | …' : '');
        }
        if ($withDefault && \array_key_exists('default', $resolved) && \is_scalar($resolved['default'])) {
            $rules[] = 'default '.json_encode($resolved['default'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        return $rules;
    }
}
