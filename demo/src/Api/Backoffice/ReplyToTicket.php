<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Backoffice;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\ReplyInput;
use Tollbooth\Demo\Model\TicketView;

#[Endpoint('POST', '/backoffice/tickets/{id}/replies', status: 201)]
final readonly class ReplyToTicket
{
    public function __invoke(string $id, #[Body] ReplyInput $input): TicketView
    {
        return new TicketView($id, 'Parcel arrived damaged', 'usr_104', $input->closeTicket ? 'closed' : 'open', 4);
    }
}
