<?php

declare(strict_types=1);

namespace Tollbooth\Core\Metadata;

/**
 * @internal
 *
 * @phpstan-type LimitArray array{name: string, limit: int, window: int, scope: string}
 */
final readonly class Limit
{
    private const string PATTERN = '~^\s*(\d+)\s*/\s*(\d+)?\s*(s|sec|seconds?|m|min|minutes?|h|hours?|d|days?)\s*$~i';
    private const array UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];
    private const array NAMES = ['s' => 'second', 'm' => 'minute', 'h' => 'hour', 'd' => 'day'];

    public function __construct(
        public string $name,
        public int $limit,
        public int $window,
        public string $scope,
    ) {
    }

    public static function parse(string $spec, string $scope, ?string $name = null, string $namePrefix = ''): self
    {
        if (1 !== preg_match(self::PATTERN, $spec, $matches) || (int) $matches[1] < 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a rate limit. Write it as "<count>/<interval>", for instance "600/minute", "100/10 seconds" or "200000/day".', $spec));
        }

        $unit = strtolower($matches[3][0]);
        $count = '' === $matches[2] ? 1 : (int) $matches[2];
        if ($count < 1) {
            throw new \InvalidArgumentException(\sprintf('The interval of the rate limit "%s" must be at least one %s.', $spec, self::NAMES[$unit]));
        }

        return new self(
            name: $namePrefix.($name ?? (1 === $count ? self::NAMES[$unit] : $count.$unit)),
            limit: (int) $matches[1],
            window: $count * self::UNITS[$unit],
            scope: $scope,
        );
    }

    /**
     * @param list<string>|array<string, string> $specs
     *
     * @return list<self>
     */
    public static function parseAll(array $specs, string $scope, string $namePrefix = ''): array
    {
        $limits = [];
        foreach ($specs as $name => $spec) {
            $limit = self::parse($spec, $scope, \is_string($name) ? $name : null, $namePrefix);
            if (isset($limits[$limit->name])) {
                throw new \InvalidArgumentException(\sprintf('Two rate limits of "%s" are named "%s". Name them explicitly, for instance {burst: "%s"}.', $scope, $limit->name, $spec));
            }
            $limits[$limit->name] = $limit;
        }

        return array_values($limits);
    }

    /**
     * @return LimitArray
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'limit' => $this->limit, 'window' => $this->window, 'scope' => $this->scope];
    }

    /**
     * @param LimitArray $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
