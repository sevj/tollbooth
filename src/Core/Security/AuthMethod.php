<?php

declare(strict_types=1);

namespace Tollbooth\Core\Security;

enum AuthMethod: string
{
    case ApiKey = 'api_key';
    case Jwt = 'jwt';
    case User = 'user';
    case Anonymous = 'anonymous';

    public function requiresSecurityBundle(): bool
    {
        return self::Jwt === $this || self::User === $this;
    }

    public function carriesScopes(): bool
    {
        return self::ApiKey === $this || self::Jwt === $this;
    }

    /**
     * @param list<string> $values
     *
     * @return list<self>
     */
    public static function list(array $values, string $context): array
    {
        return array_map(
            static fn (string $value): self => self::tryFrom($value) ?? throw new \InvalidArgumentException(\sprintf('%s uses the unknown authentication method "%s". Known methods: "%s".', $context, $value, implode('", "', array_column(self::cases(), 'value')))),
            array_values(array_unique($values)),
        );
    }
}
