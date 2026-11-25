<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

use Symfony\Component\String\Inflector\EnglishInflector;

/**
 * @internal
 */
final class ModelReferences
{
    private const string SCHEMA_PREFIX = '#/components/schemas/';
    private const int MAX_DEPTH = 3;

    /**
     * @var array<string, array<mixed>>
     */
    private array $schemas = [];

    /**
     * @var array<string, string>
     */
    private array $owners = [];

    /**
     * @var array<string, true>
     */
    private array $resources = [];

    /**
     * @param array<mixed>          $document
     * @param array<string, string> $resourceByOperation resource name by operation id
     */
    public function __construct(array $document, array $resourceByOperation)
    {
        $components = $document['components'] ?? null;
        $schemas = \is_array($components) && \is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        foreach ($schemas as $name => $schema) {
            if (\is_array($schema)) {
                $this->schemas[(string) $name] = $schema;
            }
        }
        $this->resources = array_fill_keys(array_values($resourceByOperation), true);

        $counts = [];
        $paths = \is_array($document['paths'] ?? null) ? $document['paths'] : [];
        foreach ($paths as $operations) {
            if (!\is_array($operations)) {
                continue;
            }
            foreach ($operations as $operation) {
                if (!\is_array($operation) || !\is_string($operation['operationId'] ?? null)) {
                    continue;
                }
                $resource = $resourceByOperation[$operation['operationId']] ?? null;
                if (null === $resource) {
                    continue;
                }
                $payloads = [$operation['requestBody'] ?? null];
                foreach (\is_array($operation['responses'] ?? null) ? $operation['responses'] : [] as $status => $response) {
                    if (str_starts_with((string) $status, '2')) {
                        $payloads[] = $response;
                    }
                }
                foreach ($payloads as $payload) {
                    foreach (self::refs($payload) as $schema) {
                        $counts[$schema][$resource] = ($counts[$schema][$resource] ?? 0) + 1;
                    }
                }
            }
        }

        $inflector = new EnglishInflector();
        foreach ($counts as $schema => $byResource) {
            $model = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', (string) $schema));
            $named = array_filter(
                array_keys($byResource),
                static fn (string $resource): bool => [] !== array_filter(
                    $inflector->singularize(str_replace('-', '_', $resource)),
                    static fn (string $singular): bool => str_starts_with($model, $singular),
                ),
            );
            if ([] !== $named) {
                $this->owners[$schema] = (string) reset($named);
                continue;
            }
            arsort($byResource);
            $this->owners[$schema] = (string) array_key_first($byResource);
        }
    }

    /**
     * @return list<Reference>
     */
    public function references(): array
    {
        $references = [];
        foreach ($this->owners as $schema => $owner) {
            foreach ($this->targets($schema, $owner, 0, [$schema => true]) as [$target, $label]) {
                $key = $owner < $target ? $owner.'|'.$target : $target.'|'.$owner;
                $references[$key] ??= new Reference($owner, $target, $label);
            }
        }

        return array_values($references);
    }

    public function modelCount(): int
    {
        $models = [];
        foreach (array_keys($this->schemas) as $name) {
            if (\in_array($name, ['Problem', 'ValidationProblem', 'RateLimitProblem'], true)) {
                continue;
            }
            $models[str_ends_with($name, 'Attributes') ? substr($name, 0, -10) : $name] = true;
        }

        return \count($models);
    }

    /**
     * @param array<string, true> $visited
     *
     * @return list<array{string, string}>
     */
    private function targets(string $schema, string $owner, int $depth, array $visited): array
    {
        $targets = [];
        foreach (self::properties($this->schemas[$schema] ?? []) as $property => $definition) {
            $resource = $this->resourceFromId($property);
            if (null !== $resource && $resource !== $owner) {
                $targets[] = [$resource, self::idBase($property) ?? $property];
            }

            $list = \is_array($definition['items'] ?? null);
            foreach (self::refs($definition) as $target) {
                $targetOwner = $this->owners[$target] ?? null;
                if (null !== $targetOwner && $targetOwner !== $owner) {
                    $targets[] = [$targetOwner, $property.($list ? '[]' : '')];
                } elseif (null === $targetOwner && $depth < self::MAX_DEPTH && !isset($visited[$target])) {
                    $targets = [...$targets, ...$this->targets($target, $owner, $depth + 1, $visited + [$target => true])];
                }
            }
        }

        return $targets;
    }

    private function resourceFromId(string $property): ?string
    {
        $base = self::idBase($property);
        if (null === $base) {
            return null;
        }

        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $base));
        $plurals = (new EnglishInflector())->pluralize($snake);
        foreach ([...$plurals, $snake] as $candidate) {
            foreach ([$candidate, str_replace('_', '-', $candidate)] as $resource) {
                if (isset($this->resources[$resource])) {
                    return $resource;
                }
            }
        }

        return null;
    }

    private static function idBase(string $property): ?string
    {
        return 1 === preg_match('/^(.+?)(?:Id|_id)$/', $property, $matches) ? $matches[1] : null;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array<string, array<mixed>>
     */
    private static function properties(array $schema): array
    {
        $properties = [];
        foreach (\is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $definition) {
            if (\is_array($definition)) {
                $properties[(string) $name] = $definition;
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $combinator) {
            foreach (\is_array($schema[$combinator] ?? null) ? $schema[$combinator] : [] as $part) {
                if (\is_array($part)) {
                    $properties += self::properties($part);
                }
            }
        }

        return $properties;
    }

    /**
     * @return list<string>
     */
    private static function refs(mixed $node): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $refs = [];
        foreach ($node as $key => $value) {
            if ('$ref' === $key && \is_string($value) && str_starts_with($value, self::SCHEMA_PREFIX)) {
                $refs[] = substr($value, \strlen(self::SCHEMA_PREFIX));
            } elseif (\is_array($value)) {
                $refs = [...$refs, ...self::refs($value)];
            }
        }

        return array_values(array_unique($refs));
    }
}
