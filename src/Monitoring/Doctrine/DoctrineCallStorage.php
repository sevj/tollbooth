<?php

declare(strict_types=1);

namespace Tollbooth\Monitoring\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Tollbooth\Monitoring\CallBreakdownInterface;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\CallRecorderInterface;
use Tollbooth\Monitoring\ErrorGroup;
use Tollbooth\Monitoring\LatencyHistogram;
use Tollbooth\Monitoring\RollupInterface;
use Tollbooth\Monitoring\Stats;

final readonly class DoctrineCallStorage implements CallRecorderInterface, CallReaderInterface, CallBreakdownInterface, RollupInterface
{
    private const string ROLLED_UNTIL = 'rolled_until';
    private const string PURGED_AT = 'purged_before';
    private const int GRACE = 60;

    private string $calls;
    private string $minutes;
    private string $state;

    public function __construct(
        private Connection $connection,
        string $tablePrefix = 'tollbooth_',
    ) {
        $this->calls = $tablePrefix.'call';
        $this->minutes = $tablePrefix.'call_minute';
        $this->state = $tablePrefix.'state';
    }

    public function record(CallRecord $call): void
    {
        $this->connection->insert($this->calls, [
            'id' => $call->id,
            'occurred_at' => $call->occurredAt,
            'minute' => $call->minute(),
            'group_name' => $call->group,
            'endpoint' => $call->endpoint,
            'method' => $call->method,
            'path' => mb_substr($call->path, 0, 1024),
            'status' => $call->status,
            'duration' => $call->duration,
            'latency_bucket' => LatencyHistogram::bucket($call->duration),
            'consumer_id' => mb_substr($call->consumerId, 0, 190),
            'consumer_name' => mb_substr($call->consumerName, 0, 190),
            'auth_method' => $call->authMethod,
            'ip' => $call->ip,
            'response_size' => $call->responseSize,
            'error' => $call->error,
            'steps' => json_encode($call->steps, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    public function rollup(int $now): int
    {
        $until = intdiv($now - self::GRACE, 60) * 60;
        $from = $this->rolledUntil() ?? $this->oldestMinute();
        if (null === $from || $from >= $until) {
            return 0;
        }

        return (int) $this->connection->transactional(function (Connection $connection) use ($from, $until): int {
            $columns = ['minute', 'group_name', 'endpoint', 'consumer_id', 'consumer_name', 'calls', 'client_errors', 'server_errors', 'throttled', 'duration_sum', 'duration_max', ...$this->latencyColumns()];
            $select = $this->rawAggregate(['minute', 'group_name', 'endpoint', 'consumer_id'])
                ->addSelect('MAX(consumer_name) AS consumer_name')
                ->andWhere('minute >= :from AND minute < :until')
                ->setParameter('from', $from, ParameterType::INTEGER)
                ->setParameter('until', $until, ParameterType::INTEGER);

            $rows = $connection->executeStatement(
                \sprintf('INSERT INTO %s (%s) SELECT %s FROM (%s) aggregated', $this->minutes, implode(', ', $columns), implode(', ', $columns), $select->getSQL()),
                $select->getParameters(),
                $select->getParameterTypes(),
            );

            $this->saveRolledUntil($until);

            return (int) $rows;
        });
    }

    /**
     * @return array{calls: int, minutes: int}
     */
    public function purge(int $callsBefore, int $minutesBefore): array
    {
        $purged = [
            'calls' => (int) $this->connection->executeStatement(\sprintf('DELETE FROM %s WHERE occurred_at < ?', $this->calls), [$callsBefore * 1000]),
            'minutes' => (int) $this->connection->executeStatement(\sprintf('DELETE FROM %s WHERE minute < ?', $this->minutes), [$minutesBefore]),
        ];
        $this->saveState(self::PURGED_AT, $callsBefore);

        return $purged;
    }

    /**
     * @return array{rolled_until: ?int, purged_before: ?int, oldest_call: ?int}
     */
    public function maintenanceState(): array
    {
        $oldest = $this->connection->fetchOne(\sprintf('SELECT MIN(occurred_at) FROM %s', $this->calls));

        return [
            'rolled_until' => $this->rolledUntil(),
            'purged_before' => $this->stateValue(self::PURGED_AT),
            'oldest_call' => false === $oldest || null === $oldest ? null : intdiv((int) self::scalar($oldest), 1000),
        ];
    }

    public function recent(CallQuery $query): array
    {
        $builder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->calls)
            ->orderBy('id', 'DESC')
            ->setMaxResults(max(1, min($query->limit, 500)));
        $this->filter($builder, $query, true);

        if (null !== $query->since) {
            $builder->andWhere('occurred_at >= :since')->setParameter('since', $query->since, ParameterType::INTEGER);
        }

        if (null !== $query->before) {
            $builder->andWhere('id < :before')->setParameter('before', $query->before);
        }

        return array_map($this->hydrate(...), $builder->executeQuery()->fetchAllAssociative());
    }

    public function series(int $from, int $to, ?CallQuery $filter = null): array
    {
        $series = [];
        foreach ($this->stats(['minute'], $from, $to, $filter) as $row) {
            $minute = (int) self::scalar($row['minute']);
            $stats = $this->toStats((string) $minute, (string) $minute, $row);
            $series[$minute] = isset($series[$minute]) ? $series[$minute]->merge($stats) : $stats;
        }
        ksort($series);

        return $series;
    }

    public function totals(int $from, int $to, string $by, ?CallQuery $filter = null): array
    {
        $key = 'endpoint' === $by ? 'endpoint' : 'consumer_id';
        $totals = [];
        foreach ($this->stats('endpoint' === $by ? ['endpoint'] : ['consumer_id'], $from, $to, $filter, 'endpoint' === $by ? 'MAX(group_name)' : 'MAX(consumer_name)') as $row) {
            $id = (string) self::scalar($row[$key]);
            $stats = $this->toStats($id, (string) (self::scalar($row['label'] ?? null) ?? $id), $row);
            $totals[$id] = isset($totals[$id]) ? $totals[$id]->merge($stats) : $stats;
        }

        $totals = array_values($totals);
        usort($totals, static fn (Stats $a, Stats $b): int => [$b->calls, $a->key] <=> [$a->calls, $b->key]);

        return $totals;
    }

    public function statuses(int $from, int $to, ?CallQuery $filter = null): array
    {
        $builder = $this->connection->createQueryBuilder()
            ->select('status', 'COUNT(*) AS calls')
            ->from($this->calls)
            ->where('occurred_at >= :from AND occurred_at < :to')
            ->groupBy('status')
            ->orderBy('calls', 'DESC')
            ->addOrderBy('status')
            ->setParameter('from', $from * 1000, ParameterType::INTEGER)
            ->setParameter('to', $to * 1000, ParameterType::INTEGER);
        $this->filter($builder, $filter, true);

        $statuses = [];
        foreach ($builder->executeQuery()->fetchAllAssociative() as $row) {
            $statuses[(int) self::scalar($row['status'])] = (int) self::scalar($row['calls']);
        }

        return $statuses;
    }

    public function errors(int $from, int $to, ?CallQuery $filter = null, int $limit = 10): array
    {
        $builder = $this->connection->createQueryBuilder()
            ->select('status', 'error', 'COUNT(*) AS calls', 'COUNT(DISTINCT consumer_id) AS consumers', 'MAX(occurred_at) AS last_seen')
            ->from($this->calls)
            ->where('occurred_at >= :from AND occurred_at < :to AND status >= 400')
            ->groupBy('status', 'error')
            ->orderBy('calls', 'DESC')
            ->addOrderBy('last_seen', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setParameter('from', $from * 1000, ParameterType::INTEGER)
            ->setParameter('to', $to * 1000, ParameterType::INTEGER);
        $this->filter($builder, $filter, true);

        return array_map(static fn (array $row): ErrorGroup => new ErrorGroup(
            (int) self::scalar($row['status']),
            null === $row['error'] ? null : (string) self::scalar($row['error']),
            (int) self::scalar($row['calls']),
            (int) self::scalar($row['consumers']),
            (int) self::scalar($row['last_seen']),
        ), $builder->executeQuery()->fetchAllAssociative());
    }

    public function schema(): Schema
    {
        $schema = new Schema();

        $calls = $schema->createTable($this->calls);
        $calls->addColumn('id', Types::STRING, ['length' => 26]);
        $calls->addColumn('occurred_at', Types::BIGINT);
        $calls->addColumn('minute', Types::BIGINT);
        $calls->addColumn('group_name', Types::STRING, ['length' => 64]);
        $calls->addColumn('endpoint', Types::STRING, ['length' => 190]);
        $calls->addColumn('method', Types::STRING, ['length' => 10]);
        $calls->addColumn('path', Types::STRING, ['length' => 1024]);
        $calls->addColumn('status', Types::SMALLINT);
        $calls->addColumn('duration', Types::FLOAT);
        $calls->addColumn('latency_bucket', Types::SMALLINT);
        $calls->addColumn('consumer_id', Types::STRING, ['length' => 190]);
        $calls->addColumn('consumer_name', Types::STRING, ['length' => 190]);
        $calls->addColumn('auth_method', Types::STRING, ['length' => 16]);
        $calls->addColumn('ip', Types::STRING, ['length' => 45, 'notnull' => false]);
        $calls->addColumn('response_size', Types::INTEGER, ['notnull' => false]);
        $calls->addColumn('error', Types::STRING, ['length' => 255, 'notnull' => false]);
        $calls->addColumn('steps', Types::TEXT);
        $calls->setPrimaryKey(['id']);
        $calls->addIndex(['minute'], $this->calls.'_by_minute');
        $calls->addIndex(['occurred_at'], $this->calls.'_by_time');
        $calls->addIndex(['endpoint', 'occurred_at'], $this->calls.'_by_endpoint');
        $calls->addIndex(['consumer_id', 'occurred_at'], $this->calls.'_by_consumer');

        $minutes = $schema->createTable($this->minutes);
        $minutes->addColumn('minute', Types::BIGINT);
        $minutes->addColumn('group_name', Types::STRING, ['length' => 64]);
        $minutes->addColumn('endpoint', Types::STRING, ['length' => 190]);
        $minutes->addColumn('consumer_id', Types::STRING, ['length' => 190]);
        $minutes->addColumn('consumer_name', Types::STRING, ['length' => 190]);
        foreach (['calls', 'client_errors', 'server_errors', 'throttled', ...$this->latencyColumns()] as $counter) {
            $minutes->addColumn($counter, Types::INTEGER);
        }
        $minutes->addColumn('duration_sum', Types::FLOAT);
        $minutes->addColumn('duration_max', Types::FLOAT);
        $minutes->setPrimaryKey(['minute', 'group_name', 'endpoint', 'consumer_id']);
        $minutes->addIndex(['minute'], $this->minutes.'_by_minute');

        $state = $schema->createTable($this->state);
        $state->addColumn('name', Types::STRING, ['length' => 64]);
        $state->addColumn('value', Types::BIGINT);
        $state->setPrimaryKey(['name']);

        return $schema;
    }

    /**
     * @return list<string>
     */
    public function setupSql(): array
    {
        $manager = $this->connection->createSchemaManager();
        $platform = $this->connection->getDatabasePlatform();
        $comparator = $manager->createComparator();
        $sql = [];
        foreach ($this->schema()->getTables() as $table) {
            if (!$manager->tablesExist([$table->getName()])) {
                $sql = [...$sql, ...$platform->getCreateTableSQL($table)];
                continue;
            }

            $diff = $comparator->compareTables($manager->introspectTable($table->getName()), $table);
            if (!$diff->isEmpty()) {
                $sql = [...$sql, ...$platform->getAlterTableSQL($diff)];
            }
        }

        return $sql;
    }

    /**
     * @return list<string>
     */
    public function setup(): array
    {
        $sql = $this->setupSql();
        foreach ($sql as $statement) {
            $this->connection->executeStatement($statement);
        }

        return $sql;
    }

    /**
     * @param list<string> $groupBy
     *
     * @return list<array<string, mixed>>
     */
    private function stats(array $groupBy, int $from, int $to, ?CallQuery $filter, ?string $label = null): array
    {
        $from = intdiv($from, 60) * 60;
        $rolledUntil = max($from, min($to, $this->rolledUntil() ?? $from));
        $rows = [];

        if ($rolledUntil > $from) {
            $sums = implode(', ', array_map(static fn (string $column): string => \sprintf('SUM(%1$s) AS %1$s', $column), ['calls', 'client_errors', 'server_errors', 'throttled', 'duration_sum', ...$this->latencyColumns()]));
            $builder = $this->connection->createQueryBuilder()
                ->select(...$groupBy)
                ->addSelect($sums, 'MAX(duration_max) AS duration_max')
                ->from($this->minutes)
                ->where('minute >= :from AND minute < :until')
                ->groupBy(...$groupBy)
                ->setParameter('from', $from, ParameterType::INTEGER)
                ->setParameter('until', $rolledUntil, ParameterType::INTEGER);
            if (null !== $label) {
                $builder->addSelect($label.' AS label');
            }
            $this->filter($builder, $filter);
            $rows = $builder->executeQuery()->fetchAllAssociative();
        }

        $raw = $this->rawAggregate($groupBy)
            ->andWhere('minute >= :from AND minute < :until')
            ->setParameter('from', $rolledUntil, ParameterType::INTEGER)
            ->setParameter('until', $to, ParameterType::INTEGER);
        if (null !== $label) {
            $raw->addSelect($label.' AS label');
        }
        $this->filter($raw, $filter);

        return [...$rows, ...$raw->executeQuery()->fetchAllAssociative()];
    }

    /**
     * @param list<string> $groupBy
     */
    private function rawAggregate(array $groupBy): QueryBuilder
    {
        $latency = [];
        foreach ($this->latencyColumns() as $index => $column) {
            $latency[] = \sprintf('SUM(CASE WHEN latency_bucket = %d THEN 1 ELSE 0 END) AS %s', $index, $column);
        }

        return $this->connection->createQueryBuilder()
            ->select(...$groupBy)
            ->addSelect(
                'COUNT(*) AS calls',
                'SUM(CASE WHEN status >= 400 AND status < 500 AND status <> 429 THEN 1 ELSE 0 END) AS client_errors',
                'SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) AS server_errors',
                'SUM(CASE WHEN status = 429 THEN 1 ELSE 0 END) AS throttled',
                'SUM(duration) AS duration_sum',
                'MAX(duration) AS duration_max',
                ...$latency,
            )
            ->from($this->calls)
            ->where('1 = 1')
            ->groupBy(...$groupBy);
    }

    private function filter(QueryBuilder $builder, ?CallQuery $query, bool $byStatus = false): void
    {
        if (null === $query) {
            return;
        }

        foreach (['endpoint' => $query->endpoint, 'group_name' => $query->group, 'consumer_id' => $query->consumerId] as $column => $value) {
            if (null !== $value) {
                $builder->andWhere(\sprintf('%1$s = :%1$s', $column))->setParameter($column, $value);
            }
        }

        if ($byStatus && null !== $query->statusClass) {
            $builder->andWhere('status >= :status_from AND status < :status_to')
                ->setParameter('status_from', $query->statusClass * 100, ParameterType::INTEGER)
                ->setParameter('status_to', $query->statusClass * 100 + 100, ParameterType::INTEGER);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CallRecord
    {
        $steps = json_decode((string) self::scalar($row['steps'] ?? '{}'), true);

        return new CallRecord(
            id: (string) self::scalar($row['id']),
            occurredAt: (int) self::scalar($row['occurred_at']),
            endpoint: (string) self::scalar($row['endpoint']),
            group: (string) self::scalar($row['group_name']),
            method: (string) self::scalar($row['method']),
            path: (string) self::scalar($row['path']),
            status: (int) self::scalar($row['status']),
            duration: (float) self::scalar($row['duration']),
            consumerId: (string) self::scalar($row['consumer_id']),
            consumerName: (string) self::scalar($row['consumer_name']),
            authMethod: (string) self::scalar($row['auth_method']),
            ip: null === $row['ip'] ? null : (string) self::scalar($row['ip']),
            responseSize: null === $row['response_size'] ? null : (int) self::scalar($row['response_size']),
            error: null === $row['error'] ? null : (string) self::scalar($row['error']),
            steps: self::steps($steps),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toStats(string $key, string $label, array $row): Stats
    {
        return new Stats(
            key: $key,
            label: $label,
            calls: (int) self::scalar($row['calls']),
            clientErrors: (int) self::scalar($row['client_errors']),
            serverErrors: (int) self::scalar($row['server_errors']),
            throttled: (int) self::scalar($row['throttled']),
            durationSum: (float) self::scalar($row['duration_sum']),
            durationMax: (float) self::scalar($row['duration_max']),
            latency: array_map(static fn (string $column): int => (int) self::scalar($row[$column] ?? 0), $this->latencyColumns()),
        );
    }

    /**
     * @return list<string>
     */
    private function latencyColumns(): array
    {
        return array_map(static fn (int $index): string => 'lat_'.$index, range(0, LatencyHistogram::buckets() - 1));
    }

    private function rolledUntil(): ?int
    {
        return $this->stateValue(self::ROLLED_UNTIL);
    }

    private function stateValue(string $name): ?int
    {
        $value = $this->connection->fetchOne(\sprintf('SELECT value FROM %s WHERE name = ?', $this->state), [$name]);

        return false === $value || null === $value ? null : (int) self::scalar($value);
    }

    private function oldestMinute(): ?int
    {
        $value = $this->connection->fetchOne(\sprintf('SELECT MIN(minute) FROM %s', $this->calls));

        return false === $value || null === $value ? null : (int) self::scalar($value);
    }

    private function saveRolledUntil(int $until): void
    {
        $this->saveState(self::ROLLED_UNTIL, $until);
    }

    private function saveState(string $name, int $value): void
    {
        $updated = $this->connection->update($this->state, ['value' => $value], ['name' => $name]);
        if (0 === $updated) {
            $this->connection->insert($this->state, ['name' => $name, 'value' => $value]);
        }
    }

    /**
     * @return array<string, float>
     */
    private static function steps(mixed $decoded): array
    {
        $steps = [];
        foreach (\is_array($decoded) ? $decoded : [] as $name => $value) {
            if (\is_string($name) && is_numeric($value)) {
                $steps[$name] = (float) $value;
            }
        }

        return $steps;
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        return \is_scalar($value) || null === $value ? $value : null;
    }
}
