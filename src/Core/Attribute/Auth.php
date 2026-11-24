<?php

declare(strict_types=1);

namespace Tollbooth\Core\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Auth
{
    /**
     * @var list<string>
     */
    public array $methods;

    public function __construct(string ...$methods)
    {
        $this->methods = array_values($methods);
    }
}
