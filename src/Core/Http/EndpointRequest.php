<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Routing\EndpointRouteLoader;

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
}
