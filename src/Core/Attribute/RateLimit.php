<?php

declare(strict_types=1);

namespace Tollbooth\Core\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class RateLimit
{
    /**
     * @var list<string>|array<string, string>
     */
    public array $limits;

    public function __construct(string ...$limits)
    {
        $this->limits = $limits;
    }
}
