<?php

declare(strict_types=1);

namespace Tollbooth\Core\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Scope
{
    /**
     * @var list<string>
     */
    public array $scopes;

    public function __construct(string ...$scopes)
    {
        $this->scopes = array_values($scopes);
    }
}
