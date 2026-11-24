<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\SerializerInterface;
use Tollbooth\Core\Problem\ProblemFactory;
use Tollbooth\Core\Routing\EndpointRouteLoader;

/**
 * @internal
 */
final readonly class EndpointListener
{
    private const int JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private SerializerInterface $serializer,
        private ProblemFactory $problems,
    ) {
    }

    public function onView(ViewEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isEndpoint($request)) {
            return;
        }

        $status = $request->attributes->getInt(EndpointRouteLoader::STATUS_ATTRIBUTE, Response::HTTP_OK);
        $result = $event->getControllerResult();
        if (null === $result) {
            $event->setResponse(new Response(null, Response::HTTP_OK === $status ? Response::HTTP_NO_CONTENT : $status));

            return;
        }

        $event->setResponse(new Response(
            $this->serializer->serialize($result, 'json', [JsonEncode::OPTIONS => self::JSON_FLAGS]),
            $status,
            ['Content-Type' => 'application/json'],
        ));
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$this->isEndpoint($event->getRequest())) {
            return;
        }

        $problem = $this->problems->fromThrowable($event->getThrowable());
        $response = new Response(
            json_encode($problem->toArray(), self::JSON_FLAGS | \JSON_THROW_ON_ERROR),
            $problem->status,
            ['Content-Type' => 'application/problem+json'] + $problem->headers,
        );

        $event->allowCustomResponseCode();
        $event->setResponse($response);
    }

    private function isEndpoint(Request $request): bool
    {
        return $request->attributes->has(EndpointRouteLoader::ENDPOINT_ATTRIBUTE);
    }
}
