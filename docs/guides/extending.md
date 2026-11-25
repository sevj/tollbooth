# Extending Tollbooth

Tollbooth exposes a few interfaces and value objects that host applications implement or call; they are listed in the *Public API* section of the README and follow semantic versioning. This guide shows each extension point with a complete example: storing API keys in your own table, adding health checks and findings, adding members to problem details, reading the monitoring data, and decorating the call recorder. Anything not listed here is internal (`@internal`) and may change in any release.

- [Your own API key storage](#your-own-api-key-storage)
- [Health checks](#health-checks)
- [Findings](#findings)
- [Extra members in problem details](#extra-members-in-problem-details)
- [Reading monitoring data](#reading-monitoring-data)
- [Decorating the call recorder](#decorating-the-call-recorder)
- [The consumer inside an endpoint](#the-consumer-inside-an-endpoint)
- [Public extension points at a glance](#public-extension-points-at-a-glance)

## Your own API key storage

By default, API keys come from `tollbooth.auth.api_keys` in the configuration, and optionally from Tollbooth's own table (`auth.doctrine_keys`). To read them from anywhere else (an existing table, a tenant service, a secrets manager), implement one of:

| Interface | Methods | What it enables |
| --- | --- | --- |
| `Tollbooth\Auth\ApiKey\ApiKeyProviderInterface` | `find(string $id): ?ApiKey` | Authentication |
| `Tollbooth\Auth\ApiKey\ListableApiKeyProviderInterface` (extends the above) | `all(): iterable<ApiKey>` | Also: health findings on expired and expiring keys, key counts on the dashboard (map, consumers, plans) |
| `Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface` (extends Listable) | `save(ApiKey)`, `remove(string $id)`, `manages(string $id): bool` | The contract of a writable store. **Today the dashboard's key management and `tollbooth:api-key:generate --store` only use Tollbooth's own table** (`auth.doctrine_keys`); a custom provider that implements this interface is not wired into them. |

Then point the configuration at your service:

```yaml
# config/packages/tollbooth.yaml
tollbooth:
    auth:
        api_key_provider: App\Security\PartnerKeyProvider   # service id
```

When `api_key_provider` is set, it **replaces** the built-in providers for authentication: keys under `tollbooth.auth.api_keys` and in the `doctrine_keys` table are no longer looked up. Do not combine it with `doctrine_keys`.

### Key format and hash

The format of a key is public API: `tb_live_` or `tb_test_`, then a 12-character id (`[a-z0-9]`), `_`, and a 32-character secret (`[A-Za-z0-9]`):

```
tb_live_qi9ywjy0lp8x_Xb3kQ9vT2mLr8NwZ5cHy1pDf7GsJ4aUe
        └── id ────┘ └──────────── secret ────────────┘
```

Tollbooth parses the key from the `tollbooth.auth.api_key_header` header (`X-Api-Key` by default), calls `find($id)`, and accepts the key when the SHA-256 hex digest of the **secret part** equals `ApiKey::$hash` (constant-time comparison), the key has not expired (`expiresAt`) and the client IP matches `allowedIps` when it is not empty. Your provider never sees the secret.

To issue a key for your own storage, generate it with the command (without `--store`) and save the printed `id` and `hash`:

```
bin/console tollbooth:api-key:generate acme-erp --scope=orders:read --plan=pro
```

### Example: keys in an existing table

```php
// src/Security/PartnerKeyProvider.php
namespace App\Security;

use Doctrine\DBAL\Connection;
use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Auth\ApiKey\ListableApiKeyProviderInterface;

final readonly class PartnerKeyProvider implements ListableApiKeyProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $id): ?ApiKey
    {
        $row = $this->connection->fetchAssociative(
            'SELECT key_id, partner_name, secret_sha256, scopes, plan, expires_at, allowed_ips, created_at, is_test FROM partner_api_key WHERE key_id = ? AND revoked_at IS NULL',
            [$id],
        );

        return false === $row ? null : self::key($row);
    }

    public function all(): iterable
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT key_id, partner_name, secret_sha256, scopes, plan, expires_at, allowed_ips, created_at, is_test FROM partner_api_key WHERE revoked_at IS NULL ORDER BY partner_name',
        );
        foreach ($rows as $row) {
            yield self::key($row);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function key(array $row): ApiKey
    {
        return new ApiKey(
            id: (string) $row['key_id'],
            name: (string) $row['partner_name'],
            hash: (string) $row['secret_sha256'],
            scopes: self::list($row['scopes']),
            plan: null === $row['plan'] ? null : (string) $row['plan'],
            expiresAt: null === $row['expires_at'] ? null : new \DateTimeImmutable((string) $row['expires_at']),
            allowedIps: self::list($row['allowed_ips']),
            createdAt: null === $row['created_at'] ? null : new \DateTimeImmutable((string) $row['created_at']),
            test: (bool) $row['is_test'],
        );
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $json): array
    {
        $decoded = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($decoded) ? array_values(array_filter($decoded, \is_string(...))) : [];
    }
}
```

`ApiKey` is a public value object:

| Property | Type | Meaning |
| --- | --- | --- |
| `id` | `string` | The 12-character id from the key |
| `name` | `string` | Consumer name, shown in monitoring and on the dashboard |
| `hash` | `string` | SHA-256 hex of the secret part |
| `scopes` | `list<string>` | Scopes granted |
| `plan` | `?string` | Rate limit plan, `null` for `default_plan` |
| `expiresAt` | `?\DateTimeImmutable` | Refused from this instant |
| `allowedIps` | `list<string>` | Addresses or CIDR ranges; empty accepts any |
| `createdAt` | `?\DateTimeImmutable` | Informational |
| `test` | `bool` | Issued with the `tb_test_` prefix (informational: the prefix is not checked against it) |

The consumer of a call authenticated this way is `new Consumer($key->id, $key->name, AuthMethod::ApiKey, $key->scopes, $key->plan)`. See [authentication.md](../authentication.md).

`find()` runs on every call that presents a key: keep it to one indexed lookup, or cache it yourself.

## Health checks

A check is a service implementing `Tollbooth\Core\Health\HealthCheckInterface`. With autoconfiguration (the default in `config/services.yaml`), it is picked up through the `tollbooth.health_check` tag; otherwise add the tag by hand. Checks run on every call to `/_tollbooth/health/ready` (which lists them only to a caller sending the token), `/_tollbooth/health.json`, `bin/console tollbooth:health` and the dashboard's Health screen. See [health.md](../health.md).

```php
// src/Health/PaymentGatewayCheck.php
namespace App\Health;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\HealthCheckInterface;

final readonly class PaymentGatewayCheck implements HealthCheckInterface
{
    public function __construct(private HttpClientInterface $paymentGateway)
    {
    }

    public function name(): string
    {
        return 'payment_gateway';
    }

    public function check(): CheckResult
    {
        $start = microtime(true);
        $status = $this->paymentGateway->request('GET', '/status', ['timeout' => 2])->getStatusCode();
        $ms = (microtime(true) - $start) * 1000;

        return match (true) {
            200 !== $status => CheckResult::critical(\sprintf('GET /status answered %d.', $status)),
            $ms > 1000 => CheckResult::warning(\sprintf('GET /status answered 200 in %.1f s.', $ms / 1000)),
            default => CheckResult::ok(\sprintf('GET /status answered 200 in %d ms.', $ms)),
        };
    }
}
```

| Result | Overall status | `/ready` |
| --- | --- | --- |
| `CheckResult::ok($summary)` | `ok` | 200 |
| `CheckResult::warning($summary)` | at least `degraded` | 200 |
| `CheckResult::critical($summary)` | `critical` | 503 |

- A check that throws is reported `critical` with the exception class only, never its message.
- `name()` is shown in the report; keep it stable, monitoring tools may key on it.
- The summary is visible to whoever reads the report: do not put secrets in it.
- Checks run on every readiness probe, token or not: keep them fast and give network calls a short timeout.
- The interface and `CheckResult`, `HealthStatus` (`ok`, `warning`, `critical`) live in `Tollbooth\Core\Health` and are public API. The registration needs `tollbooth.health.enabled` (the default).

## Findings

A finding points at something worth a look without changing the status. Implement `Tollbooth\Core\Health\FindingProviderInterface`; autoconfigured services get the `tollbooth.finding_provider` tag.

```php
// src/Health/FailedWebhookFindings.php
namespace App\Health;

use Doctrine\DBAL\Connection;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\FindingProviderInterface;

final readonly class FailedWebhookFindings implements FindingProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findings(): iterable
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT partner, COUNT(*) AS failures FROM webhook_delivery WHERE failed_at > ? GROUP BY partner HAVING COUNT(*) >= 10',
            [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')],
        );

        foreach ($rows as $row) {
            $failures = (int) $row['failures'];
            yield new Finding(
                \sprintf('%d webhook deliveries to %s failed in the last hour.', $failures, $row['partner']),
                warning: $failures >= 100,
            );
        }
    }
}
```

`new Finding(string $message, bool $warning = false)`: a warning is highlighted in the report and on the dashboard. Findings appear in `/_tollbooth/health.json`, `tollbooth:health` and the dashboard, not in `/ready`.

## Extra members in problem details

Every error of an endpoint is an RFC 9457 problem. An exception that implements `Tollbooth\Core\Problem\ProblemMembersInterface` adds its own members to the problem body. Combine it with Symfony's `HttpExceptionInterface` (for instance by extending one of the `*HttpException` classes) to choose the status and headers; any other exception answers 500.

```php
// src/Catalog/OutOfStockException.php
namespace App\Catalog;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tollbooth\Core\Problem\ProblemMembersInterface;

final class OutOfStockException extends ConflictHttpException implements ProblemMembersInterface
{
    public function __construct(
        private readonly string $sku,
        private readonly int $available,
    ) {
        parent::__construct(\sprintf('Only %d of %s left.', $available, $sku));
    }

    public function problemMembers(): array
    {
        return ['sku' => $this->sku, 'available' => $this->available];
    }
}
```

```php
#[Endpoint('POST', '/orders', status: 201)]
final readonly class CreateOrder
{
    public function __construct(private Orders $orders)
    {
    }

    public function __invoke(#[Body] CreateOrderInput $input): OrderView
    {
        return OrderView::from($this->orders->place($input)); // throws OutOfStockException
    }
}
```

```http
HTTP/1.1 409 Conflict
Content-Type: application/problem+json

{"type":"about:blank","title":"Conflict","status":409,"detail":"Only 2 of GATE-01 left.","sku":"GATE-01","available":2}
```

Rules:

- Members named `type`, `title`, `status`, `detail`, `instance` or `violations` are ignored: those are set by Tollbooth.
- Member names are sent as you write them; the group's `output.case` is not applied to them.
- `detail` is the exception message for statuses below 500. For 500 and above, it is only sent in debug mode.
- The headers of an `HttpExceptionInterface` (`getHeaders()`) are sent with the problem.
- `Tollbooth\RateLimit\RateLimitExceededException` is the built-in example: its `limiters` member is the list of limiter states.

The problem shape is part of the HTTP contract; see [http-contract.md](../http-contract.md).

## Reading monitoring data

With `tollbooth.monitoring.enabled`, the application can read what the dashboard reads through two public interfaces, autowirable by their names. They are only registered when monitoring is enabled. See [monitoring.md](../monitoring.md).

### `Tollbooth\Monitoring\CallReaderInterface`

| Method | Returns |
| --- | --- |
| `recent(CallQuery $query): list<CallRecord>` | The latest calls, newest first, at most `$query->limit` (capped at 500) |
| `series(int $from, int $to, ?CallQuery $filter = null): array<int, Stats>` | One `Stats` per minute that had calls, keyed by the minute's Unix timestamp |
| `totals(int $from, int $to, 'endpoint'\|'consumer' $by, ?CallQuery $filter = null): list<Stats>` | One `Stats` per endpoint or consumer, most calls first |

`$from` and `$to` are Unix timestamps in **seconds**; `$from` is rounded down to the minute. Finished minutes are read from the aggregates, the current ones from the raw calls.

### `Tollbooth\Monitoring\CallQuery`

| Property | Used by | Meaning |
| --- | --- | --- |
| `endpoint` | all | Route name: `tollbooth_<group>_<name>`, or `tollbooth_<name>` in the default group |
| `group` | all | Group name |
| `consumerId` | all | Consumer id: API key id, user identifier, or `ip:<address>` for anonymous calls |
| `statusClass` | `recent` | `2`, `4`, `5`… for 2xx, 4xx, 5xx |
| `status` | `recent` | One status code |
| `minDuration` | `recent` | Minimum duration in milliseconds |
| `since` | `recent` | Unix timestamp in **milliseconds** |
| `before`, `after` | `recent` | Cursor: a `CallRecord::$id` (ids sort by time) |
| `id` | `recent` | One call |
| `limit` | `recent` | Default 50, at most 500 |

### `Tollbooth\Monitoring\Stats` and `CallRecord`

`Stats` has `key` (endpoint route name, consumer id or minute), `label` (the consumer name for consumer totals, the group for endpoint totals), `calls`, `clientErrors` (4xx except 429), `serverErrors`, `throttled` (429), `durationSum` and `durationMax` (ms), and the methods `errorRate()` and `percentile(float $p)` (estimated from a latency histogram, in ms, `null` without calls).

`CallRecord` has `id`, `occurredAt` (Unix timestamp in **milliseconds**), `endpoint`, `group`, `method`, `path`, `status`, `duration` (ms), `consumerId`, `consumerName`, `authMethod`, `ip`, `responseSize`, `error`, `steps` (ms per pipeline step) and `limiters`.

### Example: a daily usage report

```php
// src/Command/UsageReportCommand.php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;

#[AsCommand('app:api-usage', 'Calls per consumer of the partner API over the last 24 hours')]
final readonly class UsageReportCommand
{
    public function __construct(private CallReaderInterface $calls)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $now = time();
        $rows = [];
        foreach ($this->calls->totals($now - 86400, $now + 60, 'consumer', new CallQuery(group: 'partner')) as $stats) {
            $p95 = $stats->percentile(0.95);
            $rows[] = [
                $stats->label,
                $stats->calls,
                \sprintf('%.2f %%', $stats->errorRate() * 100),
                $stats->throttled,
                null === $p95 ? '-' : \sprintf('%d ms', $p95),
            ];
        }
        $io->table(['consumer', 'calls', 'errors', '429', 'p95'], $rows);

        $failures = $this->calls->recent(new CallQuery(group: 'partner', statusClass: 5, since: ($now - 3600) * 1000, limit: 20));
        foreach ($failures as $call) {
            $io->writeln(\sprintf('%s %s %s %d %s (%s)', date('H:i:s', intdiv($call->occurredAt, 1000)), $call->method, $call->path, $call->status, $call->error ?? '', $call->consumerName));
        }

        return Command::SUCCESS;
    }
}
```

### `Tollbooth\Monitoring\CallBreakdownInterface`

| Method | Returns |
| --- | --- |
| `statuses(int $from, int $to, ?CallQuery $filter = null): array<int, int>` | Calls per status code |
| `errors(int $from, int $to, ?CallQuery $filter = null, int $limit = 10): list<ErrorGroup>` | Errors grouped by status and recorded error, each `ErrorGroup` with `status`, `error`, `calls`, `consumers` and `lastSeenAt` |

```php
$byStatus = $breakdown->statuses(time() - 3600, time() + 60, new CallQuery(endpoint: 'tollbooth_partner_create_order'));
// [201 => 1840, 422 => 37, 429 => 5]
```

## Decorating the call recorder

`Tollbooth\Monitoring\CallRecorderInterface::record(CallRecord $call): void` stores each call after the response is sent. Decorate it to send calls elsewhere as well, with Symfony's service decoration:

```php
// src/Monitoring/SlowCallLogger.php
namespace App\Monitoring;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Tollbooth\Monitoring\CallRecord;
use Tollbooth\Monitoring\CallRecorderInterface;

#[AsDecorator(CallRecorderInterface::class)]
final readonly class SlowCallLogger implements CallRecorderInterface
{
    public function __construct(
        #[AutowireDecorated] private CallRecorderInterface $inner,
        private LoggerInterface $logger,
    ) {
    }

    public function record(CallRecord $call): void
    {
        $this->inner->record($call);

        if ($call->duration > 1000) {
            $this->logger->warning('Slow API call {method} {path}: {duration} ms for {consumer}.', [
                'method' => $call->method,
                'path' => $call->path,
                'duration' => $call->duration,
                'consumer' => $call->consumerName,
            ]);
        }
    }
}
```

- Always call the inner recorder, or the dashboard and the health checks lose the calls.
- `record()` runs on `kernel.terminate`, after the response is sent; an exception there is caught and logged by Tollbooth, the client is not affected.
- `CallRecorderInterface` is only registered when monitoring is enabled; with monitoring off, the decoration above fails the container build. In an application where monitoring is off in some environments, write `#[AsDecorator(CallRecorderInterface::class, onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]` (with `use Symfony\Component\DependencyInjection\ContainerInterface;`) so that Symfony drops the decorator where there is nothing to decorate.

## The consumer inside an endpoint

Not an extension point but often what extensions need: an endpoint can take the authenticated consumer as an argument.

```php
use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Security\AuthMethod;

#[Endpoint('GET', '/me')]
final readonly class WhoAmI
{
    public function __invoke(Consumer $consumer): array
    {
        return [
            'id' => $consumer->id,
            'name' => $consumer->name,
            'method' => $consumer->method->value,     // api_key, jwt, user or anonymous
            'scopes' => $consumer->scopes,
            'plan' => $consumer->plan,
            'via_key' => AuthMethod::ApiKey === $consumer->method,
        ];
    }
}
```

`Consumer` (`id`, `name`, `method`, `scopes`, `plan`) and the `AuthMethod` enum are public API. Asking for a `Consumer` outside an endpoint throws, unless the argument is nullable. See [authentication.md](../authentication.md).

## Public extension points at a glance

| Extension point | Namespace | How it is registered |
| --- | --- | --- |
| `ApiKeyProviderInterface`, `ListableApiKeyProviderInterface`, `ManageableApiKeyProviderInterface`, `ApiKey` | `Tollbooth\Auth\ApiKey` | `tollbooth.auth.api_key_provider: <service id>` |
| `HealthCheckInterface`, `CheckResult`, `HealthStatus` | `Tollbooth\Core\Health` | Autoconfiguration, tag `tollbooth.health_check` |
| `FindingProviderInterface`, `Finding` | `Tollbooth\Core\Health` | Autoconfiguration, tag `tollbooth.finding_provider` |
| `ProblemMembersInterface` | `Tollbooth\Core\Problem` | Implemented by an exception |
| `CallReaderInterface`, `CallBreakdownInterface`, `CallQuery`, `Stats`, `CallRecord`, `ErrorGroup` | `Tollbooth\Monitoring` | Autowiring (monitoring enabled) |
| `CallRecorderInterface`, `RollupInterface` | `Tollbooth\Monitoring` | Autowiring or decoration (monitoring enabled) |
| `Consumer`, `AuthMethod` | `Tollbooth\Auth`, `Tollbooth\Core\Security` | Endpoint argument |
| `RateLimitExceededException` | `Tollbooth\RateLimit` | Thrown by Tollbooth on 429 |

Adding a method to one of these interfaces without a default implementation is a breaking change under the project's versioning policy; see the README.
