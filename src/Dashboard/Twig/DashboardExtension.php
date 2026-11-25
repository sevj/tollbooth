<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Twig;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tollbooth\Dashboard\Assets;
use Tollbooth\Dashboard\DashboardRouteProvider;
use Tollbooth\Dashboard\Endpoint\PhpHighlighter;
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
            new TwigFilter('tb_truncate', Format::truncate(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tb_asset', $this->asset(...)),
            new TwigFunction('tb_sparkline', Format::sparkline(...)),
            new TwigFunction('tb_php', PhpHighlighter::highlight(...), ['is_safe' => ['html']]),
            new TwigFunction('tb_json', Format::json(...), ['is_safe' => ['html']]),
            new TwigFunction('tb_status_text', static fn (int $status): string => Response::$statusTexts[$status] ?? ''),
            new TwigFunction('tb_ago', static fn (int $milliseconds): array => Format::ago($milliseconds)),
        ];
    }

    public function asset(string $file): string
    {
        return $this->urls->generate(DashboardRouteProvider::ASSET, ['file' => $file, 'v' => $this->assets->version($file)]);
    }
}
