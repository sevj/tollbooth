<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
}
