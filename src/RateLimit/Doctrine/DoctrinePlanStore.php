<?php

declare(strict_types=1);

namespace Tollbooth\RateLimit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Tollbooth\Core\Metadata\Limit;

/**
 * @internal
 *
 * @phpstan-import-type LimitArray from Limit
 */
final readonly class DoctrinePlanStore
{
    private string $table;

    public function __construct(
        private Connection $connection,
        string $tablePrefix = 'tollbooth_',
    ) {
        $this->table = $tablePrefix.'rate_limit_plan';
    }

    /**
     * @return array<string, list<LimitArray>>
     */
    public function all(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('name', 'limits')
            ->from($this->table)
            ->orderBy('name')
            ->executeQuery()
            ->fetchAllAssociative();

        $plans = [];
        foreach ($rows as $row) {
            $limits = json_decode(\is_string($row['limits'] ?? null) ? $row['limits'] : '[]', true, 4, \JSON_THROW_ON_ERROR);
            $plans[\is_scalar($row['name'] ?? null) ? (string) $row['name'] : ''] = array_map(self::limit(...), array_values(\is_array($limits) ? $limits : []));
        }

        return $plans;
    }

    /**
     * @return LimitArray
     */
    private static function limit(mixed $limit): array
    {
        $limit = \is_array($limit) ? $limit : [];

        return [
            'name' => \is_string($limit['name'] ?? null) ? $limit['name'] : '',
            'limit' => \is_int($limit['limit'] ?? null) ? $limit['limit'] : 0,
            'window' => \is_int($limit['window'] ?? null) ? $limit['window'] : 0,
            'scope' => 'plan',
        ];
    }

    /**
     * @param list<Limit> $limits
     */
    public function save(string $name, array $limits): void
    {
        $row = [
            'limits' => json_encode(array_map(static fn (Limit $limit): array => $limit->toArray(), $limits), \JSON_THROW_ON_ERROR),
            'updated_at' => time(),
        ];

        if (false !== $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE name = ?', $this->table), [$name])) {
            $this->connection->update($this->table, $row, ['name' => $name]);
        } else {
            $this->connection->insert($this->table, ['name' => $name, ...$row]);
        }
    }

    public function remove(string $name): void
    {
        $this->connection->delete($this->table, ['name' => $name]);
    }

    public function schema(): Schema
    {
        $schema = new Schema();
        $table = $schema->createTable($this->table);
        $table->addColumn('name', Types::STRING, ['length' => 64]);
        $table->addColumn('limits', Types::TEXT);
        $table->addColumn('updated_at', Types::BIGINT);
        $table->setPrimaryKey(['name']);

        return $schema;
    }

    /**
     * @return list<string>
     */
    public function setupSql(): array
    {
        $manager = $this->connection->createSchemaManager();
        $platform = $this->connection->getDatabasePlatform();
        $sql = [];
        foreach ($this->schema()->getTables() as $table) {
            if (!$manager->tablesExist([$table->getName()])) {
                $sql = [...$sql, ...$platform->getCreateTableSQL($table)];
                continue;
            }
            $diff = $manager->createComparator()->compareTables($manager->introspectTable($table->getName()), $table);
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
}
