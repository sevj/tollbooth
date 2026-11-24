<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Model;

final readonly class ThingView
{
    public function __construct(
        public int $id,
        public string $name,
        public Color $color,
        public ?string $note = null,
    ) {
    }
}
