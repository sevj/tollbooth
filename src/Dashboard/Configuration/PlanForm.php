<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Configuration;

use Symfony\Component\HttpFoundation\Request;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\RateLimit\Plans;

/**
 * @internal
 *
 * @phpstan-import-type LimitArray from Limit
 */
final readonly class PlanForm
{
    private const array UNITS = [86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second'];

    /**
     * @param list<Limit>           $limits
     * @param array<string, string> $errors
     */
    private function __construct(
        public string $name,
        public string $rawLimits,
        public array $limits = [],
        public array $errors = [],
        public ?string $invalidLine = null,
    ) {
    }

    public static function empty(): self
    {
        return new self('', "60/minute\n10000/day");
    }

    /**
     * @param list<LimitArray> $limits
     */
    public static function of(string $name, array $limits): self
    {
        return new self($name, implode("\n", array_map(self::line(...), $limits)));
    }

    /**
     * @param list<string> $taken
     */
    public static function submit(Request $request, ?string $name, array $taken): self
    {
        $errors = [];
        if (null === $name) {
            $name = strtolower(trim($request->request->getString('name')));
            if ('' === $name) {
                $errors['name'] = 'name_required';
            } elseif (1 !== preg_match(Plans::NAME_PATTERN, $name)) {
                $errors['name'] = 'name_format';
            } elseif ('new' === $name || \in_array($name, $taken, true)) {
                $errors['name'] = 'name_taken';
            }
        }

        $raw = trim(str_replace("\r", '', $request->request->getString('limits')));
        $specs = [];
        $invalid = null;
        foreach (array_filter(array_map(trim(...), explode("\n", $raw)), static fn (string $line): bool => '' !== $line) as $line) {
            if (1 === preg_match('~^([a-z0-9_-]+)\s*:\s*(.+)$~i', $line, $matches)) {
                $specs[$matches[1]] = $matches[2];
            } else {
                $specs[] = $line;
            }
            try {
                Limit::parse(end($specs), 'plan');
            } catch (\InvalidArgumentException) {
                $invalid ??= $line;
            }
        }

        $limits = [];
        if (null !== $invalid) {
            $errors['limits'] = 'limits_format';
        } elseif ([] === $specs) {
            $errors['limits'] = 'limits_required';
        } else {
            try {
                $limits = Limit::parseAll($specs, 'plan');
            } catch (\InvalidArgumentException) {
                $errors['limits'] = 'limits_duplicate';
            }
        }

        return new self($name, $raw, $limits, $errors, $invalid);
    }

    public function valid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * @param LimitArray $limit
     */
    public static function line(array $limit): string
    {
        $period = $limit['window'].' seconds';
        foreach (self::UNITS as $seconds => $unit) {
            if (0 === $limit['window'] % $seconds) {
                $count = intdiv($limit['window'], $seconds);
                $period = 1 === $count ? $unit : $count.' '.$unit.'s';
                break;
            }
        }
        $spec = $limit['limit'].'/'.$period;

        return Limit::parse($spec, 'plan')->name === $limit['name'] ? $spec : $limit['name'].': '.$spec;
    }
}
