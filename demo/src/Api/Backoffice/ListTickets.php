<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Backoffice;

use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Demo\Model\TicketView;

#[Endpoint('GET', '/backoffice/tickets')]
final readonly class ListTickets
{
    /**
     * @return list<TicketView>
     */
    public function __invoke(#[Query] string $status = 'open'): array
    {
        return [
            new TicketView('tkt_311', 'Parcel arrived damaged', 'usr_104', $status, 3),
            new TicketView('tkt_312', 'Coupon WELCOME15 refused', 'usr_212', $status, 1),
            new TicketView('tkt_315', 'Where is my refund?', 'usr_388', $status, 5),
        ];
    }
}
