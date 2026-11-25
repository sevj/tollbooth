<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\RateLimit;
use Tollbooth\Demo\Model\ImportOrdersInput;
use Tollbooth\Demo\Model\ImportResult;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/orders/import', status: 202)]
#[RateLimit(imports: '20/hour')]
final readonly class ImportOrders
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(#[Body] ImportOrdersInput $input): ImportResult
    {
        $imported = 0;
        foreach ($input->orders as $order) {
            try {
                $this->store->createOrder($order);
                ++$imported;
            } catch (NotFoundHttpException) {
            }
        }

        return new ImportResult($imported, \count($input->orders) - $imported);
    }
}
