<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReplyInput
{
    public function __construct(
        #[Assert\Length(min: 2, max: 5000)]
        public string $message = '',
        public bool $closeTicket = false,
    ) {
    }
}
