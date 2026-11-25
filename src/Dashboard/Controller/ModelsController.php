<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tollbooth\Dashboard\DashboardRouteProvider;
use Tollbooth\Dashboard\Models\Model;
use Tollbooth\Dashboard\Models\ModelCatalog;
use Tollbooth\Dashboard\Models\ModelCatalogFactory;
use Tollbooth\Dashboard\Models\ModelDiagram;
use Tollbooth\Dashboard\Models\ModelPreview;
use Tollbooth\Dashboard\Shell;
use Twig\Environment;

/**
 * @internal
 */
final readonly class ModelsController
{
    private const array DEPTHS = ['1' => 1, '2' => 2, 'all' => null];

    public function __construct(
        private ModelCatalogFactory $catalogs,
        private Shell $shell,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function index(string $group): RedirectResponse
    {
        $first = $this->catalog($group)->first() ?? throw new NotFoundHttpException(\sprintf('The group "%s" has no model.', $group));

        return new RedirectResponse($this->urls->generate(DashboardRouteProvider::MODEL, ['group' => $group, 'model' => $first->name]));
    }

    public function page(Request $request, string $group, string $model): Response
    {
        $catalog = $this->catalog($group);
        $selected = $catalog->get($model) ?? throw new NotFoundHttpException(\sprintf('The group "%s" has no model "%s".', $group, $model));
        $endpoints = $this->catalogs->endpoints($group);
        $depth = self::depth($request->query->get('depth'));
        $configured = ['envelope' => $endpoints[0]->envelope ?? 'plain', 'case' => $endpoints[0]->case ?? 'snake_case'];
        $envelope = $request->query->get('envelope');
        $case = $request->query->get('case');
        $envelope = \is_string($envelope) && \in_array($envelope, ModelPreview::ENVELOPES, true) ? $envelope : $configured['envelope'];
        $case = \is_string($case) && \in_array($case, ModelPreview::CASES, true) ? $case : $configured['case'];

        return new Response($this->twig->render('@TollboothDashboard/models.html.twig', [
            ...$this->shell->context($group, 'models'),
            'catalog' => $catalog,
            'model' => $selected,
            'kinds' => [Model::OUTPUT => $catalog->ofKind(Model::OUTPUT), Model::INPUT => $catalog->ofKind(Model::INPUT), Model::ENUM => $catalog->ofKind(Model::ENUM)],
            'diagram' => ModelDiagram::build($catalog, $selected->name, $depth),
            'depth' => array_search($depth, self::DEPTHS, true),
            'depths' => array_keys(self::DEPTHS),
            'view' => 'table' === $request->query->get('view') ? 'table' : 'diagram',
            'envelope' => $envelope,
            'case' => $case,
            'configured' => $configured,
            'envelopes' => ModelPreview::ENVELOPES,
            'cases' => ModelPreview::CASES,
            'preview' => ModelPreview::render((array) $this->catalogs->document($group), $selected, $envelope, $case),
        ]), headers: ['Cache-Control' => 'no-store']);
    }

    public function svg(Request $request, string $group, string $model): Response
    {
        $catalog = $this->catalog($group);
        $selected = $catalog->get($model) ?? throw new NotFoundHttpException();

        return new Response($this->twig->render('@TollboothDashboard/model_diagram.svg.twig', [
            'diagram' => ModelDiagram::build($catalog, $selected->name, self::depth($request->query->get('depth'))),
            'standalone' => true,
        ]), headers: [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="%s.svg"', $selected->name),
        ]);
    }

    private function catalog(string $group): ModelCatalog
    {
        return $this->catalogs->catalog($group) ?? throw new NotFoundHttpException(\sprintf('No endpoint belongs to the group "%s".', $group));
    }

    private static function depth(mixed $value): ?int
    {
        return \is_string($value) && \array_key_exists($value, self::DEPTHS) ? self::DEPTHS[$value] : 2;
    }
}
