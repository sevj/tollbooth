<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

/**
 * @internal
 */
final class OperationReader
{
    private const int JSON = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<mixed> $document
     */
    public static function read(array $document, string $operationId): ?Operation
    {
        $operation = self::find($document, $operationId);
        if (null === $operation) {
            return null;
        }

        $schemas = new Schemas($document);
        $examples = new ExampleGenerator($schemas);

        $parameters = [];
        foreach (\is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [] as $parameter) {
            if (!\is_array($parameter) || !\is_string($parameter['name'] ?? null)) {
                continue;
            }
            $in = \is_string($parameter['in'] ?? null) ? $parameter['in'] : 'query';
            $parameters[] = new Field($parameter['name'], $schemas->type($parameter['schema'] ?? []), $schemas->rules($parameter['schema'] ?? [], true === ($parameter['required'] ?? false)), in: $in);
        }

        $bodySchema = self::content($schemas->resolve($operation['requestBody'] ?? null))[1];
        $bodyModel = null === $bodySchema ? null : Schemas::name($bodySchema) ?? Schemas::name($bodySchema['items'] ?? null);
        $body = null === $bodySchema ? [] : self::fields($schemas, $bodySchema);

        $responses = [];
        $successStatus = null;
        $contentType = null;
        $responseExample = null;
        $responseModel = null;
        foreach (\is_array($operation['responses'] ?? null) ? $operation['responses'] : [] as $status => $response) {
            $status = (int) $status;
            $problemName = Schemas::name(\is_array($response) ? $response : null);
            $response = $schemas->resolve($response);
            [$type, $schema] = self::content($response);
            $description = \is_string($response['description'] ?? null) ? $response['description'] : '';
            if ($status >= 400) {
                $responses[] = new ResponseRow($status, $problemName ?? Schemas::name($schema), $description, true);
                continue;
            }

            $model = null === $schema ? null : self::payloadModel($schemas, $schema);
            $responses[] = new ResponseRow($status, $model, $description);
            if (null === $successStatus) {
                $successStatus = $status;
                $responseModel = $model;
                $contentType = $type;
                $responseExample = null === $schema ? null : (string) json_encode($examples->example($schema), self::JSON);
            }
        }

        return new Operation(
            $parameters,
            $bodyModel,
            $body,
            $responses,
            null === $bodySchema ? null : (string) json_encode($examples->example($bodySchema), self::JSON),
            $contentType,
            $responseExample,
            $successStatus,
            $responseModel,
        );
    }

    /**
     * @param array<mixed> $document
     *
     * @return array<mixed>|null
     */
    private static function find(array $document, string $operationId): ?array
    {
        foreach (\is_array($document['paths'] ?? null) ? $document['paths'] : [] as $operations) {
            foreach (\is_array($operations) ? $operations : [] as $operation) {
                if (\is_array($operation) && $operationId === ($operation['operationId'] ?? null)) {
                    return $operation;
                }
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $container
     *
     * @return array{?string, ?array<mixed>}
     */
    private static function content(array $container): array
    {
        $content = $container['content'] ?? null;
        if (!\is_array($content) || [] === $content) {
            return [null, null];
        }
        $type = (string) array_key_first($content);
        $schema = \is_array($content[$type]) && \is_array($content[$type]['schema'] ?? null) ? $content[$type]['schema'] : null;

        return [$type, $schema];
    }

    /**
     * @param array<mixed> $schema
     */
    private static function payloadModel(Schemas $schemas, array $schema): ?string
    {
        $name = Schemas::name($schema);
        if (null !== $name) {
            return $name;
        }

        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach (['data', 'meta'] as $member) {
            if (!\is_array($properties[$member] ?? null)) {
                continue;
            }
            $inner = $properties[$member];
            if (\is_array($inner['items'] ?? null)) {
                $item = self::payloadModel($schemas, $inner['items']);

                return null === $item ? null : $item.'[]';
            }

            return self::payloadModel($schemas, $inner);
        }
        if (\is_array($properties['attributes'] ?? null)) {
            return Schemas::name($properties['attributes']);
        }
        if (\is_array($schema['items'] ?? null)) {
            $item = self::payloadModel($schemas, $schema['items']);

            return null === $item ? null : $item.'[]';
        }

        return null;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<Field>
     */
    private static function fields(Schemas $schemas, array $schema, string $prefix = '', int $depth = 0): array
    {
        $resolved = $schemas->resolve($schemas->unwrap($schema)[0]);
        if (\is_array($resolved['items'] ?? null) && '' === $prefix) {
            return self::fields($schemas, $resolved['items'], '[].', $depth);
        }

        $required = \is_array($resolved['required'] ?? null) ? $resolved['required'] : [];
        $fields = [];
        foreach (\is_array($resolved['properties'] ?? null) ? $resolved['properties'] : [] as $name => $definition) {
            $name = (string) $name;
            $unwrapped = $schemas->unwrap($definition)[0];
            $model = Schemas::name($unwrapped) ?? Schemas::name($unwrapped['items'] ?? null);
            $fields[] = new Field($name, $schemas->type($definition), $schemas->rules($definition, \in_array($name, $required, true), false), $prefix, model: $model);

            if (null !== $model && $depth < 1) {
                $list = \is_array($unwrapped['items'] ?? null);
                $nested = $schemas->resolve($list ? $unwrapped['items'] : $unwrapped);
                if (\is_array($nested['properties'] ?? null)) {
                    $fields = [...$fields, ...self::fields($schemas, $nested, $prefix.$name.($list ? '[].' : '.'), $depth + 1)];
                }
            }
        }

        return $fields;
    }
}
