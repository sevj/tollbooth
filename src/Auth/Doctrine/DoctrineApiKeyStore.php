<?php

declare(strict_types=1);

namespace Tollbooth\Auth\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface;

/**
 * @internal
 */
final class DoctrineApiKeyStore implements ManageableApiKeyProviderInterface
{
    private string $table;

    /**
     * @var array<string, ?ApiKey>
     */
    private array $found = [];

    public function __construct(
        private readonly Connection $connection,
        string $tablePrefix = 'tollbooth_',
    ) {
        $this->table = $tablePrefix.'api_key';
    }

    public function find(string $id): ?ApiKey
    {
        if (!\array_key_exists($id, $this->found)) {
            $row = $this->connection->createQueryBuilder()
                ->select('*')
                ->from($this->table)
                ->where('id = :id')
                ->setParameter('id', $id)
                ->executeQuery()
                ->fetchAssociative();
            $this->found[$id] = false === $row ? null : self::hydrate($row);
        }

        return $this->found[$id];
    }

    public function all(): iterable
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->orderBy('name')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::hydrate(...), $rows);
    }

    public function save(ApiKey $key): void
    {
        $row = [
            'name' => $key->name,
            'hash' => $key->hash,
            'scopes' => json_encode($key->scopes, \JSON_THROW_ON_ERROR),
            'plan' => $key->plan,
            'expires_at' => $key->expiresAt?->getTimestamp(),
            'allowed_ips' => json_encode($key->allowedIps, \JSON_THROW_ON_ERROR),
            'created_at' => ($key->createdAt ?? new \DateTimeImmutable())->getTimestamp(),
            'test' => $key->test ? 1 : 0,
        ];

        if ($this->manages($key->id)) {
            $this->connection->update($this->table, $row, ['id' => $key->id]);
        } else {
            $this->connection->insert($this->table, ['id' => $key->id, ...$row]);
        }
        $this->found[$key->id] = $key;
    }

    public function remove(string $id): void
    {
        $this->connection->delete($this->table, ['id' => $id]);
        $this->found[$id] = null;
    }

    public function manages(string $id): bool
    {
        return null !== $this->find($id);
    }

    public function schema(): Schema
    {
        $schema = new Schema();
        $table = $schema->createTable($this->table);
        $table->addColumn('id', Types::STRING, ['length' => 32]);
        $table->addColumn('name', Types::STRING, ['length' => 190]);
        $table->addColumn('hash', Types::STRING, ['length' => 128]);
        $table->addColumn('scopes', Types::TEXT);
        $table->addColumn('plan', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('expires_at', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('allowed_ips', Types::TEXT);
        $table->addColumn('created_at', Types::BIGINT);
        $table->addColumn('test', Types::SMALLINT, ['default' => 0]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['name'], $this->table.'_name');

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

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): ApiKey
    {
        $expires = $row['expires_at'] ?? null;
        $created = $row['created_at'] ?? null;

        return new ApiKey(
            id: self::string($row['id'] ?? ''),
            name: self::string($row['name'] ?? ''),
            hash: self::string($row['hash'] ?? ''),
            scopes: self::list($row['scopes'] ?? '[]'),
            plan: null === ($row['plan'] ?? null) ? null : self::string($row['plan']),
            expiresAt: is_numeric($expires) ? new \DateTimeImmutable('@'.(int) $expires) : null,
            allowedIps: self::list($row['allowed_ips'] ?? '[]'),
            createdAt: is_numeric($created) ? new \DateTimeImmutable('@'.(int) $created) : null,
            test: (bool) ($row['test'] ?? false),
        );
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        $decoded = \is_string($value) ? json_decode($value, true) : null;

        return \is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
