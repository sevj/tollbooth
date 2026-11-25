<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Dashboard\Endpoint\EndpointPageBuilder;
use Tollbooth\Dashboard\Endpoint\Snippets;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Shell;
use Tollbooth\Dashboard\Traffic\Range;
use Twig\Environment;

/**
 * @internal
 */
final readonly class EndpointController
{
    public function __construct(
        private EndpointPageBuilder $pages,
        private GroupCatalog $groups,
        private Shell $shell,
        private Environment $twig,
        private string $apiKeyHeader = 'X-Api-Key',
        private bool $traffic = false,
        private ?string $docsRoute = null,
    ) {
    }

    public function __invoke(Request $request, string $group, string $name): Response
    {
        $endpoint = $this->pages->find($group, $name) ?? throw new NotFoundHttpException(\sprintf('The group "%s" has no endpoint named "%s".', $group, $name));
        $range = Range::fromQuery($request->query->get('range'));
        $page = $this->pages->build($endpoint, $range);

        return new Response($this->twig->render('@TollboothDashboard/endpoint.html.twig', [
            ...$this->shell->context($group, 'endpoint'),
            'page' => $page,
            'snippets' => Snippets::for($endpoint, $page->operation, $request->getSchemeAndHttpHost().$this->groups->pathPrefix(), $this->apiKeyHeader),
            'range' => $range,
            'ranges' => Range::cases(),
            'traffic' => $this->traffic,
            'docs_route' => $this->docsRoute,
        ]), headers: ['Cache-Control' => 'no-store']);
    }
}
