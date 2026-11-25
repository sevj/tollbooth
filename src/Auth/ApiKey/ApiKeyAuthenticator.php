<?php

declare(strict_types=1);

namespace Tollbooth\Auth\ApiKey;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Security\AuthMethod;

/**
 * @internal
 */
final readonly class ApiKeyAuthenticator
{
    public function __construct(
        private ApiKeyProviderInterface $keys,
        private string $header = 'X-Api-Key',
        private ?ClockInterface $clock = null,
    ) {
    }

    public function hasCredentials(Request $request): bool
    {
        return $request->headers->has($this->header);
    }

    public function challenge(): string
    {
        return \sprintf('ApiKey header="%s"', $this->header);
    }

    public function authenticate(Request $request): Consumer
    {
        $credentials = ApiKeyFormat::parse((string) $request->headers->get($this->header));
        $key = null === $credentials ? null : $this->keys->find($credentials['id']);
        if (null === $credentials || null === $key || !ApiKeyFormat::verify($credentials['secret'], $key->hash) || $credentials['test'] !== $key->test) {
            throw $this->reject('The API key is not valid.');
        }

        if ($key->isExpired($this->clock?->now() ?? new \DateTimeImmutable())) {
            throw $this->reject('The API key has expired.');
        }

        if ([] !== $key->allowedIps && !IpUtils::checkIp((string) $request->getClientIp(), $key->allowedIps)) {
            throw $this->reject('The API key is not allowed from this address.');
        }

        return new Consumer($key->id, $key->name, AuthMethod::ApiKey, $key->scopes, $key->plan);
    }

    private function reject(string $message): UnauthorizedHttpException
    {
        return new UnauthorizedHttpException($this->challenge(), $message);
    }
}
