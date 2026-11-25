<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tollbooth\Core\Health\HealthStatus;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Shell;
use Tollbooth\Health\CheckOutcome;
use Tollbooth\Health\HealthRunner;
use Twig\Environment;

/**
 * @internal
 */
final readonly class HealthController
{
    public function __construct(
        private HealthRunner $runner,
        private GroupCatalog $groups,
        private Shell $shell,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(Request $request, string $group): Response
    {
        if (!$this->groups->has($group)) {
            throw new NotFoundHttpException(\sprintf('No endpoint belongs to the group "%s".', $group));
        }

        $report = $this->runner->run();
        $sources = [];
        foreach ($report->checks as $check) {
            $sources[$check->source][] = $check;
        }
        $count = static fn (HealthStatus $status): int => \count(array_filter($report->checks, static fn (CheckOutcome $check): bool => $check->status === $status));

        return new Response($this->twig->render('@TollboothDashboard/health.html.twig', [
            ...$this->shell->context($group, 'health'),
            'report' => $report,
            'sources' => $sources,
            'warnings' => $count(HealthStatus::Warning),
            'criticals' => $count(HealthStatus::Critical),
            'probe' => $this->urls->generate('tollbooth_health_ready', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'live' => $this->urls->generate('tollbooth_health_live'),
            'ready' => $this->urls->generate('tollbooth_health_ready'),
            'public' => json_encode($report->toArray(false), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        ]), headers: ['Cache-Control' => 'no-store']);
    }
}
