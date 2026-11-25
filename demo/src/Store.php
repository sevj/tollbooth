<?php

declare(strict_types=1);

namespace Tollbooth\Demo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Demo\Model\AddressInput;
use Tollbooth\Demo\Model\AddressView;
use Tollbooth\Demo\Model\CartLineView;
use Tollbooth\Demo\Model\CartView;
use Tollbooth\Demo\Model\Category;
use Tollbooth\Demo\Model\CouponView;
use Tollbooth\Demo\Model\CreateOrderInput;
use Tollbooth\Demo\Model\CustomerView;
use Tollbooth\Demo\Model\Money;
use Tollbooth\Demo\Model\OrderLineView;
use Tollbooth\Demo\Model\OrderStatus;
use Tollbooth\Demo\Model\OrderView;
use Tollbooth\Demo\Model\PaymentStatus;
use Tollbooth\Demo\Model\PaymentView;
use Tollbooth\Demo\Model\ProductView;
use Tollbooth\Demo\Model\Rating;
use Tollbooth\Demo\Model\ReviewView;
use Tollbooth\Demo\Model\StatsView;
use Tollbooth\Demo\Model\WebhookView;

final readonly class Store
{
    public function __construct(
        private Connection $db,
    ) {
    }

    /**
     * @return list<ProductView>
     */
    public function products(?Category $category = null, int $limit = 100, int $offset = 0): array
    {
        $query = $this->db->createQueryBuilder()->select('*')->from('products')->orderBy('id')->setMaxResults($limit)->setFirstResult($offset);
        if (null !== $category) {
            $query->where('category = :category')->setParameter('category', $category->value);
        }

        return array_map($this->product(...), $query->fetchAllAssociative());
    }

    public function findProduct(string $id): ProductView
    {
        $row = $this->db->fetchAssociative('SELECT * FROM products WHERE id = ?', [$id]);

        return false === $row ? throw new NotFoundHttpException(\sprintf('Product "%s" does not exist.', $id)) : $this->product($row);
    }

    public function stock(string $productId): int
    {
        return (int) $this->db->fetchOne('SELECT stock FROM products WHERE id = ?', [$this->findProduct($productId)->id]);
    }

    public function setStock(string $productId, int $stock): void
    {
        $this->findProduct($productId);
        $this->db->update('products', ['stock' => $stock, 'updated_at' => $this->now()], ['id' => $productId], ['updated_at' => Types::DATETIMETZ_IMMUTABLE]);
    }

    public function countProducts(Category $category): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM products WHERE category = ?', [$category->value]);
    }

    public function createCart(): CartView
    {
        $id = 'cart_'.bin2hex(random_bytes(4));
        $this->db->insert('carts', ['id' => $id, 'created_at' => $this->now()], ['created_at' => Types::DATETIMETZ_IMMUTABLE]);

        return $this->cart($id);
    }

    public function cart(string $id): CartView
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM carts WHERE id = ?', [$id])) {
            throw new NotFoundHttpException(\sprintf('Cart "%s" does not exist.', $id));
        }

        $lines = array_map(
            static fn (array $row): CartLineView => new CartLineView((string) $row['product_id'], (int) $row['quantity'], new Money((int) $row['unit_price'])),
            $this->db->fetchAllAssociative('SELECT * FROM cart_lines WHERE cart_id = ? ORDER BY product_id', [$id]),
        );

        return new CartView($id, $lines, new Money(array_sum(array_map(static fn (CartLineView $line): int => $line->unitPrice->amount * $line->quantity, $lines))));
    }

    public function addToCart(string $cartId, string $productId, int $quantity): CartView
    {
        $this->cart($cartId);
        $product = $this->findProduct($productId);
        $updated = $this->db->executeStatement('UPDATE cart_lines SET quantity = quantity + ? WHERE cart_id = ? AND product_id = ?', [$quantity, $cartId, $productId]);
        if (0 === $updated) {
            $this->db->insert('cart_lines', ['cart_id' => $cartId, 'product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $product->price->amount]);
        }

        return $this->cart($cartId);
    }

    public function removeFromCart(string $cartId, string $productId): void
    {
        $this->cart($cartId);
        $this->db->delete('cart_lines', ['cart_id' => $cartId, 'product_id' => $productId]);
    }

    public function createOrder(CreateOrderInput $input): OrderView
    {
        $id = 'ord_'.bin2hex(random_bytes(4));
        $this->db->transactional(function (Connection $db) use ($id, $input): void {
            $total = 0;
            $lines = [];
            foreach ($input->items as $item) {
                $product = $this->findProduct($item->productId);
                $lines[] = ['order_id' => $id, 'product_id' => $product->id, 'quantity' => $item->quantity, 'unit_price' => $product->price->amount];
                $total += $product->price->amount * $item->quantity;
            }

            $db->insert('orders', [
                'id' => $id, 'number' => \sprintf('SO-2026-%05d', crc32($id) % 100000), 'status' => OrderStatus::Pending->value,
                'customer_id' => $input->customerId, 'total' => $total, 'tracking_url' => null, 'created_at' => $this->now(),
            ], ['created_at' => Types::DATETIMETZ_IMMUTABLE]);
            foreach ($lines as $line) {
                $db->executeStatement('INSERT INTO order_lines (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?) ON CONFLICT (order_id, product_id) DO UPDATE SET quantity = order_lines.quantity + EXCLUDED.quantity', array_values($line));
            }
        });

        return $this->order($id);
    }

    public function order(string $id): OrderView
    {
        $row = $this->db->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id]);
        if (false === $row) {
            throw new NotFoundHttpException(\sprintf('Order "%s" does not exist.', $id));
        }

        return $this->orderView($row);
    }

    /**
     * @return list<OrderView>
     */
    public function orders(?OrderStatus $status, int $page): array
    {
        $query = $this->db->createQueryBuilder()->select('*')->from('orders')->orderBy('created_at', 'DESC')->setMaxResults(20)->setFirstResult(($page - 1) * 20);
        if (null !== $status) {
            $query->where('status = :status')->setParameter('status', $status->value);
        }

        return array_map($this->orderView(...), $query->fetchAllAssociative());
    }

    public function cancelOrder(string $id): OrderView
    {
        $order = $this->order($id);
        if (\in_array($order->status, [OrderStatus::Shipped, OrderStatus::Refunded], true)) {
            throw new ConflictHttpException(\sprintf('A %s order cannot be cancelled.', $order->status->value));
        }

        $this->db->update('orders', ['status' => OrderStatus::Cancelled->value], ['id' => $id]);

        return $this->order($id);
    }

    public function pay(string $orderId): PaymentView
    {
        $order = $this->order($orderId);
        if (OrderStatus::Pending !== $order->status) {
            throw new ConflictHttpException(\sprintf('Order "%s" is %s and cannot be paid.', $orderId, $order->status->value));
        }

        $id = 'pay_'.substr($orderId, 4);
        $this->db->transactional(function (Connection $db) use ($id, $order): void {
            $db->insert('payments', ['id' => $id, 'order_id' => $order->id, 'status' => PaymentStatus::Captured->value, 'amount' => $order->total->amount, 'paid_at' => $this->now()], ['paid_at' => Types::DATETIMETZ_IMMUTABLE]);
            $db->update('orders', ['status' => OrderStatus::Paid->value], ['id' => $order->id]);
        });

        return $this->payment($id);
    }

    public function refund(string $paymentId, ?int $amount): PaymentView
    {
        $payment = $this->payment($paymentId);
        if (PaymentStatus::Refunded === $payment->status) {
            throw new ConflictHttpException(\sprintf('Payment "%s" is already refunded.', $paymentId));
        }

        $this->db->transactional(function (Connection $db) use ($payment, $amount): void {
            $db->update('payments', ['status' => PaymentStatus::Refunded->value, 'amount' => $amount ?? $payment->amount->amount], ['id' => $payment->id]);
            $db->update('orders', ['status' => OrderStatus::Refunded->value], ['id' => $payment->orderId]);
        });

        return $this->payment($paymentId);
    }

    public function payment(string $id): PaymentView
    {
        $row = $this->db->fetchAssociative('SELECT * FROM payments WHERE id = ?', [$id]);
        if (false === $row) {
            throw new NotFoundHttpException(\sprintf('Payment "%s" does not exist.', $id));
        }

        return new PaymentView((string) $row['id'], (string) $row['order_id'], PaymentStatus::from((string) $row['status']), new Money((int) $row['amount']), self::date($row['paid_at']));
    }

    /**
     * @return list<ReviewView>
     */
    public function reviews(string $productId, ?Rating $minimum): array
    {
        $this->findProduct($productId);

        return array_map(
            static fn (array $row): ReviewView => new ReviewView((string) $row['id'], (string) $row['product_id'], Rating::from((int) $row['rating']), (string) $row['comment'], (string) $row['author'], self::date($row['created_at']) ?? new \DateTimeImmutable()),
            $this->db->fetchAllAssociative('SELECT * FROM reviews WHERE product_id = ? AND rating >= ? ORDER BY created_at DESC LIMIT 20', [$productId, $minimum->value ?? 1]),
        );
    }

    public function addReview(string $productId, Rating $rating, string $comment, string $author): ReviewView
    {
        $this->findProduct($productId);
        $id = 'rev_'.bin2hex(random_bytes(4));
        $this->db->insert('reviews', ['id' => $id, 'product_id' => $productId, 'rating' => $rating->value, 'comment' => $comment, 'author' => $author, 'created_at' => $this->now()], ['created_at' => Types::DATETIMETZ_IMMUTABLE]);

        return new ReviewView($id, $productId, $rating, $comment, $author, $this->now());
    }

    public function deleteReview(string $id): void
    {
        if (0 === $this->db->delete('reviews', ['id' => $id])) {
            throw new NotFoundHttpException(\sprintf('Review "%s" does not exist.', $id));
        }
    }

    /**
     * @return list<WebhookView>
     */
    public function webhooks(string $owner): array
    {
        return array_map(
            static fn (array $row): WebhookView => new WebhookView((string) $row['id'], (string) $row['url'], self::strings($row['events'])),
            $this->db->fetchAllAssociative('SELECT * FROM webhooks WHERE owner = ? ORDER BY id', [$owner]),
        );
    }

    /**
     * @param list<string> $events
     */
    public function addWebhook(string $owner, string $url, array $events): WebhookView
    {
        $id = 'wh_'.bin2hex(random_bytes(3));
        $this->db->insert('webhooks', ['id' => $id, 'owner' => $owner, 'url' => $url, 'events' => $events], ['events' => Types::JSON]);

        return new WebhookView($id, $url, $events);
    }

    public function deleteWebhook(string $owner, string $id): void
    {
        if (0 === $this->db->delete('webhooks', ['id' => $id, 'owner' => $owner])) {
            throw new NotFoundHttpException(\sprintf('Webhook "%s" does not exist.', $id));
        }
    }

    /**
     * @return list<CouponView>
     */
    public function coupons(): array
    {
        return array_map($this->coupon(...), $this->db->fetchAllAssociative('SELECT * FROM coupons ORDER BY code'));
    }

    public function findCoupon(string $code): ?CouponView
    {
        $row = $this->db->fetchAssociative('SELECT * FROM coupons WHERE code = ?', [$code]);

        return false === $row ? null : $this->coupon($row);
    }

    public function addCoupon(string $code, int $percent, ?\DateTimeImmutable $expiresAt): CouponView
    {
        if (null !== $this->findCoupon($code)) {
            throw new ConflictHttpException(\sprintf('The coupon "%s" already exists.', $code));
        }

        $this->db->insert('coupons', ['code' => $code, 'percent' => $percent, 'expires_at' => $expiresAt], ['expires_at' => Types::DATETIMETZ_IMMUTABLE]);

        return $this->findCoupon($code) ?? throw new \LogicException();
    }

    public function deleteCoupon(string $code): void
    {
        if (0 === $this->db->delete('coupons', ['code' => $code])) {
            throw new NotFoundHttpException(\sprintf('Coupon "%s" does not exist.', $code));
        }
    }

    /**
     * @return list<CustomerView>
     */
    public function customers(): array
    {
        return array_map(
            static fn (array $row): CustomerView => new CustomerView((string) $row['id'], (string) $row['email'], (string) $row['name'], (bool) $row['banned']),
            $this->db->fetchAllAssociative('SELECT * FROM customers ORDER BY id'),
        );
    }

    public function ban(string $id): CustomerView
    {
        if (0 === $this->db->update('customers', ['banned' => true], ['id' => $id], ['banned' => Types::BOOLEAN])) {
            throw new NotFoundHttpException(\sprintf('Customer "%s" does not exist.', $id));
        }

        foreach ($this->customers() as $customer) {
            if ($customer->id === $id) {
                return $customer;
            }
        }

        throw new \LogicException();
    }

    /**
     * @return list<AddressView>
     */
    public function addresses(string $owner): array
    {
        return array_map(
            static fn (array $row): AddressView => new AddressView((string) $row['id'], (string) $row['label'], (string) $row['line1'], (string) $row['city'], (string) $row['postal_code'], (string) $row['country']),
            $this->db->fetchAllAssociative('SELECT * FROM addresses WHERE owner = ? ORDER BY id', [$owner]),
        );
    }

    public function addAddress(string $owner, AddressInput $input): AddressView
    {
        $id = 'adr_'.bin2hex(random_bytes(3));
        $this->db->insert('addresses', ['id' => $id, 'owner' => $owner, 'label' => 'Other', 'line1' => $input->line1, 'city' => $input->city, 'postal_code' => $input->postalCode, 'country' => $input->country]);

        return new AddressView($id, 'Other', $input->line1, $input->city, $input->postalCode, $input->country);
    }

    public function deleteAddress(string $owner, string $id): void
    {
        if (0 === $this->db->delete('addresses', ['id' => $id, 'owner' => $owner])) {
            throw new NotFoundHttpException(\sprintf('Address "%s" does not exist.', $id));
        }
    }

    /**
     * @return list<ProductView>
     */
    public function favorites(string $owner): array
    {
        return array_map($this->product(...), $this->db->fetchAllAssociative('SELECT p.* FROM favorites f JOIN products p ON p.id = f.product_id WHERE f.owner = ? ORDER BY p.id', [$owner]));
    }

    public function addFavorite(string $owner, string $productId): ProductView
    {
        $product = $this->findProduct($productId);
        $this->db->executeStatement('INSERT INTO favorites (owner, product_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$owner, $productId]);

        return $product;
    }

    public function removeFavorite(string $owner, string $productId): void
    {
        $this->db->delete('favorites', ['owner' => $owner, 'product_id' => $productId]);
    }

    public function stats(): StatsView
    {
        $today = $this->now()->setTime(0, 0);
        $orders = $this->db->fetchAssociative('SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS revenue FROM orders WHERE created_at >= ? AND status <> ?', [$today, OrderStatus::Cancelled->value], [Types::DATETIMETZ_IMMUTABLE]) ?: ['n' => 0, 'revenue' => 0];

        return new StatsView(
            (int) $orders['n'],
            new Money((int) $orders['revenue']),
            (int) $this->db->fetchOne('SELECT COUNT(DISTINCT cart_id) FROM cart_lines'),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM customers'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function product(array $row): ProductView
    {
        return new ProductView(
            (string) $row['id'],
            (string) $row['sku'],
            (string) $row['name'],
            Category::from((string) $row['category']),
            new Money((int) $row['price']),
            self::date($row['updated_at']) ?? new \DateTimeImmutable(),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function orderView(array $row): OrderView
    {
        $lines = array_map(
            static fn (array $line): OrderLineView => new OrderLineView((string) $line['product_id'], (int) $line['quantity'], new Money((int) $line['unit_price'])),
            $this->db->fetchAllAssociative('SELECT * FROM order_lines WHERE order_id = ? ORDER BY product_id', [$row['id']]),
        );

        return new OrderView(
            id: (string) $row['id'],
            number: (string) $row['number'],
            status: OrderStatus::from((string) $row['status']),
            customerId: (string) $row['customer_id'],
            lines: $lines,
            total: new Money((int) $row['total']),
            createdAt: self::date($row['created_at']) ?? new \DateTimeImmutable(),
            trackingUrl: null === $row['tracking_url'] ? null : (string) $row['tracking_url'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function coupon(array $row): CouponView
    {
        $expiresAt = self::date($row['expires_at']);

        return new CouponView('cpn_'.strtolower((string) $row['code']), (string) $row['code'], (int) $row['percent'], null === $expiresAt || $expiresAt > $this->now(), $expiresAt);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $json): array
    {
        $decoded = \is_string($json) ? json_decode($json, true) : $json;

        return \is_array($decoded) ? array_values(array_filter($decoded, \is_string(...))) : [];
    }
}
