<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tollbooth\Dashboard\Endpoint\Field;
use Tollbooth\Dashboard\Endpoint\OperationReader;
use Tollbooth\Dashboard\Endpoint\ResponseRow;

final class OperationReaderTest extends TestCase
{
    public function testFieldsAreFlattenedWithTheirTypesAndRules(): void
    {
        $operation = OperationReader::read(self::document(), 'create_order');
        self::assertNotNull($operation);

        self::assertSame('CreateOrderInput', $operation->bodyModel);
        self::assertSame([
            ['', 'customer_id', 'string', ['required', 'not blank']],
            ['', 'items', 'OrderItemInput[]', ['required', 'min 1', 'max 50']],
            ['items[].', 'quantity', 'int', ['> 0', '≤ 99']],
            ['', 'coupon', '?string', ['length 6–12', '/[A-Z0-9]+/']],
            ['', 'status', 'Status', ['pending | paid']],
        ], array_map(static fn (Field $field): array => [$field->prefix, $field->name, $field->type, $field->rules], $operation->body));
        self::assertSame(1, $operation->nestedFields());
        self::assertSame([['dry_run', 'query', 'bool', ['default false']]], array_map(static fn (Field $field): array => [$field->name, $field->in, $field->type, $field->rules], $operation->parameters));
    }

    public function testResponsesNameTheirModelOrProblem(): void
    {
        $operation = OperationReader::read(self::document(), 'create_order');
        self::assertNotNull($operation);

        self::assertSame(
            [[201, 'OrderView', false], [422, 'ValidationFailed', true]],
            array_map(static fn (ResponseRow $row): array => [$row->status, $row->model, $row->problem], $operation->responses),
        );
        self::assertSame(201, $operation->successStatus);
        self::assertSame('application/vnd.api+json', $operation->responseContentType);
        self::assertSame(['data' => ['type' => 'orders', 'id' => '42', 'attributes' => ['number' => 'string', 'created_at' => '2026-11-25T09:41:12+00:00']]], json_decode((string) $operation->responseExample, true));
        self::assertSame(['customer_id' => '42', 'items' => [['quantity' => 1]], 'coupon' => 'string', 'status' => 'pending'], json_decode((string) $operation->bodyExample, true));
    }

    public function testUnknownOperationsAreNull(): void
    {
        self::assertNull(OperationReader::read(self::document(), 'nope'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        return [
            'paths' => ['/orders' => ['post' => [
                'operationId' => 'create_order',
                'parameters' => [['name' => 'dry_run', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean', 'default' => false]]],
                'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CreateOrderInput']]]],
                'responses' => [
                    '201' => ['description' => 'Success.', 'content' => ['application/vnd.api+json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => [
                        'type' => ['type' => 'string', 'const' => 'orders'],
                        'id' => ['type' => 'string'],
                        'attributes' => ['$ref' => '#/components/schemas/OrderViewAttributes'],
                    ]]]]]]],
                    '422' => ['$ref' => '#/components/responses/ValidationFailed'],
                ],
            ]]],
            'components' => [
                'schemas' => [
                    'CreateOrderInput' => ['type' => 'object', 'required' => ['customer_id', 'items'], 'properties' => [
                        'customer_id' => ['type' => 'string', 'minLength' => 1, 'default' => ''],
                        'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['$ref' => '#/components/schemas/OrderItemInput']],
                        'coupon' => ['type' => ['string', 'null'], 'minLength' => 6, 'maxLength' => 12, 'pattern' => '[A-Z0-9]+'],
                        'status' => ['$ref' => '#/components/schemas/Status'],
                    ]],
                    'OrderItemInput' => ['type' => 'object', 'properties' => ['quantity' => ['type' => 'integer', 'exclusiveMinimum' => 0, 'maximum' => 99]]],
                    'Status' => ['type' => 'string', 'enum' => ['pending', 'paid']],
                    'OrderViewAttributes' => ['type' => 'object', 'properties' => ['number' => ['type' => 'string'], 'created_at' => ['type' => 'string', 'format' => 'date-time']]],
                    'ValidationProblem' => ['type' => 'object'],
                ],
                'responses' => ['ValidationFailed' => ['description' => 'The body does not pass validation.', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ValidationProblem']]]]],
            ],
        ];
    }
}
