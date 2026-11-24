<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tollbooth\Tests\Fixtures\TestKernel;

abstract class FunctionalTestCase extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @return array<mixed>
     */
    protected function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $problem
     *
     * @return list<mixed>
     */
    protected function fields(array $problem): array
    {
        self::assertIsArray($problem['violations']);

        return array_column($problem['violations'], 'field');
    }
}
