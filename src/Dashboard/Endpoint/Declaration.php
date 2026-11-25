<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

/**
 * @internal
 */
final readonly class Declaration
{
    private const int MAX_LINES = 30;

    public function __construct(
        public string $file,
        public string $code,
        public bool $truncated,
    ) {
    }

    /**
     * @param class-string $class
     */
    public static function of(string $class, string $projectDir = ''): ?self
    {
        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        if (false === $file || !is_readable($file)) {
            return null;
        }

        $lines = file($file, \FILE_IGNORE_NEW_LINES);
        $start = $reflection->getStartLine() - 1;
        $end = $reflection->getEndLine() - 1;
        if (false === $lines || $start < 0) {
            return null;
        }

        $first = $start;
        while ($first > 0 && self::isAttribute($lines[$first - 1])) {
            --$first;
        }
        while ($first < $start && !self::isAttribute($lines[$first]) && !str_contains($lines[$first], 'class ')) {
            ++$first;
        }

        $last = min($end, $first + self::MAX_LINES - 1);
        $code = implode("\n", \array_slice($lines, $first, $last - $first + 1));
        $relative = '' !== $projectDir && str_starts_with($file, rtrim($projectDir, '/\\').\DIRECTORY_SEPARATOR) ? substr($file, \strlen(rtrim($projectDir, '/\\')) + 1) : $file;

        return new self(str_replace('\\', '/', $relative), $code, $last < $end);
    }

    private static function isAttribute(string $line): bool
    {
        $trimmed = trim($line);

        return str_starts_with($trimmed, '#[') || (str_ends_with($trimmed, ')]') && !str_contains($trimmed, ';')) || str_ends_with($trimmed, ',');
    }
}
