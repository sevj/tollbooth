<?php

declare(strict_types=1);

namespace Tollbooth\Core\Metadata;

final class InvalidEndpointException extends \LogicException
{
    public static function missingAttribute(string $class): self
    {
        return new self(\sprintf('Class "%s" is tagged as an endpoint but has no #[Endpoint] attribute.', $class));
    }

    public static function unsupportedMethod(string $class, string $method): self
    {
        return new self(\sprintf('Endpoint "%s" declares the unsupported HTTP method "%s".', $class, $method));
    }

    public static function notInvokable(string $class): self
    {
        return new self(\sprintf('Endpoint "%s" must declare a public __invoke() method.', $class));
    }

    public static function invalidStatus(string $class, int $status): self
    {
        return new self(\sprintf('Endpoint "%s" declares the invalid status code %d.', $class, $status));
    }

    public static function duplicateName(string $name, string $first, string $second): self
    {
        return new self(\sprintf('Endpoints "%s" and "%s" share the name "%s". Set a distinct name on one of their #[Endpoint] attributes.', $first, $second, $name));
    }

    public static function duplicateRoute(string $route, string $first, string $second): self
    {
        return new self(\sprintf('Endpoints "%s" and "%s" both answer "%s".', $first, $second, $route));
    }
}
