<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

use Tollbooth\Core\Metadata\EndpointMetadata;

/**
 * @internal
 */
final class ResourceGrouper
{
    private const array METHOD_ORDER = ['GET' => 0, 'HEAD' => 1, 'POST' => 2, 'PUT' => 3, 'PATCH' => 4, 'DELETE' => 5, 'OPTIONS' => 6];

    /**
     * @param list<EndpointMetadata> $endpoints
     * @param list<string>           $strip
     *
     * @return array<string, list<EndpointMetadata>>
     */
    public static function group(array $endpoints, array $strip = []): array
    {
        $local = static fn (EndpointMetadata $endpoint): string => self::localPath($endpoint->path, $strip);
        usort($endpoints, static fn (EndpointMetadata $a, EndpointMetadata $b): int => [$local($a), self::METHOD_ORDER[$a->method] ?? 9] <=> [$local($b), self::METHOD_ORDER[$b->method] ?? 9]);

        $resources = [];
        foreach ($endpoints as $endpoint) {
            $resources[self::resourceOf($local($endpoint))][] = $endpoint;
        }

        return $resources;
    }

    /**
     * @param list<string> $strip
     */
    public static function localPath(string $path, array $strip): string
    {
        foreach ($strip as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ('' !== $prefix && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                $path = substr($path, \strlen($prefix));
            }
        }

        return '' === $path ? '/' : $path;
    }

    public static function resourceOf(string $localPath): string
    {
        $first = explode('/', trim($localPath, '/'))[0];

        return '' === $first || str_starts_with($first, '{') ? '/' : $first;
    }

    public static function title(string $resource): string
    {
        return '/' === $resource ? '/' : ucfirst(str_replace(['-', '_'], ' ', $resource));
    }
}
