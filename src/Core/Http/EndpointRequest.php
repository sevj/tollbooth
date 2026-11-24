<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Routing\EndpointRouteLoader;
use Tollbooth\Core\Security\AuthMethod;

/**
 * @internal
 */
final class EndpointRequest
{
    public static function isEndpoint(Request $request): bool
    {
        return $request->attributes->has(EndpointRouteLoader::ENDPOINT_ATTRIBUTE);
    }

    public static function status(Request $request): int
    {
        return $request->attributes->getInt(EndpointRouteLoader::STATUS_ATTRIBUTE, Response::HTTP_OK);
    }

    public static function envelope(Request $request): Envelope
    {
        return Envelope::tryFrom($request->attributes->getString(EndpointRouteLoader::ENVELOPE_ATTRIBUTE)) ?? Envelope::Plain;
    }

    public static function case(Request $request): NameCase
    {
        return NameCase::tryFrom($request->attributes->getString(EndpointRouteLoader::CASE_ATTRIBUTE)) ?? NameCase::Preserve;
    }

    /**
     * @return list<AuthMethod>
     */
    public static function auth(Request $request): array
    {
        $methods = [];
        foreach (self::strings($request, EndpointRouteLoader::AUTH_ATTRIBUTE) as $value) {
            $method = AuthMethod::tryFrom($value);
            if (null !== $method) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * @return list<string>
     */
    public static function scopes(Request $request): array
    {
        return self::strings($request, EndpointRouteLoader::SCOPES_ATTRIBUTE);
    }

    /**
     * @return list<Limit>
     */
    public static function limits(Request $request): array
    {
        $values = $request->attributes->get(EndpointRouteLoader::LIMITS_ATTRIBUTE, []);
        if (!\is_array($values)) {
            return [];
        }

        $limits = [];
        foreach ($values as $value) {
            if (\is_array($value) && \is_string($value['name'] ?? null) && \is_int($value['limit'] ?? null) && \is_int($value['window'] ?? null) && \is_string($value['scope'] ?? null)) {
                $limits[] = new Limit($value['name'], $value['limit'], $value['window'], $value['scope']);
            }
        }

        return $limits;
    }

    /**
     * @return list<string>
     */
    private static function strings(Request $request, string $attribute): array
    {
        $values = $request->attributes->get($attribute, []);

        return \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];
    }
}
