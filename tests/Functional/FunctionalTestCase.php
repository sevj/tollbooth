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
     * @param array<mixed> $data
     * @param list<string> $keys
     *
     * @return array<mixed>
     */
    protected static function withPath(array $data, array $keys, mixed $value, bool $remove = false): array
    {
        $key = array_shift($keys);
        if (null === $key) {
            return $data;
        }

        if ([] === $keys) {
            if ($remove) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }

            return $data;
        }

        $data[$key] = self::withPath(\is_array($data[$key] ?? null) ? $data[$key] : [], $keys, $value, $remove);

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    protected static function path(array $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
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
