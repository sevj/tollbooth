<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Tollbooth\Core\Routing\EndpointRouteLoader;

/**
 * @internal
 */
final readonly class DeprecationListener
{
    public function __construct(
        private bool $goneAfterSunset = false,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $deprecation = self::deprecation($event->getRequest());
        if (!$this->goneAfterSunset || null === $deprecation || null === $deprecation['sunset']) {
            return;
        }

        $now = ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
        if ($now >= $deprecation['sunset']) {
            throw new GoneHttpException(\sprintf('This endpoint was retired on %s.', gmdate('Y-m-d', $deprecation['sunset'])));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $deprecation = self::deprecation($event->getRequest());
        if (null === $deprecation) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Deprecation', '@'.$deprecation['since']);
        if (null !== $deprecation['sunset']) {
            $headers->set('Sunset', gmdate('D, d M Y H:i:s', $deprecation['sunset']).' GMT');
        }
        if (null !== $deprecation['link']) {
            $headers->set('Link', \sprintf('<%s>; rel="deprecation"', $deprecation['link']), false);
        }
    }

    /**
     * @return array{since: int, sunset: ?int, link: ?string}|null
     */
    public static function deprecation(Request $request): ?array
    {
        $value = $request->attributes->get(EndpointRouteLoader::DEPRECATION_ATTRIBUTE);
        if (!\is_array($value) || !\is_int($value['since'] ?? null)) {
            return null;
        }

        return [
            'since' => $value['since'],
            'sunset' => \is_int($value['sunset'] ?? null) ? $value['sunset'] : null,
            'link' => \is_string($value['link'] ?? null) ? $value['link'] : null,
        ];
    }
}
