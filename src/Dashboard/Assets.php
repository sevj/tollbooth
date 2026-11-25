<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard;

/**
 * @internal
 */
final class Assets
{
    /**
     * @var array<string, string>
     */
    private array $versions = [];

    public function __construct(
        private readonly string $directory = __DIR__.'/Resources/public',
    ) {
    }

    public function path(string $file): ?string
    {
        if (str_contains($file, '..')) {
            return null;
        }

        $path = $this->directory.'/'.$file;

        return is_file($path) ? $path : null;
    }

    public function version(string $file): string
    {
        if (!isset($this->versions[$file])) {
            $path = $this->path($file);
            $this->versions[$file] = null === $path ? '0' : substr((string) hash_file('xxh128', $path), 0, 10);
        }

        return $this->versions[$file];
    }
}
