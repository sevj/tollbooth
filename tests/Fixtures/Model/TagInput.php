<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TagInput
{
    public function __construct(
        #[Assert\NotBlank]
        public string $label = '',
        public Color $color = Color::Amber,
    ) {
    }
}
