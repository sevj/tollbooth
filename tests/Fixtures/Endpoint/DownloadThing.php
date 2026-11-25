<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Endpoint;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/things/{id}/file')]
final class DownloadThing
{
    public function __invoke(int $id): StreamedResponse
    {
        return new StreamedResponse(static function () use ($id): void {
            echo 'thing '.$id;
        }, 200, ['Content-Type' => 'application/octet-stream']);
    }
}
