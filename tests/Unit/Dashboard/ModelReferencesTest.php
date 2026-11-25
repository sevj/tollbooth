<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tollbooth\Dashboard\Map\ModelReferences;
use Tollbooth\Dashboard\Map\Reference;

final class ModelReferencesTest extends TestCase
{
    public function testResourcesAreLinkedByEmbeddedModelsAndByIds(): void
    {
        $references = new ModelReferences(self::document(), [
            'get_order' => 'orders',
            'ship_order' => 'orders',
            'get_shipment' => 'shipments',
            'get_product' => 'products',
            'get_customer' => 'customers',
        ]);

        self::assertEquals([
            new Reference('orders', 'customers', 'customer'),
            new Reference('orders', 'products', 'product'),
            new Reference('shipments', 'orders', 'order'),
        ], $references->references());
    }

    public function testModelsAreCountedOnceWithoutProblemSchemas(): void
    {
        $references = new ModelReferences(self::document(), []);

        self::assertSame(5, $references->modelCount());
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        $ok = static fn (string $schema): array => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]]], '404' => ['$ref' => '#/components/responses/NotFound']]];

        return [
            'paths' => [
                '/orders/{id}' => ['get' => ['operationId' => 'get_order', ...$ok('OrderView')]],
                '/orders/{id}/shipments' => ['post' => ['operationId' => 'ship_order', ...$ok('ShipmentView')]],
                '/shipments/{id}' => ['get' => ['operationId' => 'get_shipment', ...$ok('ShipmentView')]],
                '/products/{id}' => ['get' => ['operationId' => 'get_product', 'responses' => ['200' => ['content' => ['application/vnd.api+json' => ['schema' => ['properties' => ['data' => ['properties' => ['attributes' => ['$ref' => '#/components/schemas/ProductViewAttributes']]]]]]]]]]],
                '/customers/{id}' => ['get' => ['operationId' => 'get_customer', ...$ok('CustomerView')]],
            ],
            'components' => ['schemas' => [
                'OrderView' => ['properties' => [
                    'customer_id' => ['type' => 'string'],
                    'lines' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OrderLineView']],
                ]],
                'OrderLineView' => ['properties' => ['productId' => ['type' => 'string']]],
                'ShipmentView' => ['properties' => ['order_id' => ['type' => 'string']]],
                'ProductViewAttributes' => ['properties' => ['name' => ['type' => 'string']]],
                'CustomerView' => ['properties' => ['name' => ['type' => 'string']]],
                'Problem' => ['properties' => ['title' => ['type' => 'string']]],
            ]],
        ];
    }
}
