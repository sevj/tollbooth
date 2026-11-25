<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tollbooth\Changes\CurrentContract;
use Tollbooth\Changes\SnapshotStore;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Demo\DemoKeys;
use Tollbooth\Demo\Traffic\TrafficProfile;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\Doctrine\DoctrineCallStorage;

#[AsCommand('demo:seed', 'Create the monitoring tables, fill them with a day of traffic and save two contract snapshots')]
final readonly class SeedCommand
{
    public function __construct(
        private DoctrineCallStorage $storage,
        private EndpointRegistry $registry,
        private CurrentContract $contract,
        private SnapshotStore $snapshots,
        #[Autowire(service: 'doctrine.dbal.monitoring_connection')] private Connection $connection,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Calls per minute at the daily peak')] int $peak = 60): int
    {
        $this->storage->setup();
        foreach (['tollbooth_call', 'tollbooth_call_minute', 'tollbooth_state'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }

        $endpoints = [];
        foreach ($this->registry->all() as $endpoint) {
            $endpoints[substr($endpoint->routeName(), \strlen('tollbooth_'))] = $endpoint;
        }

        $now = time();
        $start = intdiv($now - 86400, 60) * 60;
        $count = 0;
        $this->connection->transactional(function () use ($start, $now, $peak, $endpoints, &$count): void {
            $daily = [];
            for ($minute = $start; $minute < $now; $minute += 60) {
                $hour = (int) gmdate('G', $minute);
                $curve = 0.25 + 0.75 * (0.5 - 0.5 * cos(($hour - 3) / 24 * 2 * M_PI));
                $calls = (int) round($peak * $curve * (0.8 + mt_rand() / mt_getrandmax() * 0.4));
                if (0 === (int) gmdate('i', $minute)) {
                    $calls += $this->burst($minute, $endpoints, $count);
                }

                for ($i = 0; $i < $calls; ++$i) {
                    $consumer = TrafficProfile::consumer();
                    $call = TrafficProfile::call($consumer);
                    if (!isset($endpoints[$call])) {
                        continue;
                    }

                    $day = gmdate('Y-m-d', $minute).$consumer;
                    $daily[$day] = ($daily[$day] ?? 0) + 1;
                    $this->storage->record($this->record($minute + mt_rand(0, 59), $consumer, $call, $endpoints[$call], 'zapier-hook' === $consumer && $daily[$day] > 1000));
                    ++$count;
                }
            }
        });

        $this->storage->rollup($now);
        $io->writeln(\sprintf('%d calls recorded over the last 24 hours.', $count));

        $current = $this->contract->documents();
        $this->snapshots->save('2.4.0', $this->previous($current), new \DateTimeImmutable('2026-11-10 09:12:00 UTC'), true);
        $this->snapshots->save('2.3.0', $this->older($this->previous($current)), new \DateTimeImmutable('2026-10-14 16:40:00 UTC'), true);
        $io->success('Contract snapshots 2.3.0 and 2.4.0 written. Run the demo with: docker compose up demo');
        $io->writeln('API keys:');
        foreach (array_keys(DemoKeys::consumers()) as $consumer) {
            $io->writeln(\sprintf('  %-16s %s', $consumer, DemoKeys::key($consumer)));
        }
        $io->writeln('  admin            HTTP Basic admin:admin');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, EndpointMetadata> $endpoints
     */
    private function burst(int $minute, array $endpoints, int &$count): int
    {
        $burst = mt_rand(620, 720);
        for ($i = 0; $i < $burst; ++$i) {
            $call = 0 === $i % 3 ? 'partner_update_stock' : 'partner_list_catalog';
            $this->storage->record($this->record($minute + intdiv($i * 60, $burst), 'shopify-sync', $call, $endpoints[$call], $i >= 600));
            ++$count;
        }

        return 0;
    }

    private function record(int $at, string $consumer, string $call, EndpointMetadata $endpoint, bool $throttled): CallRecord
    {
        $auth = TrafficProfile::CONSUMERS[$consumer]['auth'];
        $ip = 'anonymous' === $consumer ? '198.51.100.'.mt_rand(2, 250) : '203.0.113.'.(crc32($consumer) % 200 + 10);
        [$status, $error] = $this->outcome($consumer, $call, $throttled);
        $base = TrafficProfile::LATENCY[$call] ?? 35;
        $duration = $status >= 400 && 500 !== $status ? mt_rand(1, 8) + mt_rand() / mt_getrandmax() : $base * exp((mt_rand() / mt_getrandmax() - 0.5) * 1.1);
        if (500 === $status) {
            $duration = 5000 + mt_rand(0, 300);
        }

        $id = DemoKeys::consumers()[$consumer]['id'] ?? null;
        $milliseconds = $at * 1000 + mt_rand(0, 999);

        return new CallRecord(
            id: CallRecord::newId($milliseconds),
            occurredAt: $milliseconds,
            endpoint: $endpoint->routeName(),
            group: $endpoint->group,
            method: $endpoint->method,
            path: '/api'.TrafficProfile::path($endpoint->path),
            status: $status,
            duration: round($duration, 3),
            consumerId: match ($auth) {
                'key' => $id ?? $consumer,
                'user', 'jwt' => $consumer,
                default => 'ip:'.$ip,
            },
            consumerName: 'none' === $auth ? 'ip:'.$ip : $consumer,
            authMethod: match ($auth) {
                'key' => 'api_key',
                'user' => 'user',
                'jwt' => 'jwt',
                default => 'anonymous',
            },
            ip: $ip,
            responseSize: $status >= 400 ? mt_rand(90, 240) : mt_rand(300, 9000),
            error: $error,
            steps: 401 === $status || 429 === $status ? ['auth' => 0.3, 'rate_limit' => 0.4] : [
                'auth' => round(0.2 + mt_rand() / mt_getrandmax() * 0.4, 3),
                'rate_limit' => round(0.2 + mt_rand() / mt_getrandmax() * 0.5, 3),
                'hydrate' => round(0.5 + mt_rand() / mt_getrandmax() * 4, 3),
                'handler' => round($duration * 0.8, 3),
                'serialize' => round(1 + mt_rand() / mt_getrandmax() * 6, 3),
            ],
        );
    }

    /**
     * @return array{int, ?string}
     */
    private function outcome(string $consumer, string $call, bool $throttled): array
    {
        $roll = mt_rand(1, 1000);

        return match (true) {
            'old-integration' === $consumer => [401, 'The API key has expired.'],
            $throttled => [429, 'shopify-sync' === $consumer ? 'Rate limit "minute" reached: 600 requests per minute.' : 'Rate limit "day" reached: 1000 requests per day.'],
            'shop_create_order' === $call && $roll <= 25 => [422, 'items[0].quantity: This value should be positive.'],
            'shop_create_order' === $call && $roll <= 32 => [422, 'shipping_address.postal_code: This value should not be blank.'],
            'shop_add_cart_item' === $call && $roll <= 15 => [404, 'Product "p_99" does not exist.'],
            'shop_get_order' === $call && $roll <= 20 => [404, 'Order not found.'],
            'shop_pay_order' === $call && $roll <= 18 => [500, 'Tollbooth\Demo\PaymentGatewayTimeout'],
            'shop_cancel_order' === $call && $roll <= 150 => [409, 'A shipped order cannot be cancelled.'],
            'partner_update_stock' === $call && $roll <= 30 => [422, 'stock: This value should be either positive or zero.'],
            'sandbox-test' === $consumer && $roll <= 60 => [422, 'customer_id: This value should not be blank.'],
            'shop_create_review' === $call && $roll <= 40 => [422, 'comment: This value is too short. It should have 10 characters or more.'],
            'admin_create_coupon' === $call && $roll <= 80 => [422, 'percent: This value should be between 1 and 90.'],
            'shop_track_shipment' === $call && $roll <= 12 => [429, 'Rate limit "track_shipment.minute" reached: 10 requests per minute.'],
            'reporting-bot' === $consumer && 'backoffice_reply_to_ticket' === $call => [403, 'This endpoint requires the scope "tickets:write".'],
            default => [$this->successStatus($call), null],
        };
    }

    private function successStatus(string $call): int
    {
        return match ($call) {
            'shop_create_cart', 'shop_add_cart_item', 'shop_create_order', 'shop_pay_order', 'partner_create_webhook', 'shop_create_shipment',
            'shop_create_review', 'partner_record_stock_movement', 'mobile_add_address', 'mobile_add_favorite', 'admin_create_coupon',
            'backoffice_reply_to_ticket' => 201,
            'partner_import_orders' => 202,
            'shop_remove_cart_item', 'partner_delete_webhook', 'shop_delete_review', 'mobile_remove_address', 'mobile_remove_favorite',
            'admin_delete_coupon' => 204,
            default => 200,
        };
    }

    /**
     * @param array<string, array<mixed>> $documents
     *
     * @return array<string, array<mixed>>
     */
    private function previous(array $documents): array
    {
        $shop = $documents['shop'] ?? [];
        unset($shop['paths']['/v2/payments/{id}/refund']);
        if (isset($shop['components']['schemas']['ProductViewAttributes']['properties'])) {
            $shop['components']['schemas']['ProductViewAttributes']['properties']['stock'] = ['type' => ['integer', 'null']];
        }
        if (isset($shop['components']['schemas']['OrderViewAttributes']['properties'])) {
            unset($shop['components']['schemas']['OrderViewAttributes']['properties']['tracking_url']);
            $shop['components']['schemas']['OrderViewAttributes']['properties']['status']['enum'] = ['pending', 'paid', 'shipped', 'cancelled'];
        }
        if (isset($shop['paths']['/v2/products/search']['get']['x-rate-limits'][0])) {
            $shop['paths']['/v2/products/search']['get']['x-rate-limits'][0]['limit'] = 20;
        }
        $documents['shop'] = $shop;

        $legacy = $documents['legacy'] ?? [];
        unset($legacy['paths']['/v1/products']['get']['deprecated'], $legacy['paths']['/v1/products']['get']['x-sunset']);
        $documents['legacy'] = $legacy;

        return $documents;
    }

    /**
     * @param array<string, array<mixed>> $documents
     *
     * @return array<string, array<mixed>>
     */
    private function older(array $documents): array
    {
        unset($documents['partner']['paths']['/partner/orders/import'], $documents['mobile']);

        return $documents;
    }
}
