<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\Models\Model;
use Tollbooth\Dashboard\Models\ModelCatalog;
use Tollbooth\Dashboard\Models\ModelDiagram;
use Tollbooth\Dashboard\Models\ModelPreview;

final class ModelsTest extends TestCase
{
    public function testModelsAreSortedIntoOutputsInputsAndEnums(): void
    {
        $catalog = self::catalog();

        self::assertSame(['Money', 'OrderLineView', 'OrderView'], self::names($catalog->ofKind(Model::OUTPUT)));
        self::assertSame(['CreateOrderInput'], self::names($catalog->ofKind(Model::INPUT)));
        self::assertSame(['OrderStatus'], self::names($catalog->ofKind(Model::ENUM)));
        self::assertSame(['pending', 'paid'], $catalog->get('OrderStatus')?->values);
        self::assertSame('orders', $catalog->get('OrderView')?->resourceType);
    }

    public function testModelsKnowTheirReferencesAndWhoUsesThem(): void
    {
        $catalog = self::catalog();
        $order = $catalog->get('OrderView');
        self::assertNotNull($order);

        self::assertSame(['OrderStatus', 'OrderLineView', 'Money'], $order->references());
        self::assertSame(2, $catalog->get('Money')?->uses());
        self::assertSame(
            [['get_order', '200'], ['create_order', '201']],
            array_map(static fn (array $usage): array => [$usage['endpoint']->name, $usage['role']], $order->usages),
        );
        self::assertSame([['create_order', 'body']], array_map(static fn (array $usage): array => [$usage['endpoint']->name, $usage['role']], $catalog->get('CreateOrderInput')->usages ?? []));
        self::assertSame('OrderView', $catalog->first()?->name);

        $field = $order->fields[1];
        self::assertSame(['lines', 'OrderLineView[]', 'OrderLineView', true, false, true], [$field->name, $field->type, $field->ref, $field->list, $field->nullable, $field->required]);
    }

    public function testTheNeighbourhoodStopsAtTheRequestedDepth(): void
    {
        $catalog = self::catalog();

        self::assertSame(['OrderView' => ['OrderStatus', 'OrderLineView', 'Money'], 'OrderStatus' => [], 'OrderLineView' => [], 'Money' => []], $catalog->neighbourhood('OrderView', 1));
        self::assertSame(['Money'], $catalog->neighbourhood('OrderView', null)['OrderLineView']);
    }

    public function testTheDiagramPutsTheModelInTheMiddleAndItsReferencesOnBothSides(): void
    {
        $diagram = ModelDiagram::build(self::catalog(), 'OrderView', 2);

        self::assertSame(310, $diagram->cards['OrderView']['x']);
        self::assertTrue($diagram->cards['OrderView']['selected']);
        self::assertSame(640, $diagram->cards['OrderStatus']['x']);
        self::assertSame(0, $diagram->cards['OrderLineView']['x']);
        self::assertSame(640, $diagram->cards['Money']['x']);
        self::assertSame(860, $diagram->width);
        self::assertSame(
            [['status', true], ['lines[]', true], ['total', true]],
            array_map(static fn (array $link): array => [$link['label'], $link['direct']], $diagram->links),
        );
        self::assertSame('M550 53 H595 V20 H640', $diagram->links[0]['path']);
    }

    public function testPreviewsFollowTheChosenConvention(): void
    {
        $catalog = self::catalog();
        $order = $catalog->get('OrderView');
        self::assertNotNull($order);

        self::assertJsonStringEqualsJsonString(
            '{"data": {"type": "orders", "id": "42", "attributes": {"status": "pending", "lines": [{"unit_price": {"amount": 1}}], "total": {"amount": 1}, "created_at": "2026-11-25T09:41:12+00:00"}}}',
            ModelPreview::render(self::document(), $order, 'json_api', 'snake_case'),
        );
        self::assertJsonStringEqualsJsonString(
            '{"id": "42", "status": "pending", "lines": [{"unitPrice": {"amount": 1}}], "total": {"amount": 1}, "createdAt": "2026-11-25T09:41:12+00:00"}',
            ModelPreview::render(self::document(), $order, 'plain', 'camel_case'),
        );

        self::assertSame('stockMovementId', ModelPreview::recaseKey('stock_movement_id', 'camel_case'));
        self::assertSame('stock_movement_id', ModelPreview::recaseKey('stockMovementId', 'snake_case'));
    }

    /**
     * @param list<Model> $models
     *
     * @return list<string>
     */
    private static function names(array $models): array
    {
        return array_map(static fn (Model $model): string => $model->name, $models);
    }

    private static function catalog(): ModelCatalog
    {
        $endpoint = static fn (string $name, string $method): EndpointMetadata => new EndpointMetadata($name, $method, '/orders', 200, 's', self::class, __FILE__);

        return new ModelCatalog(self::document(), ['get_order' => $endpoint('get_order', 'GET'), 'create_order' => $endpoint('create_order', 'POST')]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        $resource = ['content' => ['application/vnd.api+json' => ['schema' => ['properties' => ['data' => ['properties' => [
            'type' => ['type' => 'string', 'const' => 'orders'],
            'id' => ['type' => 'string'],
            'attributes' => ['$ref' => '#/components/schemas/OrderViewAttributes'],
        ]]]]]]];

        return [
            'paths' => ['/orders' => [
                'get' => ['operationId' => 'get_order', 'responses' => ['200' => $resource, '404' => ['$ref' => '#/components/responses/NotFound']]],
                'post' => ['operationId' => 'create_order', 'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CreateOrderInput']]]], 'responses' => ['201' => $resource]],
            ]],
            'components' => ['schemas' => [
                'CreateOrderInput' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string']]],
                'Money' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'integer']]],
                'OrderLineView' => ['type' => 'object', 'properties' => ['unit_price' => ['$ref' => '#/components/schemas/Money']]],
                'OrderViewAttributes' => ['type' => 'object', 'required' => ['lines'], 'properties' => [
                    'status' => ['title' => 'OrderStatus', 'type' => 'string', 'enum' => ['pending', 'paid']],
                    'lines' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OrderLineView']],
                    'total' => ['$ref' => '#/components/schemas/Money'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ]],
                'Problem' => ['type' => 'object'],
            ]],
        ];
    }
}
