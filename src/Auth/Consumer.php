<?php

declare(strict_types=1);

namespace Tollbooth\Auth;

use Tollbooth\Core\Security\AuthMethod;

final readonly class Consumer
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $id,
        public string $name,
        public AuthMethod $method,
        public array $scopes = [],
        public ?string $plan = null,
    ) {
    }

    public static function anonymous(?string $ip): self
    {
        $id = 'ip:'.($ip ?? 'unknown');

        return new self($id, $id, AuthMethod::Anonymous);
    }

    /**
     * @param list<string> $required
     *
     * @return list<string>
     */
    public function missingScopes(array $required): array
    {
        if (!$this->method->carriesScopes()) {
            return [];
        }

        return array_values(array_diff($required, $this->scopes));
    }
}
