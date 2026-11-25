<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Tollbooth\Demo\Model\Category;
use Tollbooth\Demo\Model\OrderStatus;

final readonly class ShopSchema
{
    public const array PRODUCTS = [
        ['Desk lamp', Category::Home, 3490],
        ['Noise cancelling headphones', Category::Electronics, 19900],
        ['Garden hose 25 m', Category::Garden, 2990],
        ['Wooden train set', Category::Toys, 4590],
        ['Espresso machine', Category::Home, 24900],
        ['USB-C charger 65 W', Category::Electronics, 3990],
        ['Pruning shears', Category::Garden, 1890],
        ['Building blocks 500 pcs', Category::Toys, 3290],
        ['Smart thermostat', Category::Electronics, 12900],
        ['Linen bedsheets', Category::Home, 8900],
        ['Solar garden lights', Category::Garden, 2490],
        ['Plush fox', Category::Toys, 1990],
    ];

    public const array USERS = [
        ['admin', 'admin', 'Shop administrator', ['ROLE_ADMIN']],
        ['ada', 'ada', 'Ada Lovelace', ['ROLE_USER']],
    ];

    public function __construct(
        private Connection $connection,
        private PasswordHasherFactoryInterface $hashers,
    ) {
    }

    public function reset(): void
    {
        $manager = $this->connection->createSchemaManager();
        foreach (array_reverse($this->schema()->getTables()) as $table) {
            if ($manager->tablesExist([$table->getName()])) {
                $manager->dropTable($table->getName());
            }
        }

        foreach ($this->schema()->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->connection->transactional(fn () => $this->fixtures());
    }

    private function schema(): Schema
    {
        $schema = new Schema();

        $products = $schema->createTable('products');
        $products->addColumn('id', Types::STRING, ['length' => 32]);
        $products->addColumn('sku', Types::STRING, ['length' => 32]);
        $products->addColumn('name', Types::STRING, ['length' => 120]);
        $products->addColumn('category', Types::STRING, ['length' => 32]);
        $products->addColumn('price', Types::INTEGER);
        $products->addColumn('stock', Types::INTEGER);
        $products->addColumn('updated_at', Types::DATETIMETZ_IMMUTABLE);
        $products->setPrimaryKey(['id']);

        $users = $schema->createTable('users');
        $users->addColumn('username', Types::STRING, ['length' => 64]);
        $users->addColumn('name', Types::STRING, ['length' => 120]);
        $users->addColumn('password', Types::STRING, ['length' => 255]);
        $users->addColumn('roles', Types::JSON);
        $users->setPrimaryKey(['username']);

        $customers = $schema->createTable('customers');
        $customers->addColumn('id', Types::STRING, ['length' => 32]);
        $customers->addColumn('email', Types::STRING, ['length' => 190]);
        $customers->addColumn('name', Types::STRING, ['length' => 120]);
        $customers->addColumn('banned', Types::BOOLEAN, ['default' => false]);
        $customers->setPrimaryKey(['id']);

        $carts = $schema->createTable('carts');
        $carts->addColumn('id', Types::STRING, ['length' => 32]);
        $carts->addColumn('created_at', Types::DATETIMETZ_IMMUTABLE);
        $carts->setPrimaryKey(['id']);

        $lines = $schema->createTable('cart_lines');
        $lines->addColumn('cart_id', Types::STRING, ['length' => 32]);
        $lines->addColumn('product_id', Types::STRING, ['length' => 32]);
        $lines->addColumn('quantity', Types::INTEGER);
        $lines->addColumn('unit_price', Types::INTEGER);
        $lines->setPrimaryKey(['cart_id', 'product_id']);

        $orders = $schema->createTable('orders');
        $orders->addColumn('id', Types::STRING, ['length' => 32]);
        $orders->addColumn('number', Types::STRING, ['length' => 32]);
        $orders->addColumn('status', Types::STRING, ['length' => 16]);
        $orders->addColumn('customer_id', Types::STRING, ['length' => 32]);
        $orders->addColumn('total', Types::INTEGER);
        $orders->addColumn('tracking_url', Types::STRING, ['length' => 255, 'notnull' => false]);
        $orders->addColumn('created_at', Types::DATETIMETZ_IMMUTABLE);
        $orders->setPrimaryKey(['id']);
        $orders->addIndex(['status'], 'orders_by_status');

        $orderLines = $schema->createTable('order_lines');
        $orderLines->addColumn('order_id', Types::STRING, ['length' => 32]);
        $orderLines->addColumn('product_id', Types::STRING, ['length' => 32]);
        $orderLines->addColumn('quantity', Types::INTEGER);
        $orderLines->addColumn('unit_price', Types::INTEGER);
        $orderLines->setPrimaryKey(['order_id', 'product_id']);

        $payments = $schema->createTable('payments');
        $payments->addColumn('id', Types::STRING, ['length' => 32]);
        $payments->addColumn('order_id', Types::STRING, ['length' => 32]);
        $payments->addColumn('status', Types::STRING, ['length' => 16]);
        $payments->addColumn('amount', Types::INTEGER);
        $payments->addColumn('paid_at', Types::DATETIMETZ_IMMUTABLE, ['notnull' => false]);
        $payments->setPrimaryKey(['id']);

        $reviews = $schema->createTable('reviews');
        $reviews->addColumn('id', Types::STRING, ['length' => 32]);
        $reviews->addColumn('product_id', Types::STRING, ['length' => 32]);
        $reviews->addColumn('rating', Types::SMALLINT);
        $reviews->addColumn('comment', Types::TEXT);
        $reviews->addColumn('author', Types::STRING, ['length' => 120]);
        $reviews->addColumn('created_at', Types::DATETIMETZ_IMMUTABLE);
        $reviews->setPrimaryKey(['id']);
        $reviews->addIndex(['product_id'], 'reviews_by_product');

        $webhooks = $schema->createTable('webhooks');
        $webhooks->addColumn('id', Types::STRING, ['length' => 32]);
        $webhooks->addColumn('owner', Types::STRING, ['length' => 190]);
        $webhooks->addColumn('url', Types::STRING, ['length' => 255]);
        $webhooks->addColumn('events', Types::JSON);
        $webhooks->setPrimaryKey(['id']);

        $coupons = $schema->createTable('coupons');
        $coupons->addColumn('code', Types::STRING, ['length' => 32]);
        $coupons->addColumn('percent', Types::SMALLINT);
        $coupons->addColumn('expires_at', Types::DATETIMETZ_IMMUTABLE, ['notnull' => false]);
        $coupons->setPrimaryKey(['code']);

        $addresses = $schema->createTable('addresses');
        $addresses->addColumn('id', Types::STRING, ['length' => 32]);
        $addresses->addColumn('owner', Types::STRING, ['length' => 190]);
        $addresses->addColumn('label', Types::STRING, ['length' => 64]);
        $addresses->addColumn('line1', Types::STRING, ['length' => 190]);
        $addresses->addColumn('city', Types::STRING, ['length' => 120]);
        $addresses->addColumn('postal_code', Types::STRING, ['length' => 16]);
        $addresses->addColumn('country', Types::STRING, ['length' => 2]);
        $addresses->setPrimaryKey(['id']);

        $favorites = $schema->createTable('favorites');
        $favorites->addColumn('owner', Types::STRING, ['length' => 190]);
        $favorites->addColumn('product_id', Types::STRING, ['length' => 32]);
        $favorites->setPrimaryKey(['owner', 'product_id']);

        return $schema;
    }

    private function fixtures(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach (self::PRODUCTS as $index => [$name, $category, $price]) {
            $this->insert('products', [
                'id' => 'p_'.($index + 1), 'sku' => \sprintf('SKU-%04d', 1000 + $index * 7), 'name' => $name, 'category' => $category->value,
                'price' => $price, 'stock' => 40 + $index * 13 % 120, 'updated_at' => $now->modify(\sprintf('-%d days', 24 - $index * 2)),
            ]);
        }

        $hasher = $this->hashers->getPasswordHasher(InMemoryUser::class);
        foreach (self::USERS as [$username, $password, $name, $roles]) {
            $this->insert('users', ['username' => $username, 'name' => $name, 'password' => $hasher->hash($password), 'roles' => $roles]);
        }

        foreach (['Ada Lovelace', 'Alan Turing', 'Grace Hopper', 'Katherine Johnson', 'Linus Torvalds', 'Margaret Hamilton'] as $index => $name) {
            $this->insert('customers', ['id' => 'usr_'.(101 + $index), 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'name' => $name, 'banned' => false]);
        }

        for ($i = 1000; $i < 1100; ++$i) {
            $this->insert('carts', ['id' => 'cart_'.$i, 'created_at' => $now->modify(\sprintf('-%d minutes', $i - 990))]);
            $product = $i % 12;
            $this->insert('cart_lines', ['cart_id' => 'cart_'.$i, 'product_id' => 'p_'.($product + 1), 'quantity' => 1 + $i % 3, 'unit_price' => self::PRODUCTS[$product][2]]);
        }

        $statuses = [OrderStatus::Paid, OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Pending, OrderStatus::Cancelled];
        for ($i = 1000; $i < 1200; ++$i) {
            $product = $i % 12;
            $quantity = 1 + $i % 3;
            $status = $statuses[$i % 5];
            $this->insert('orders', [
                'id' => 'ord_'.$i, 'number' => \sprintf('SO-2026-%05d', $i), 'status' => $status->value, 'customer_id' => 'usr_'.(101 + $i % 6),
                'total' => self::PRODUCTS[$product][2] * $quantity, 'tracking_url' => OrderStatus::Shipped === $status ? 'https://track.example.com/ord_'.$i : null,
                'created_at' => $now->modify(\sprintf('-%d hours', 1200 - $i)),
            ]);
            $this->insert('order_lines', ['order_id' => 'ord_'.$i, 'product_id' => 'p_'.($product + 1), 'quantity' => $quantity, 'unit_price' => self::PRODUCTS[$product][2]]);
            if (OrderStatus::Pending !== $status) {
                $this->insert('payments', ['id' => 'pay_'.$i, 'order_id' => 'ord_'.$i, 'status' => 'captured', 'amount' => self::PRODUCTS[$product][2] * $quantity, 'paid_at' => $now->modify(\sprintf('-%d hours', 1199 - $i))]);
            }
        }

        $comments = ['Does what it says, delivered quickly.', 'Solid build, a bit pricey.', 'Stopped working after a week, support was helpful.', 'My kids love it.'];
        for ($i = 1; $i <= 200; ++$i) {
            $this->insert('reviews', [
                'id' => 'rev_'.$i, 'product_id' => 'p_'.($i % 12 + 1), 'rating' => [5, 4, 2, 5, 3][$i % 5], 'comment' => $comments[$i % 4],
                'author' => 'Customer '.(40 + $i), 'created_at' => $now->modify(\sprintf('-%d days', $i % 60)),
            ]);
        }

        $this->insert('coupons', ['code' => 'WELCOME15', 'percent' => 15, 'expires_at' => null]);
        $this->insert('coupons', ['code' => 'BLACKFRI30', 'percent' => 30, 'expires_at' => new \DateTimeImmutable('2026-11-30 23:59:59 UTC')]);
        $this->insert('coupons', ['code' => 'SUMMER2026', 'percent' => 20, 'expires_at' => new \DateTimeImmutable('2026-08-31 23:59:59 UTC')]);
        $this->insert('webhooks', ['id' => 'wh_1', 'owner' => 'acmeerp00001', 'url' => 'https://erp.acme.example/hooks/orders', 'events' => ['order.created', 'order.paid']]);
        $this->insert('webhooks', ['id' => 'wh_2', 'owner' => 'zapierhook01', 'url' => 'https://hooks.zapier.com/demo', 'events' => ['order.created']]);
        $this->insert('addresses', ['id' => 'adr_home', 'owner' => 'ada', 'label' => 'Home', 'line1' => '12 rue des Lilas', 'city' => 'Lyon', 'postal_code' => '69003', 'country' => 'FR']);
        $this->insert('addresses', ['id' => 'adr_work', 'owner' => 'mobileapp001', 'label' => 'Work', 'line1' => '4 place Bellecour', 'city' => 'Lyon', 'postal_code' => '69002', 'country' => 'FR']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): void
    {
        $types = [];
        foreach ($row as $column => $value) {
            $types[$column] = match (true) {
                $value instanceof \DateTimeImmutable => Types::DATETIMETZ_IMMUTABLE,
                \is_array($value) => Types::JSON,
                \is_bool($value) => Types::BOOLEAN,
                default => null,
            };
        }

        $this->connection->insert($table, $row, array_filter($types));
    }
}
