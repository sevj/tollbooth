<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Changes\Change;
use Tollbooth\Changes\ChangeKind;
use Tollbooth\Changes\ChangeReport;
use Tollbooth\Changes\ConsumerChangelog;
use Tollbooth\Changes\Impact;
use Tollbooth\Dashboard\Changes\ContractChanges;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Shell;
use Twig\Environment;

/**
 * @internal
 */
final readonly class ChangesController
{
    public function __construct(
        private ContractChanges $changes,
        private Impact $impact,
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

        $versions = $this->changes->versions();
        $base = self::version($request->query->get('base'), $versions);
        $target = self::version($request->query->get('target'), $versions);
        $comparison = [] === $versions ? null : $this->changes->compare($base, $target);

        $report = null;
        $groups = [];
        $next = null;
        if (null !== $comparison) {
            $report = new ChangeReport(array_values(array_filter($comparison['report']->changes, static fn (Change $change): bool => $change->group === $group)));
            foreach (ChangeKind::cases() as $kind) {
                $items = [];
                foreach ($report->of($kind) as $change) {
                    $items[] = ['change' => $change, 'impact' => \in_array($kind, [ChangeKind::Breaking, ChangeKind::Risky, ChangeKind::Deprecated], true) ? $this->impact->of($change->operations, $comparison['ids']) : null];
                }
                if ([] !== $items) {
                    $groups[$kind->value] = $items;
                }
            }
            $next = $report->nextVersion($comparison['base']);
        }

        return new Response($this->twig->render('@TollboothDashboard/changes.html.twig', [
            ...$this->shell->context($group, 'changes'),
            'versions' => $versions,
            'comparison' => $comparison,
            'report' => $report,
            'kinds' => ChangeKind::cases(),
            'changes' => $groups,
            'next' => $next,
            'impact_days' => $this->impact->available() ? $this->impact->days() : null,
            'changelog' => null === $report ? null : ConsumerChangelog::markdown($report, 'current' === $comparison['target'] ? ($next ?? 'next') : $comparison['target'], 'current' !== $comparison['target']),
            'releases' => $this->changes->releases($group),
        ]), headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @param list<string> $versions
     */
    private static function version(mixed $value, array $versions): ?string
    {
        return \is_string($value) && \in_array($value, $versions, true) ? $value : null;
    }
}
