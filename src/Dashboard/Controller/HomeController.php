<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tollbooth\Dashboard\DashboardRouteProvider;
use Tollbooth\Dashboard\GroupCatalog;

/**
 * @internal
 */
final readonly class HomeController
{
    public function __construct(
        private GroupCatalog $groups,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(): RedirectResponse
    {
        $group = $this->groups->names()[0] ?? throw new NotFoundHttpException('No endpoint is declared yet.');

        return new RedirectResponse($this->urls->generate(DashboardRouteProvider::MAP, ['group' => $group]));
    }
}
