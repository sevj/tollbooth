<?php

declare(strict_types=1);

namespace Tollbooth\Auth;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\OidcUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Tollbooth\Core\Security\AuthMethod;

/**
 * @internal
 */
final readonly class TokenConsumerResolver
{
    public function __construct(
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    public function resolve(Request $request): ?Consumer
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }

        if ($user instanceof OidcUser) {
            return $this->fromOidc($user);
        }

        $method = str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ') ? AuthMethod::Jwt : AuthMethod::User;

        return new Consumer($user->getUserIdentifier(), $user->getUserIdentifier(), $method);
    }

    private function fromOidc(OidcUser $user): Consumer
    {
        $claims = $user->getAdditionalClaims();
        $clientId = $claims['client_id'] ?? $claims['azp'] ?? null;
        $id = \is_string($clientId) ? $clientId : $user->getUserIdentifier();

        return new Consumer($id, $user->getName() ?? $id, AuthMethod::Jwt, $this->scopes($claims['scope'] ?? $claims['scp'] ?? []));
    }

    /**
     * @return list<string>
     */
    private function scopes(mixed $claim): array
    {
        if (\is_string($claim)) {
            return array_values(array_filter(explode(' ', $claim)));
        }

        return \is_array($claim) ? array_values(array_filter($claim, \is_string(...))) : [];
    }
}
