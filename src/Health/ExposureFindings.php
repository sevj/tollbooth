<?php

declare(strict_types=1);

namespace Tollbooth\Health;

use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMapInterface;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\FindingProviderInterface;

/**
 * @internal
 */
final readonly class ExposureFindings implements FindingProviderInterface
{
    private const array OPEN = ['PUBLIC_ACCESS', 'IS_AUTHENTICATED_ANONYMOUSLY'];

    /**
     * @param array<string, string> $paths
     */
    public function __construct(
        private array $paths,
        private ?FirewallMap $firewalls = null,
        private ?AccessMapInterface $access = null,
    ) {
    }

    public function findings(): iterable
    {
        foreach ($this->paths as $what => $path) {
            $request = Request::create($path);
            $config = $this->firewalls?->getFirewallConfig($request);
            if (null === $config || !$config->isSecurityEnabled()) {
                yield new Finding(\sprintf('The %s at %s is outside any firewall: anyone can read it.', $what, $path));

                continue;
            }

            if (null !== $this->access && !$this->requiresAccess($request)) {
                yield new Finding(\sprintf('The %s at %s is behind the "%s" firewall, but no access_control rule requires a role for it: anyone can read it.', $what, $path, $config->getName()));
            }
        }
    }

    private function requiresAccess(Request $request): bool
    {
        [$attributes] = $this->access?->getPatterns($request) ?? [null];

        foreach (\is_array($attributes) ? $attributes : [] as $attribute) {
            if (!\in_array($attribute, self::OPEN, true)) {
                return true;
            }
        }

        return false;
    }
}
