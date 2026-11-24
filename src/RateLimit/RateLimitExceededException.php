<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tollbooth\Core\Problem\ProblemMembersInterface;

final class RateLimitExceededException extends TooManyRequestsHttpException implements ProblemMembersInterface
{
    /**
     * @param list<LimitState> $states
     */
    public function __construct(
        private readonly array $states,
    ) {
        $rejected = array_values(array_filter($states, static fn (LimitState $state): bool => !$state->accepted));
        $first = $rejected[0] ?? $states[0] ?? null;

        parent::__construct(
            max([0, ...array_map(static fn (LimitState $state): int => $state->resetIn, $rejected)]),
            null === $first ? 'Rate limit reached.' : \sprintf('Rate limit "%s" reached: %d requests per %s.', $first->name, $first->limit, self::describe($first->window)),
        );
    }

    public function problemMembers(): array
    {
        return ['limiters' => array_map(static fn (LimitState $state): array => $state->toArray(), $this->states)];
    }

    private static function describe(int $window): string
    {
        foreach (['day' => 86400, 'hour' => 3600, 'minute' => 60] as $unit => $seconds) {
            if (0 === $window % $seconds) {
                $count = intdiv($window, $seconds);

                return 1 === $count ? $unit : $count.' '.$unit.'s';
            }
        }

        return 1 === $window ? 'second' : $window.' seconds';
    }
}
