<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tollbooth\Dashboard\Consumers\ConsumerDirectory;
use Tollbooth\Dashboard\Consumers\ConsumerInsights;
use Tollbooth\Dashboard\Consumers\ConsumerRow;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Shell;
use Twig\Environment;

/**
 * @internal
 */
final readonly class ConsumersController
{
    public const array FILTERS = ['all', 'near', 'throttled', 'idle'];

    public function __construct(
        private ConsumerDirectory $directory,
        private ConsumerInsights $insights,
        private GroupCatalog $groups,
        private Shell $shell,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function list(Request $request, string $group): Response
    {
        $this->assertGroup($group);
        $show = $request->query->getString('show', 'all');
        $show = \in_array($show, self::FILTERS, true) ? $show : 'all';
        $rows = $this->directory->rows($group);
        $active = \count(array_filter($rows, static fn (ConsumerRow $row): bool => !$row->idle()));
        $shown = array_values(array_filter($rows, static fn (ConsumerRow $row): bool => match ($show) {
            'near' => $row->nearLimit(),
            'throttled' => $row->throttled(),
            'idle' => $row->idle(),
            default => true,
        }));

        return new Response($this->twig->render('@TollboothDashboard/consumers.html.twig', [
            ...$this->shell->context($group, 'consumers'),
            'rows' => $shown,
            'total' => \count($rows),
            'active' => $active,
            'keys' => $this->directory->keyCount(),
            'plans' => $this->directory->plans(),
            'flow' => $this->insights->flow($group, $this->translator->trans('dashboard.consumers.others', [], 'tollbooth')),
            'show' => $show,
            'filters' => self::FILTERS,
        ]), headers: ['Cache-Control' => 'no-store']);
    }

    public function page(string $group, string $consumer): Response
    {
        $this->assertGroup($group);
        $page = $this->insights->page($group, $consumer) ?? throw new NotFoundHttpException(\sprintf('No consumer "%s".', $consumer));

        return new Response($this->twig->render('@TollboothDashboard/consumer.html.twig', [
            ...$this->shell->context($group, 'consumers'),
            'page' => $page,
            'consumer' => $page->consumer,
            'limits' => $this->directory->limitsOf($page->consumer->plan),
        ]), headers: ['Cache-Control' => 'no-store']);
    }

    private function assertGroup(string $group): void
    {
        if (!$this->groups->has($group)) {
            throw new NotFoundHttpException(\sprintf('No endpoint belongs to the group "%s".', $group));
        }
    }
}
