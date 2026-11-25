<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\InvoiceView;
use Tollbooth\Demo\Model\Money;

#[Endpoint('GET', '/invoices')]
final readonly class ListInvoices
{
    /**
     * @return list<InvoiceView>
     */
    public function __invoke(#[Query] int $year = 2026): array
    {
        return array_map(static fn (int $month): InvoiceView => new InvoiceView(\sprintf('inv_%d%02d', $year, $month), \sprintf('%d-%02d', $year, $month), new Money(120000 + $month * 3100), $month < 11), range(1, 11));
    }
}
