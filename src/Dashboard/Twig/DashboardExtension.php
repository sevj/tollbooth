<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tollbooth\Dashboard\Assets;
use Tollbooth\Dashboard\DashboardRouteProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @internal
 */
final class DashboardExtension extends AbstractExtension
{
    public function __construct(
        private readonly Assets $assets,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('tb_compact', Format::compact(...)),
            new TwigFilter('tb_number', Format::number(...)),
            new TwigFilter('tb_percent', Format::percent(...)),
            new TwigFilter('tb_signed', Format::signed(...)),
            new TwigFilter('tb_change', Format::change(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tb_asset', $this->asset(...)),
            new TwigFunction('tb_sparkline', Format::sparkline(...)),
        ];
    }

    public function asset(string $file): string
    {
        return $this->urls->generate(DashboardRouteProvider::ASSET, ['file' => $file, 'v' => $this->assets->version($file)]);
    }
}
