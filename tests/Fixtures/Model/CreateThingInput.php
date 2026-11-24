<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateThingInput
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name = '',
        #[Assert\Positive]
        public int $quantity = 0,
        public Color $color = Color::Amber,
    ) {
    }
}
