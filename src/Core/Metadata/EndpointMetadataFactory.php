<?php

declare(strict_types=1);

namespace Tollbooth\Core\Metadata;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;

/**
 * @internal
 */
final class EndpointMetadataFactory
{
    private const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

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

        return new EndpointMetadata(
            name: $endpoint->name ?? $this->nameFrom($class->getShortName()),
            method: $method,
            path: $path,
            status: $endpoint->status,
            serviceId: $serviceId,
            class: $class->getName(),
            file: (string) $class->getFileName(),
            requirements: $this->requirements($path, $class->getMethod('__invoke')),
        );
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
