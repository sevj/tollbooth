<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Dashboard\Map\ApiMap;
use Tollbooth\Dashboard\Map\MapBuilder;
use Tollbooth\Dashboard\Map\MermaidExporter;
use Tollbooth\Dashboard\Shell;
use Tollbooth\Dashboard\Traffic\Range;
use Twig\Environment;

/**
 * @internal
 */
final readonly class MapController
{
    public function __construct(
        private MapBuilder $maps,
        private Shell $shell,
        private Environment $twig,
    ) {
    }

    public function page(Request $request, string $group): Response
    {
        $range = Range::fromQuery($request->query->get('range'));
        $live = $this->maps->hasTraffic() && 'structure' !== $request->query->get('mode');
        $map = $this->map($group, $range, $live);

        return new Response($this->twig->render('@TollboothDashboard/map.html.twig', [
            ...$this->shell->context($group, 'map'),
            'map' => $map,
            'range' => $range,
            'ranges' => Range::cases(),
            'live' => $live,
            'traffic' => $this->maps->hasTraffic(),
        ]), headers: ['Cache-Control' => 'no-store']);
    }

    public function mermaid(string $group): Response
    {
        return new Response(MermaidExporter::export($this->map($group, Range::Day, false)), headers: [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="%s-map.mmd"', $group),
        ]);
    }

    private function map(string $group, Range $range, bool $live): ApiMap
    {
        return $this->maps->build($group, $range, $live) ?? throw new NotFoundHttpException(\sprintf('No endpoint belongs to the group "%s".', $group));
    }
}
