<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Models;

use Tollbooth\Dashboard\Endpoint\ExampleGenerator;
use Tollbooth\Dashboard\Endpoint\Schemas;

/**
 * @internal
 */
final class ModelPreview
{
    public const array ENVELOPES = ['json_api', 'data', 'plain'];
    public const array CASES = ['snake_case', 'camel_case'];

    /**
     * @param array<mixed> $document
     */
    public static function render(array $document, Model $model, string $envelope, string $case): string
    {
        $example = (new ExampleGenerator(new Schemas($document)))->example($model->schema);
        if (Model::ENUM === $model->kind) {
            return (string) json_encode($model->values, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        $fields = self::recase(\is_array($example) ? $example : [], $case);
        if (null !== $model->resourceType && !\array_key_exists('id', $fields)) {
            $fields = ['id' => '42', ...$fields];
        }

        if (Model::OUTPUT !== $model->kind || 'plain' === $envelope) {
            $document = $fields;
        } elseif ('data' === $envelope) {
            $document = ['data' => $fields];
        } else {
            $id = $fields['id'] ?? '42';
            unset($fields['id']);
            $document = ['data' => [
                'type' => $model->resourceType ?? self::plural((string) preg_replace('/(View|Output)$/', '', $model->name)),
                'id' => \is_scalar($id) ? (string) $id : '42',
                'attributes' => [] === $fields ? new \stdClass() : $fields,
            ]];
        }

        return (string) json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function recase(array $data, string $case): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[\is_string($key) ? self::recaseKey($key, $case) : $key] = \is_array($value) ? self::recase($value, $case) : $value;
        }

        return $result;
    }

    public static function recaseKey(string $key, string $case): string
    {
        $words = array_map('strtolower', preg_split('/_|-|(?<=[a-z0-9])(?=[A-Z])/', $key) ?: [$key]);
        $words = array_values(array_filter($words, static fn (string $word): bool => '' !== $word));
        if ([] === $words) {
            return $key;
        }

        return 'camel_case' === $case
            ? $words[0].implode('', array_map('ucfirst', \array_slice($words, 1)))
            : implode('_', $words);
    }

    private static function plural(string $name): string
    {
        $snake = self::recaseKey($name, 'snake_case');

        return str_ends_with($snake, 's') ? $snake : (str_ends_with($snake, 'y') ? substr($snake, 0, -1).'ies' : $snake.'s');
    }
}
