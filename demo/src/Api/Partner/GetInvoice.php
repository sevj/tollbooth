<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Partner;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\InvoiceView;
use Tollbooth\Demo\Model\Money;

#[Endpoint('GET', '/invoices/{id}')]
final readonly class GetInvoice
{
    public function __invoke(string $id): InvoiceView
    {
        if (1 !== preg_match('/^inv_(\d{4})(\d{2})$/', $id, $matches)) {
            throw new NotFoundHttpException(\sprintf('Invoice "%s" does not exist.', $id));
        }

        return new InvoiceView($id, $matches[1].'-'.$matches[2], new Money(120000 + (int) $matches[2] * 3100), (int) $matches[2] < 11);
    }
}
