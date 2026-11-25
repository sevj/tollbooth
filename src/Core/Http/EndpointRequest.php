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
    private const string TIMINGS_ATTRIBUTE = '_tollbooth_timings';
    private const string MARKS_ATTRIBUTE = '_tollbooth_marks';
    private const string ERROR_ATTRIBUTE = '_tollbooth_error';

    public static function isEndpoint(Request $request): bool
    {
        return $request->attributes->has(EndpointRouteLoader::ENDPOINT_ATTRIBUTE);
    }

    public static function routeName(Request $request): string
    {
        return $request->attributes->getString('_route');
    }

    public static function group(Request $request): string
    {
        return $request->attributes->getString(EndpointRouteLoader::GROUP_ATTRIBUTE);
    }

    public static function addTiming(Request $request, string $step, float $since): void
    {
        $timings = self::timings($request);
        $timings[$step] = ($timings[$step] ?? 0.0) + round((microtime(true) - $since) * 1000, 3);
        $request->attributes->set(self::TIMINGS_ATTRIBUTE, $timings);
    }

    /**
     * @return array<string, float>
     */
    public static function timings(Request $request): array
    {
        $timings = $request->attributes->get(self::TIMINGS_ATTRIBUTE, []);
        $valid = [];
        foreach (\is_array($timings) ? $timings : [] as $step => $duration) {
            if (\is_string($step) && \is_float($duration)) {
                $valid[$step] = $duration;
            }
        }

        return $valid;
    }

    public static function mark(Request $request, string $mark): void
    {
        $marks = $request->attributes->get(self::MARKS_ATTRIBUTE, []);
        $request->attributes->set(self::MARKS_ATTRIBUTE, [...(\is_array($marks) ? $marks : []), $mark => microtime(true)]);
    }

    public static function markedAt(Request $request, string $mark): ?float
    {
        $marks = $request->attributes->get(self::MARKS_ATTRIBUTE, []);
        $value = \is_array($marks) ? ($marks[$mark] ?? null) : null;

        return \is_float($value) ? $value : null;
    }

    public static function setError(Request $request, string $error): void
    {
        $request->attributes->set(self::ERROR_ATTRIBUTE, mb_substr($error, 0, 255));
    }

    public static function error(Request $request): ?string
    {
        $error = $request->attributes->get(self::ERROR_ATTRIBUTE);

        return \is_string($error) ? $error : null;
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
