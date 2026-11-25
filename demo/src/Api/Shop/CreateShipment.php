<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CreateShipmentInput;
use Tollbooth\Demo\Model\OrderStatus;
use Tollbooth\Demo\Model\ShipmentView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/orders/{id}/shipments', status: 201)]
final readonly class CreateShipment
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id, #[Body] CreateShipmentInput $input): ShipmentView
    {
        $order = $this->store->order($id);
        if (OrderStatus::Paid !== $order->status && OrderStatus::Shipped !== $order->status) {
            throw new ConflictHttpException(\sprintf('Order "%s" is %s: only paid orders ship.', $id, $order->status->value));
        }

        return new ShipmentView('shp_'.substr($id, 4), $id, $input->carrier, strtoupper(bin2hex(random_bytes(6))), new \DateTimeImmutable());
    }
}
