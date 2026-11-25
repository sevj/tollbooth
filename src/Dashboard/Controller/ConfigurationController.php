<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tollbooth\Auth\ApiKey\ApiKeyProviderInterface;
use Tollbooth\Auth\ApiKey\ListableApiKeyProviderInterface;
use Tollbooth\Dashboard\Configuration\PlanForm;
use Tollbooth\Dashboard\Configuration\Settings;
use Tollbooth\Dashboard\DashboardRouteProvider;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Keys\FormGuard;
use Tollbooth\Dashboard\Shell;
use Tollbooth\RateLimit\Plans;
use Twig\Environment;

/**
 * @internal
 */
final readonly class ConfigurationController
{
    public function __construct(
        private Settings $settings,
        private GroupCatalog $groups,
        private Shell $shell,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private FormGuard $guard,
        private ?Plans $plans = null,
        private ?ApiKeyProviderInterface $keys = null,
    ) {
    }

    public function page(Request $request, string $group): Response
    {
        $this->assertGroup($group);
        $keys = $this->keysPerPlan();
        $plans = [];
        if (null !== $this->plans) {
            foreach ($this->plans->all() as $name => $limits) {
                $plans[] = [
                    'name' => $name,
                    'limits' => $limits,
                    'keys' => $keys[$name] ?? 0,
                    'configured' => $this->plans->configured($name),
                    'default' => $name === $this->plans->defaultPlan,
                    'anonymous' => $name === ($this->plans->anonymousPlan ?? $this->plans->defaultPlan),
                ];
            }
        }

        return new Response($this->twig->render('@TollboothDashboard/configuration.html.twig', [
            ...$this->shell->context($group, 'configuration'),
            'plans' => $plans,
            'rate_limited' => null !== $this->plans,
            'editable' => (bool) $this->plans?->editable(),
            'keys_listed' => $this->keys instanceof ListableApiKeyProviderInterface,
            'sections' => $this->settings->sections($group),
            'saved' => $request->query->get('saved'),
            'deleted' => $request->query->get('deleted'),
        ]), headers: ['Cache-Control' => 'no-store']);
    }

    public function create(Request $request, string $group): Response
    {
        $plans = $this->editablePlans($group);
        $form = PlanForm::empty();
        if ($request->isMethod('POST')) {
            $this->guard->check($request, 'plan_create');
            $form = PlanForm::submit($request, null, array_keys($plans->all()));
            if ($form->valid()) {
                $plans->save($form->name, $form->limits);

                return $this->saved($group, $form->name);
            }
        }

        return $this->form($group, $form, true);
    }

    public function edit(Request $request, string $group, string $plan): Response
    {
        $plans = $this->editablePlans($group);
        $limits = $this->stored($plans, $plan);
        $form = PlanForm::of($plan, $limits);
        if ($request->isMethod('POST')) {
            $this->guard->check($request, 'plan_edit', $plan);
            $form = PlanForm::submit($request, $plan, []);
            if ($form->valid()) {
                $plans->save($plan, $form->limits);

                return $this->saved($group, $plan);
            }
        }

        return $this->form($group, $form, false);
    }

    public function delete(Request $request, string $group, string $plan): RedirectResponse
    {
        $plans = $this->editablePlans($group);
        $this->stored($plans, $plan);
        $this->guard->check($request, 'plan_delete', $plan);
        if (($this->keysPerPlan()[$plan] ?? 0) > 0) {
            throw new ConflictHttpException(\sprintf('API keys still use the plan "%s".', $plan));
        }
        $plans->remove($plan);

        return new RedirectResponse($this->urls->generate(DashboardRouteProvider::CONFIGURATION, ['group' => $group, 'deleted' => $plan]).'#plans-title', 303);
    }

    private function form(string $group, PlanForm $form, bool $creating): Response
    {
        return new Response($this->twig->render('@TollboothDashboard/plan_form.html.twig', [
            ...$this->shell->context($group, 'configuration'),
            'form' => $form,
            'creating' => $creating,
        ]), $form->valid() ? 200 : 422, ['Cache-Control' => 'no-store']);
    }

    private function saved(string $group, string $plan): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(DashboardRouteProvider::CONFIGURATION, ['group' => $group, 'saved' => $plan]).'#plans-title', 303);
    }

    private function editablePlans(string $group): Plans
    {
        $this->assertGroup($group);
        if (null === $this->plans || !$this->plans->editable()) {
            throw new NotFoundHttpException('Rate limit plans are edited from the dashboard only when tollbooth.rate_limit.doctrine_plans is enabled.');
        }

        return $this->plans;
    }

    /**
     * @return list<array{name: string, limit: int, window: int, scope: string}>
     */
    private function stored(Plans $plans, string $plan): array
    {
        $limits = $plans->all()[$plan] ?? null;
        if (null === $limits || $plans->configured($plan)) {
            throw new NotFoundHttpException(\sprintf('No plan "%s" can be changed from the dashboard.', $plan));
        }

        return $limits;
    }

    /**
     * @return array<string, int>
     */
    private function keysPerPlan(): array
    {
        if (!$this->keys instanceof ListableApiKeyProviderInterface) {
            return [];
        }

        $counts = [];
        foreach ($this->keys->all() as $key) {
            $plan = $key->plan ?? $this->plans?->defaultPlan;
            if (null !== $plan) {
                $counts[$plan] = ($counts[$plan] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function assertGroup(string $group): void
    {
        if (!$this->groups->has($group)) {
            throw new NotFoundHttpException(\sprintf('No endpoint belongs to the group "%s".', $group));
        }
    }
}
