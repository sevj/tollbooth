<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

use Tollbooth\Core\Metadata\EndpointMetadata;

/**
 * @internal
 */
final class Pipeline
{
    private const array CODES = [
        'auth' => [401, 403],
        'rate_limit' => [429],
        'hydrate' => [400, 415],
        'validate' => [422],
    ];

    /**
     * @param array<int, int> $statuses
     *
     * @return list<PipelineStep>
     */
    public static function steps(EndpointMetadata $endpoint, ?Operation $operation, array $statuses, bool $rateLimited): array
    {
        $total = array_sum($statuses);
        $share = static function (array $codes) use ($statuses, $total): ?float {
            $calls = 0;
            foreach ($codes as $code) {
                $calls += \is_int($code) ? $statuses[$code] ?? 0 : 0;
            }

            return 0 === $total ? null : $calls / $total;
        };
        $listed = array_merge(...array_values(self::CODES));
        $handlerCodes = array_values(array_filter(array_keys($statuses), static fn (int $status): bool => $status >= 400 && !\in_array($status, $listed, true)));
        $successCodes = array_values(array_filter(array_keys($statuses), static fn (int $status): bool => $status < 400));
        sort($handlerCodes);
        sort($successCodes);
        if ([] === $successCodes) {
            $successCodes = [$operation->successStatus ?? $endpoint->status];
        }

        $public = ['anonymous'] === $endpoint->auth;
        $limits = array_map(static fn (array $limit): string => $limit['limit'].'/'.self::window($limit['window']), $endpoint->limits);
        $query = \count(array_filter($operation->parameters ?? [], static fn (Field $field): bool => 'query' === $field->in));
        $short = substr($endpoint->class, (int) strrpos('\\'.$endpoint->class, '\\'));

        $bodyModel = $operation?->bodyModel;
        $hydrate = array_values(array_filter([
            null === $bodyModel ? null : self::t('body', ['%model%' => $bodyModel]),
            $query > 0 ? self::t('query', ['%count%' => $query]) : null,
            [] !== $endpoint->requirements ? self::t('path', ['%count%' => \count($endpoint->requirements)]) : null,
        ]));

        return [
            new PipelineStep('auth', $public ? [self::t('public')] : [implode(' · ', $endpoint->auth), ...([] !== $endpoint->scopes ? [self::t('scope', ['%scopes%' => implode(' ', $endpoint->scopes)])] : [])], self::CODES['auth'], $share(self::CODES['auth']), off: $public),
            new PipelineStep('rate_limit', $rateLimited ? [...\array_slice($limits, 0, 2), self::t([] === $limits ? 'plan' : 'plan_extra')] : [self::t('off')], self::CODES['rate_limit'], $share(self::CODES['rate_limit']), off: !$rateLimited),
            new PipelineStep('hydrate', [] === $hydrate ? [self::t('no_input')] : $hydrate, self::CODES['hydrate'], $share(self::CODES['hydrate'])),
            new PipelineStep('validate', null === $operation || null === $bodyModel ? [self::t('no_body')] : [self::t('constrained', ['%count%' => $operation->constraints()]), self::t('nested', ['%count%' => $operation->nestedFields()])], self::CODES['validate'], $share(self::CODES['validate']), off: null === $bodyModel),
            new PipelineStep('handler', [$short, '::__invoke'], [] === $handlerCodes ? [500] : $handlerCodes, $share($handlerCodes)),
            new PipelineStep('serialize', [$operation->responseModel ?? self::t('no_content'), $endpoint->envelope.' · '.$endpoint->case], $successCodes, $share($successCodes), true),
        ];
    }

    private static function window(int $seconds): string
    {
        foreach ([86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second'] as $size => $unit) {
            if (0 === $seconds % $size) {
                $count = intdiv($seconds, $size);

                return 1 === $count ? $unit : $count.' '.$unit.'s';
            }
        }

        return $seconds.' seconds';
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return array{key: string, parameters: array<string, string|int>}
     */
    private static function t(string $key, array $parameters = []): array
    {
        return ['key' => $key, 'parameters' => $parameters];
    }
}
