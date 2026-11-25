<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard;

use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * @internal
 */
final readonly class LocaleListener
{
    /**
     * @param list<string> $locales
     */
    public function __construct(
        private ?string $locale = null,
        private array $locales = ['en', 'fr'],
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!\is_string($route) || !str_starts_with($route, DashboardRouteProvider::PREFIX) || $request->attributes->has('_locale')) {
            return;
        }

        $request->attributes->set('_locale', $this->locale ?? $request->getPreferredLanguage($this->locales) ?? $this->locales[0]);
    }
}
