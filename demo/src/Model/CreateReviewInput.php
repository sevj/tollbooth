<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateReviewInput
{
    public function __construct(
        public Rating $rating = Rating::Five,
        #[Assert\Length(min: 10, max: 2000)]
        public string $comment = '',
    ) {
    }
}
