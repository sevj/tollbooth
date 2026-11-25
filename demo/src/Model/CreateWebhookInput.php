<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateWebhookInput
{
    /**
     * @param list<string> $events
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        public string $url = '',
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Choice(choices: ['order.created', 'order.paid', 'order.shipped', 'payment.refunded'])])]
        public array $events = [],
    ) {
    }
}
