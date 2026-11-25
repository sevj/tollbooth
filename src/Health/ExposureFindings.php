<?php

declare(strict_types=1);

namespace Tollbooth\Health;

use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\FindingProviderInterface;

/**
 * @internal
 */
final readonly class ExposureFindings implements FindingProviderInterface
{
    /**
     * @param array<string, string> $paths
     */
    public function __construct(
        private array $paths,
        private ?FirewallMap $firewalls = null,
    ) {
    }

    public function findings(): iterable
    {
        foreach ($this->paths as $what => $path) {
            $config = $this->firewalls?->getFirewallConfig(Request::create($path));
            if (null === $config || !$config->isSecurityEnabled()) {
                yield new Finding(\sprintf('The %s at %s is outside any firewall: anyone can read it.', $what, $path));
            }
        }
    }
}
