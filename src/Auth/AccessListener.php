<?php

declare(strict_types=1);

namespace Tollbooth\Auth;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Tollbooth\Auth\ApiKey\ApiKeyAuthenticator;
use Tollbooth\Core\Http\EndpointRequest;
use Tollbooth\Core\Security\AuthMethod;

/**
 * @internal
 */
final readonly class AccessListener
{
    public const string CONSUMER_ATTRIBUTE = '_tollbooth_consumer';

    public function __construct(
        private ApiKeyAuthenticator $apiKeys,
        private TokenConsumerResolver $tokens,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!EndpointRequest::isEndpoint($request)) {
            return;
        }

        $start = microtime(true);
        try {
            $consumer = $this->authenticate($request, EndpointRequest::auth($request));
            $request->attributes->set(self::CONSUMER_ATTRIBUTE, $consumer);

            $required = EndpointRequest::scopes($request);
            if ([] !== $required && AuthMethod::Anonymous === $consumer->method) {
                throw new UnauthorizedHttpException($this->challenges(EndpointRequest::auth($request)), \sprintf('Authentication is required: this endpoint requires the scope%s "%s".', \count($required) > 1 ? 's' : '', implode('", "', $required)));
            }

            $missing = $consumer->missingScopes($required);
            if ([] !== $missing) {
                throw new AccessDeniedHttpException(\sprintf('This endpoint requires the scope%s "%s".', \count($missing) > 1 ? 's' : '', implode('", "', $missing)));
            }
        } finally {
            EndpointRequest::addTiming($request, 'auth', $start);
        }
    }

    /**
     * @param list<AuthMethod> $allowed
     */
    private function authenticate(Request $request, array $allowed): Consumer
    {
        if (\in_array(AuthMethod::ApiKey, $allowed, true) && $this->apiKeys->hasCredentials($request)) {
            return $this->apiKeys->authenticate($request);
        }

        $consumer = $this->tokens->resolve($request, $allowed);
        if (null !== $consumer && \in_array($consumer->method, $allowed, true)) {
            return $consumer;
        }

        if (\in_array(AuthMethod::Anonymous, $allowed, true)) {
            return Consumer::anonymous($request->getClientIp());
        }

        throw new UnauthorizedHttpException($this->challenges($allowed), 'Authentication is required to access this endpoint.');
    }

    /**
     * @param list<AuthMethod> $allowed
     */
    private function challenges(array $allowed): string
    {
        $challenges = [];
        foreach ($allowed as $method) {
            $challenges[] = match ($method) {
                AuthMethod::ApiKey => $this->apiKeys->challenge(),
                AuthMethod::Jwt => 'Bearer',
                default => null,
            };
        }

        return implode(', ', array_filter($challenges)) ?: (\in_array(AuthMethod::User, $allowed, true) ? 'Session' : 'Bearer');
    }
}
