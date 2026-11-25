<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tollbooth\Core\Http\DeprecationListener;
use Tollbooth\Core\Routing\EndpointRouteLoader;

final class DeprecationListenerTest extends TestCase
{
    public function testRetiredEndpointAnswersGoneWhenEnabled(): void
    {
        $this->expectException(GoneHttpException::class);
        $this->expectExceptionMessage('This endpoint was retired on 2027-03-01.');

        (new DeprecationListener(true, new MockClock('2027-03-02', 'UTC')))->onRequest($this->event());
    }

    public function testRetiredEndpointKeepsAnsweringByDefault(): void
    {
        (new DeprecationListener(false, new MockClock('2027-03-02', 'UTC')))->onRequest($this->event());

        $this->addToAssertionCount(1);
    }

    public function testEndpointBeforeItsSunsetKeepsAnswering(): void
    {
        (new DeprecationListener(true, new MockClock('2027-02-28', 'UTC')))->onRequest($this->event());

        $this->addToAssertionCount(1);
    }

    private function event(): RequestEvent
    {
        $request = new Request();
        $request->attributes->set(EndpointRouteLoader::DEPRECATION_ATTRIBUTE, ['since' => 1780272000, 'sunset' => 1803859200, 'link' => null]);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
