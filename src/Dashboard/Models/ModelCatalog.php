<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Models;

use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\Endpoint\Schemas;

/**
 * @internal
 */
final class ModelCatalog
{
    private const array PROBLEMS = ['Problem', 'ValidationProblem', 'RateLimitProblem'];

    /**
     * @var array<string, Model>
     */
    private array $models = [];

    private Schemas $schemas;

    /**
     * @param array<mixed>                    $document
     * @param array<string, EndpointMetadata> $endpoints by operation id
     */
    public function __construct(array $document, array $endpoints = [])
    {
        $this->schemas = new Schemas($document);
        $components = \is_array($document['components'] ?? null) && \is_array($document['components']['schemas'] ?? null) ? $document['components']['schemas'] : [];

        foreach ($components as $key => $schema) {
            $key = (string) $key;
            if (\in_array($key, self::PROBLEMS, true) || !\is_array($schema)) {
                continue;
            }
            $name = self::display($key);
            $this->models[$name] ??= new Model($name, Model::OUTPUT, $this->fields($schema), [], $schema);
        }
        foreach ($components as $schema) {
            $this->collectEnums($schema);
        }
        $this->collectResourceTypes($document['paths'] ?? null);

        $kinds = [];
        foreach (\is_array($document['paths'] ?? null) ? $document['paths'] : [] as $operations) {
            foreach (\is_array($operations) ? $operations : [] as $operation) {
                if (!\is_array($operation)) {
                    continue;
                }
                $this->collectEnums($operation['parameters'] ?? null);
                $endpoint = \is_string($operation['operationId'] ?? null) ? $endpoints[$operation['operationId']] ?? null : null;
                foreach ($this->reach($operation['requestBody'] ?? null) as $name) {
                    $kinds[$name][Model::INPUT] = true;
                    $this->use($name, $endpoint, 'body');
                }
                foreach (\is_array($operation['responses'] ?? null) ? $operation['responses'] : [] as $status => $response) {
                    if ((int) $status >= 400) {
                        continue;
                    }
                    foreach ($this->reach($response) as $name) {
                        $kinds[$name][Model::OUTPUT] = true;
                        $this->use($name, $endpoint, (string) $status);
                    }
                }
            }
        }

        foreach ($this->models as $name => $model) {
            if (Model::ENUM !== $model->kind && isset($kinds[$name][Model::INPUT]) && !isset($kinds[$name][Model::OUTPUT])) {
                $model->kind = Model::INPUT;
            }
            foreach ($model->references() as $reference) {
                if (isset($this->models[$reference]) && $reference !== $name) {
                    $this->models[$reference]->referencedBy[$name] = true;
                }
            }
        }
        ksort($this->models);
    }

    public function get(string $name): ?Model
    {
        return $this->models[$name] ?? null;
    }

    /**
     * @return array<string, Model>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * @param Model::* $kind
     *
     * @return list<Model>
     */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->models, static fn (Model $model): bool => $model->kind === $kind));
    }

    public function count(): int
    {
        return \count($this->models);
    }

    public function first(): ?Model
    {
        $outputs = $this->ofKind(Model::OUTPUT);
        usort($outputs, static fn (Model $a, Model $b): int => [$a->uses() > 0, \count($b->usages), $a->name] <=> [$b->uses() > 0, \count($a->usages), $b->name]);

        return $outputs[0] ?? array_values($this->models)[0] ?? null;
    }

    /**
     * @return array<string, list<string>> referenced model names by model, within the given depth
     */
    public function neighbourhood(string $root, ?int $depth): array
    {
        $graph = [];
        $queue = [[$root, 0]];
        $seen = [$root => true];
        while ([] !== $queue) {
            [$name, $level] = array_shift($queue);
            $references = array_values(array_filter(isset($this->models[$name]) ? $this->models[$name]->references() : [], fn (string $reference): bool => isset($this->models[$reference])));
            $graph[$name] = null !== $depth && $level >= $depth ? [] : $references;
            foreach ($graph[$name] as $reference) {
                if (!isset($seen[$reference])) {
                    $seen[$reference] = true;
                    $queue[] = [$reference, $level + 1];
                }
            }
        }

        return $graph;
    }

    private function use(string $name, ?EndpointMetadata $endpoint, string $role): void
    {
        if (null === $endpoint || !isset($this->models[$name])) {
            return;
        }
        foreach ($this->models[$name]->usages as $usage) {
            if ($usage['endpoint'] === $endpoint && $usage['role'] === $role) {
                return;
            }
        }
        $this->models[$name]->usages[] = ['endpoint' => $endpoint, 'role' => $role];
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<ModelField>
     */
    private function fields(array $schema): array
    {
        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $fields = [];
        foreach (\is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $property => $definition) {
            [$unwrapped, $nullable] = $this->schemas->unwrap($definition);
            $list = \is_array($unwrapped['items'] ?? null);
            $target = $list ? $this->schemas->unwrap($unwrapped['items'])[0] : $unwrapped;
            $ref = Schemas::name($target) ?? (\is_string($target['title'] ?? null) && \is_array($target['enum'] ?? null) ? $target['title'] : null);
            $isRequired = \in_array($property, $required, true);
            $fields[] = new ModelField((string) $property, $this->schemas->type($definition), $ref, $list, $nullable, $isRequired, $this->schemas->rules($definition, $isRequired, false));
        }

        return $fields;
    }

    private function collectEnums(mixed $node): void
    {
        if (!\is_array($node)) {
            return;
        }
        if (\is_string($node['title'] ?? null) && \is_array($node['enum'] ?? null) && !isset($this->models[$node['title']])) {
            $values = array_values(array_filter($node['enum'], static fn (mixed $value): bool => \is_int($value) || \is_string($value)));
            $this->models[$node['title']] = new Model($node['title'], Model::ENUM, [], $values, $node);
        }
        foreach ($node as $child) {
            $this->collectEnums($child);
        }
    }

    private function collectResourceTypes(mixed $node): void
    {
        if (!\is_array($node)) {
            return;
        }
        $properties = \is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $type = \is_array($properties['type'] ?? null) ? $properties['type']['const'] ?? null : null;
        $model = Schemas::name($properties['attributes'] ?? null);
        if (\is_string($type) && null !== $model && isset($this->models[$model])) {
            $this->models[$model]->resourceType = $type;
        }
        foreach ($node as $child) {
            $this->collectResourceTypes($child);
        }
    }

    /**
     * @param array<string, true> $visited
     *
     * @return list<string>
     */
    private function reach(mixed $node, array &$visited = []): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $names = [];
        $ref = $node['$ref'] ?? null;
        if (\is_string($ref) && str_starts_with($ref, '#/components/schemas/')) {
            $key = substr($ref, \strlen('#/components/schemas/'));
            $name = self::display($key);
            if (isset($visited[$name])) {
                return [];
            }
            $visited[$name] = true;

            return [$name, ...$this->reach($this->schemas->resolve($node), $visited)];
        }
        if (\is_string($ref)) {
            return $this->reach($this->schemas->resolve($node), $visited);
        }
        if (\is_string($node['title'] ?? null) && \is_array($node['enum'] ?? null) && !isset($visited[$node['title']])) {
            $visited[$node['title']] = true;
            $names[] = $node['title'];
        }
        foreach ($node as $child) {
            $names = [...$names, ...$this->reach($child, $visited)];
        }

        return $names;
    }

    private static function display(string $key): string
    {
        return str_ends_with($key, 'Attributes') && \strlen($key) > 10 ? substr($key, 0, -10) : $key;
    }
}
