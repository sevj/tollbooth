<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<InMemoryUser>
 */
final readonly class ShopUserProvider implements UserProviderInterface
{
    public function __construct(
        private Connection $db,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): InMemoryUser
    {
        $row = $this->db->fetchAssociative('SELECT username, password, roles FROM users WHERE username = ?', [$identifier]);
        if (false === $row) {
            throw new UserNotFoundException(\sprintf('No user "%s".', $identifier));
        }

        $roles = json_decode((string) $row['roles'], true);

        return new InMemoryUser((string) $row['username'], (string) $row['password'], \is_array($roles) ? array_values(array_filter($roles, \is_string(...))) : []);
    }

    public function refreshUser(UserInterface $user): InMemoryUser
    {
        if (!$user instanceof InMemoryUser) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }
}
