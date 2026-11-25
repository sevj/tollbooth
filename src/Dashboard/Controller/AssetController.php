<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Dashboard\Assets;

/**
 * @internal
 */
final readonly class AssetController
{
    private const array TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'woff2' => 'font/woff2',
        'svg' => 'image/svg+xml',
    ];

    public function __construct(
        private Assets $assets,
    ) {
    }

    public function __invoke(Request $request, string $file): Response
    {
        $path = $this->assets->path($file) ?? throw new NotFoundHttpException();

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => self::TYPES[pathinfo($path, \PATHINFO_EXTENSION)] ?? 'application/octet-stream'], autoEtag: true);
        $response->setPublic();
        $response->setMaxAge($request->query->has('v') ? 31536000 : 300);
        if ($request->query->has('v')) {
            $response->headers->addCacheControlDirective('immutable');
        }
        $response->isNotModified($request);

        return $response;
    }
}
