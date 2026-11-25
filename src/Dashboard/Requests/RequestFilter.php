<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Requests;

/**
 * @internal
 */
final readonly class RequestFilter
{
    public const array LAST = ['15m' => 900, '1h' => 3600, '24h' => 86400, '7d' => 604800];
    public const array STATUSES = ['2xx', '4xx', '429', '5xx'];
    public const array DURATIONS = ['100', '500', '1000'];
    private const array KEYS = ['endpoint', 'status', 'consumer', 'duration', 'last'];

    /**
     * @param array<string, string> $terms
     */
    private function __construct(
        public array $terms,
        public string $text,
    ) {
    }

    public static function parse(string $query): self
    {
        $terms = [];
        $text = [];
        foreach (preg_split('/\s+/', trim($query)) ?: [] as $token) {
            if ('' === $token) {
                continue;
            }
            [$key, $value] = array_pad(explode(':', $token, 2), 2, '');
            $key = strtolower($key);
            if (\in_array($key, self::KEYS, true) && '' !== $value) {
                $terms[$key] = $value;
            } else {
                $text[] = $token;
            }
        }

        return new self($terms, implode(' ', $text));
    }

    public function get(string $key): ?string
    {
        return $this->terms[$key] ?? null;
    }

    public function with(string $key, ?string $value): string
    {
        $terms = $this->terms;
        if (null === $value) {
            unset($terms[$key]);
        } else {
            $terms[$key] = $value;
        }

        return self::render($terms, $this->text);
    }

    public function __toString(): string
    {
        return self::render($this->terms, $this->text);
    }

    public function seconds(): int
    {
        return self::LAST[$this->terms['last'] ?? ''] ?? self::LAST['1h'];
    }

    public function last(): string
    {
        $last = $this->terms['last'] ?? '1h';

        return isset(self::LAST[$last]) ? $last : '1h';
    }

    public function statusClass(): ?int
    {
        $status = strtolower($this->terms['status'] ?? '');

        return 1 === preg_match('/^([1-5])xx$/', $status, $match) ? (int) $match[1] : null;
    }

    public function status(): ?int
    {
        $status = $this->terms['status'] ?? '';

        return 1 === preg_match('/^[1-5]\d\d$/', $status) ? (int) $status : null;
    }

    public function minDuration(): ?float
    {
        $duration = ltrim($this->terms['duration'] ?? '', '>=');

        return is_numeric($duration) ? (float) $duration : null;
    }

    /**
     * @param array<string, string> $terms
     */
    private static function render(array $terms, string $text): string
    {
        $tokens = [];
        foreach (self::KEYS as $key) {
            if (isset($terms[$key])) {
                $tokens[] = $key.':'.$terms[$key];
            }
        }
        if ('' !== $text) {
            $tokens[] = $text;
        }

        return implode(' ', $tokens);
    }
}
