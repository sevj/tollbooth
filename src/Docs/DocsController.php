<?php

declare(strict_types=1);

namespace Tollbooth\Docs;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal
 */
final readonly class DocsController
{
    public const string SCALAR_VERSION = '1.77.0';

    public function __construct(
        private OpenApiGenerator $generator,
        private UrlGeneratorInterface $urls,
        private bool $ui = true,
    ) {
    }

    public function document(string $group): JsonResponse
    {
        $document = $this->generator->generate($group) ?? throw new NotFoundHttpException(\sprintf('No endpoint belongs to the group "%s".', $group));

        return (new JsonResponse(null, headers: ['Cache-Control' => 'no-store']))
            ->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION)
            ->setData($document);
    }

    public function page(string $group): Response
    {
        if (!$this->ui || null === $this->generator->generate($group)) {
            throw new NotFoundHttpException();
        }

        $links = '';
        foreach ($this->generator->groups() as $other) {
            $links .= \sprintf(
                '<a href="%s"%s>%s</a>',
                htmlspecialchars($this->urls->generate(DocsRouteProvider::PAGE_ROUTE, ['group' => $other]), \ENT_QUOTES),
                $other === $group ? ' aria-current="page"' : '',
                htmlspecialchars($other, \ENT_QUOTES),
            );
        }

        $html = strtr(<<<'HTML'
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{title}</title>
            <style>
            body{margin:0;background:#0d0e10}
            nav{display:flex;gap:4px;padding:10px 16px;background:#0f1012;border-bottom:1px solid #1f2226;font:12px ui-monospace,monospace}
            nav a{color:#9c988f;text-decoration:none;padding:4px 10px}
            nav a[aria-current]{color:#0d0e10;background:#f0b429}
            </style>
            </head>
            <body>
            <nav aria-label="API groups">{links}</nav>
            <script id="api-reference" data-url="{url}"></script>
            <script>document.getElementById('api-reference').dataset.configuration = JSON.stringify({theme: 'saturn', darkMode: true, hideDownloadButton: false});</script>
            <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@{version}"></script>
            </body>
            </html>
            HTML, [
            '{title}' => htmlspecialchars('API documentation · '.$group, \ENT_QUOTES),
            '{links}' => $links,
            '{url}' => htmlspecialchars($this->urls->generate(DocsRouteProvider::DOCUMENT_ROUTE, ['group' => $group]), \ENT_QUOTES),
            '{version}' => self::SCALAR_VERSION,
        ]);

        return new Response($html, headers: ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
