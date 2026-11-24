<?php

declare(strict_types=1);

namespace Tollbooth\Core\Metadata;

use Tollbooth\Core\Attribute\Auth;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Core\Attribute\RateLimit;
use Tollbooth\Core\Attribute\Scope;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Security\AuthMethod;

/**
 * @internal
 */
final class EndpointMetadataFactory
{
    private const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    private readonly GroupResolver $groups;

    public function __construct(?GroupResolver $groups = null)
    {
        $this->groups = $groups ?? new GroupResolver(new Group(Group::DEFAULT, Envelope::Plain, NameCase::Preserve));
    }

    /**
     * @param \ReflectionClass<*> $class
     */
    public function create(string $serviceId, \ReflectionClass $class): EndpointMetadata
    {
        $attributes = $class->getAttributes(Endpoint::class);
        if ([] === $attributes) {
            throw InvalidEndpointException::missingAttribute($class->getName());
        }

        $endpoint = $attributes[0]->newInstance();
        $method = strtoupper($endpoint->method);
        if (!\in_array($method, self::METHODS, true)) {
            throw InvalidEndpointException::unsupportedMethod($class->getName(), $endpoint->method);
        }

        if (!$class->hasMethod('__invoke') || !$class->getMethod('__invoke')->isPublic()) {
            throw InvalidEndpointException::notInvokable($class->getName());
        }

        if ($endpoint->status < 100 || $endpoint->status > 599) {
            throw InvalidEndpointException::invalidStatus($class->getName(), $endpoint->status);
        }

        $path = '/'.trim($endpoint->path, '/');
        $group = $this->groups->resolve($class->getName(), $path, $endpoint->group);
        $mounted = rtrim($group->prefix.$path, '/');
        $auth = $this->auth($class, $group);
        $name = $endpoint->name ?? $this->nameFrom($class->getShortName());

        return new EndpointMetadata(
            name: $name,
            method: $method,
            path: '' === $mounted ? '/' : $mounted,
            status: $endpoint->status,
            serviceId: $serviceId,
            class: $class->getName(),
            file: (string) $class->getFileName(),
            requirements: $this->requirements($path, $class->getMethod('__invoke')),
            group: $group->name,
            envelope: $group->envelope->value,
            case: $group->case->value,
            auth: array_map(static fn (AuthMethod $method): string => $method->value, $auth),
            scopes: [AuthMethod::Anonymous] === $auth ? [] : $this->scopes($class, $group, $method, $path),
            limits: $this->limits($class, $group, $name),
        );
    }

    /**
     * @param \ReflectionClass<*> $class
     *
     * @return list<array{name: string, limit: int, window: int, scope: string}>
     */
    private function limits(\ReflectionClass $class, Group $group, string $name): array
    {
        $specs = [];
        foreach ($class->getAttributes(RateLimit::class) as $attribute) {
            $specs = [...$specs, ...$attribute->newInstance()->limits];
        }

        try {
            $own = Limit::parseAll($specs, 'endpoint:'.$group->name.'.'.$name, $name.'.');
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidEndpointException(\sprintf('Endpoint "%s": %s', $class->getName(), $exception->getMessage()), 0, $exception);
        }

        return array_map(static fn (Limit $limit): array => $limit->toArray(), [...$group->limits, ...$own]);
    }

    /**
     * @param \ReflectionClass<*> $class
     *
     * @return list<AuthMethod>
     */
    private function auth(\ReflectionClass $class, Group $group): array
    {
        if ([] !== $class->getAttributes(PublicEndpoint::class)) {
            return [AuthMethod::Anonymous];
        }

        $attribute = $class->getAttributes(Auth::class)[0] ?? null;
        if (null === $attribute) {
            return $group->auth;
        }

        $methods = AuthMethod::list($attribute->newInstance()->methods, \sprintf('Endpoint "%s"', $class->getName()));
        if ([] === $methods) {
            throw new InvalidEndpointException(\sprintf('Endpoint "%s" declares #[Auth] without any method. Use #[PublicEndpoint] for an open endpoint.', $class->getName()));
        }

        return $methods;
    }

    /**
     * @param \ReflectionClass<*> $class
     *
     * @return list<string>
     */
    private function scopes(\ReflectionClass $class, Group $group, string $method, string $path): array
    {
        $attributes = $class->getAttributes(Scope::class);
        if ([] !== $attributes) {
            return array_values(array_unique(array_merge(...array_map(
                static fn (\ReflectionAttribute $attribute): array => $attribute->newInstance()->scopes,
                $attributes,
            ))));
        }

        if (!$group->autoScopes) {
            return $group->scopes;
        }

        $resource = explode('/', trim($path, '/'))[0];
        if ('' === $resource || str_starts_with($resource, '{')) {
            return $group->scopes;
        }

        return [...$group->scopes, $resource.':'.(\in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) ? 'read' : 'write')];
    }

    private function nameFrom(string $shortName): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $shortName));
    }

    /**
     * @return array<string, string>
     */
    private function requirements(string $path, \ReflectionMethod $invoke): array
    {
        preg_match_all('/\{(\w+)\}/', $path, $matches);
        $placeholders = $matches[1];

        $requirements = [];
        foreach ($invoke->getParameters() as $parameter) {
            if (!\in_array($parameter->getName(), $placeholders, true) || $this->isMapped($parameter)) {
                continue;
            }

            $requirement = $this->requirementFor($parameter->getType());
            if (null !== $requirement) {
                $requirements[$parameter->getName()] = $requirement;
            }
        }

        return $requirements;
    }

    private function isMapped(\ReflectionParameter $parameter): bool
    {
        return [] !== $parameter->getAttributes(Body::class) || [] !== $parameter->getAttributes(Query::class);
    }

    private function requirementFor(?\ReflectionType $type): ?string
    {
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        $name = $type->getName();
        if ('int' === $name) {
            return '\d+';
        }

        if (is_subclass_of($name, \BackedEnum::class)) {
            return implode('|', array_map(
                static fn (\BackedEnum $case): string => preg_quote((string) $case->value),
                $name::cases(),
            ));
        }

        return null;
    }
}
