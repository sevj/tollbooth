<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Tollbooth\Core\Output\OutputRenderer;
use Tollbooth\Core\Problem\ProblemFactory;
use Tollbooth\Core\Routing\EndpointRouteLoader;

/**
 * @internal
 */
final readonly class EndpointListener
{
    private string $pathPrefix;

    public function __construct(
        private OutputRenderer $renderer,
        private ProblemFactory $problems,
        string $pathPrefix = '',
    ) {
        $this->pathPrefix = rtrim($pathPrefix, '/');
    }

    public function onView(ViewEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->has(EndpointRouteLoader::ENDPOINT_ATTRIBUTE)) {
            return;
        }

        $status = $request->attributes->getInt(EndpointRouteLoader::STATUS_ATTRIBUTE, Response::HTTP_OK);
        $event->setResponse($this->renderer->render($event->getControllerResult(), $status));
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$this->isApiRequest($event->getRequest())) {
            return;
        }

        $problem = $this->problems->fromThrowable($event->getThrowable());
        if (!$event->getRequest()->attributes->has(EndpointRouteLoader::ENDPOINT_ATTRIBUTE)) {
            $problem = $problem->withoutDetail();
        }

        $response = new Response(
            json_encode($problem->toArray(), OutputRenderer::JSON_FLAGS | \JSON_THROW_ON_ERROR),
            $problem->status,
            ['Content-Type' => 'application/problem+json'] + $problem->headers,
        );

        $event->allowCustomResponseCode();
        $event->setResponse($response);
    }

    private function isApiRequest(Request $request): bool
    {
        if ($request->attributes->has(EndpointRouteLoader::ENDPOINT_ATTRIBUTE)) {
            return true;
        }

        if ('' === $this->pathPrefix) {
            return false;
        }

        $path = $request->getPathInfo();

        return $path === $this->pathPrefix || str_starts_with($path, $this->pathPrefix.'/');
    }
}
