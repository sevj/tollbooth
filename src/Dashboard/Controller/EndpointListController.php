<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Dashboard\Endpoints\EndpointList;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Shell;
use Tollbooth\Dashboard\Traffic\Range;
use Twig\Environment;

/**
 * @internal
 */
final readonly class EndpointListController
{
    public function __construct(
        private EndpointList $list,
        private GroupCatalog $groups,
        private Shell $shell,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request, string $group): Response
    {
        if (!$this->groups->has($group)) {
            throw new NotFoundHttpException(\sprintf('No endpoint belongs to the group "%s".', $group));
        }

        $traffic = $this->list->hasTraffic();
        $range = Range::fromQuery($request->query->get('range'));
        $filter = self::pick($request->query->get('filter'), $traffic ? EndpointList::FILTERS : ['all', 'deprecated']);
        $sort = self::pick($request->query->get('sort'), $traffic ? EndpointList::SORTS : ['path']);
        $descending = 'path' !== $sort && 'asc' !== $request->query->get('dir');

        return new Response($this->twig->render('@TollboothDashboard/endpoints.html.twig', [
            ...$this->shell->context($group, 'endpoints'),
            'rows' => $this->list->rows($group, $range, $filter, $sort, $descending),
            'total' => \count($this->groups->endpoints($group)),
            'traffic' => $traffic,
            'range' => $range,
            'ranges' => Range::cases(),
            'filter' => $filter,
            'filters' => $traffic ? EndpointList::FILTERS : ['all', 'deprecated'],
            'sort' => $sort,
            'descending' => $descending,
        ]), headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @param list<string> $allowed
     */
    private static function pick(mixed $value, array $allowed): string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : $allowed[0];
    }
}
