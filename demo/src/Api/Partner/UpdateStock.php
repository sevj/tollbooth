<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\StockInput;
use Tollbooth\Demo\Model\StockView;
use Tollbooth\Demo\Store;

#[Endpoint('PATCH', '/products/{id}/stock')]
final readonly class UpdateStock
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id, #[Body] StockInput $input): StockView
    {
        $this->store->setStock($id, $input->stock);

        return new StockView($id, $this->store->stock($id), new \DateTimeImmutable());
    }
}
