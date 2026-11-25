<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Auth\ApiKey\ApiKeyFormat;
use Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface;
use Tollbooth\Dashboard\Consumers\ConsumerDirectory;
use Tollbooth\Dashboard\DashboardRouteProvider;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Keys\FormGuard;
use Tollbooth\Dashboard\Keys\KeyForm;
use Tollbooth\Dashboard\Shell;
use Twig\Environment;

/**
 * @internal
 */
final readonly class KeysController
{
    public function __construct(
        private ManageableApiKeyProviderInterface $keys,
        private ConsumerDirectory $directory,
        private FormGuard $guard,
        private GroupCatalog $groups,
        private Shell $shell,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function create(Request $request, string $group): Response
    {
        $this->assertGroup($group);
        $plans = array_column($this->directory->plans(), 'name');
        $default = array_values(array_filter($this->directory->plans(), static fn (array $plan): bool => $plan['default']))[0]['name'] ?? null;

        $form = KeyForm::empty($default);
        if ($request->isMethod('POST')) {
            $this->guard->check($request, 'create');
            $taken = [];
            foreach ($this->keys->all() as $key) {
                $taken[] = $key->name;
            }
            $form = KeyForm::submit($request, $plans, $taken, $this->now());
            if ($form->valid()) {
                $generated = ApiKeyFormat::generate(!$form->test);
                $key = new ApiKey($generated['id'], $form->name, $generated['hash'], $form->scopes, $form->plan, $form->expiresAt, $form->allowedIps, $this->now(), $form->test);
                $this->keys->save($key);

                return $this->issued($group, $key, $generated['key'], false);
            }
        }

        return new Response($this->twig->render('@TollboothDashboard/key_new.html.twig', [
            ...$this->shell->context($group, 'consumers'),
            'form' => $form,
            'plans' => $plans,
        ]), $form->valid() ? 200 : 422, ['Cache-Control' => 'no-store']);
    }

    public function rotate(Request $request, string $group, string $consumer): Response
    {
        $key = $this->managed($group, $consumer);
        $this->guard->check($request, 'rotate', $key->id);

        $generated = ApiKeyFormat::generate(!$key->test);
        $secret = substr($generated['key'], -32);
        $rotated = new ApiKey($key->id, $key->name, ApiKeyFormat::hash($secret), $key->scopes, $key->plan, $key->expiresAt, $key->allowedIps, $key->createdAt, $key->test);
        $this->keys->save($rotated);

        return $this->issued($group, $rotated, \sprintf('tb_%s_%s_%s', $key->test ? 'test' : 'live', $key->id, $secret), true);
    }

    public function plan(Request $request, string $group, string $consumer): RedirectResponse
    {
        $key = $this->managed($group, $consumer);
        $this->guard->check($request, 'plan', $key->id);
        $plan = $request->request->getString('plan');
        if (!\in_array($plan, array_column($this->directory->plans(), 'name'), true)) {
            throw new NotFoundHttpException(\sprintf('No plan "%s".', $plan));
        }

        $this->keys->save(new ApiKey($key->id, $key->name, $key->hash, $key->scopes, $plan, $key->expiresAt, $key->allowedIps, $key->createdAt, $key->test));

        return new RedirectResponse($this->urls->generate(DashboardRouteProvider::CONSUMER, ['group' => $group, 'consumer' => $key->id]), 303);
    }

    public function revoke(Request $request, string $group, string $consumer): RedirectResponse
    {
        $key = $this->managed($group, $consumer);
        $this->guard->check($request, 'revoke', $key->id);
        $this->keys->remove($key->id);

        return new RedirectResponse($this->urls->generate(DashboardRouteProvider::CONSUMERS, ['group' => $group, 'revoked' => $key->name]), 303);
    }

    private function issued(string $group, ApiKey $key, string $secret, bool $rotated): Response
    {
        return new Response($this->twig->render('@TollboothDashboard/key_issued.html.twig', [
            ...$this->shell->context($group, 'consumers'),
            'key' => $key,
            'secret' => $secret,
            'rotated' => $rotated,
        ]), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function managed(string $group, string $id): ApiKey
    {
        $this->assertGroup($group);
        $key = $this->keys->find($id);
        if (null === $key || !$this->keys->manages($id)) {
            throw new NotFoundHttpException(\sprintf('No API key "%s" can be changed from the dashboard.', $id));
        }

        return $key;
    }

    private function assertGroup(string $group): void
    {
        if (!$this->groups->has($group)) {
            throw new NotFoundHttpException(\sprintf('No endpoint belongs to the group "%s".', $group));
        }
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }
}
