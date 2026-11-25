<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\OidcUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final readonly class DemoTokenHandler implements AccessTokenHandlerInterface
{
    public const array TOKENS = [
        'support-desk-token' => ['client_id' => 'support-desk', 'name' => 'Support desk', 'scope' => 'tickets:read tickets:write agents:read'],
        'reporting-bot-token' => ['client_id' => 'reporting-bot', 'name' => 'Reporting bot', 'scope' => 'tickets:read'],
    ];

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $claims = self::TOKENS[$accessToken] ?? throw new BadCredentialsException('Unknown token.');

        return new UserBadge($claims['client_id'], static fn (): OidcUser => new OidcUser(
            userIdentifier: $claims['client_id'],
            sub: $claims['client_id'],
            name: $claims['name'],
            client_id: $claims['client_id'],
            scope: $claims['scope'],
        ));
    }
}
