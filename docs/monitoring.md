# Monitoring

With monitoring on, Tollbooth records every call to an endpoint once the response has been sent: which endpoint, who called it, the status, how long each step took and the rate limit state, but never a body, a request header or a query string. Calls go into an append-only table through Doctrine DBAL (PostgreSQL, MySQL and SQLite are supported); finished minutes are aggregated in batch into per-minute counters with a latency histogram, and old rows are purged after a retention period. The dashboard, the health findings and the contract diff read these tables through public interfaces the application can use too.

## Contents

- [Enabling monitoring](#enabling-monitoring)
- [What is recorded](#what-is-recorded)
- [What is never stored](#what-is-never-stored)
- [Storage](#storage)
- [Aggregation and retention](#aggregation-and-retention)
- [Reading the data](#reading-the-data)
- [Performance](#performance)
- [Health checks and findings](#health-checks-and-findings)

## Enabling monitoring

```
composer require doctrine/doctrine-bundle
```

```yaml
tollbooth:
    monitoring:
        enabled: true
        connection: default              # DBAL connection name, or a service id containing a dot
        table_prefix: tollbooth_
        calls_retention_days: 7
        aggregates_retention_days: 30
        auto_maintenance: true
        cache_pool: cache.app
```

```
bin/console tollbooth:monitoring:setup              # create or update the tables
bin/console tollbooth:monitoring:setup --dump-sql   # print the SQL, to put in a migration
```

Monitoring is off by default. Writing any key under `monitoring` turns it on. Without `doctrine/dbal` the container build fails with *Tollbooth monitoring stores calls with Doctrine DBAL. Run "composer require doctrine/dbal" (or doctrine/doctrine-bundle), or disable tollbooth.monitoring.* A connection name without a dot refers to DoctrineBundle's `doctrine.dbal.<name>_connection` service.

Under heavy traffic, give monitoring its own database so that it never competes with the application's queries:

```yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(DATABASE_URL)%'
            monitoring:
                url: '%env(MONITORING_DATABASE_URL)%'

tollbooth:
    monitoring:
        connection: monitoring
```

If the tables do not exist, recording fails silently (an error is logged, the API answers normally) and the `monitoring` health check turns critical.

## What is recorded

One `Tollbooth\Monitoring\CallRecord` per request routed to an endpoint, whatever its outcome: success, validation error, 401, 403, 429 or 500. Requests that match no endpoint (a 404 from the router) are not recorded.

| Field | Type | Content |
| --- | --- | --- |
| `id` | `string` | 26 hexadecimal characters: the time in milliseconds (12) then 7 random bytes (14). Sorting by id sorts by time. |
| `occurredAt` | `int` | Unix time in **milliseconds** when the call was recorded, after the response was sent. |
| `endpoint` | `string` | The endpoint's route name: `tollbooth_<name>`, or `tollbooth_<group>_<name>` outside the default group. |
| `group` | `string` | Group name, `default` for endpoints in no group. |
| `method` | `string` | HTTP method. |
| `path` | `string` | Request path without the query string, truncated to 1024 characters. |
| `status` | `int` | Response status code. |
| `duration` | `float` | Milliseconds from `REQUEST_TIME_FLOAT` (when PHP received the request) to the moment the response was ready, before sending. `0.0` when `REQUEST_TIME_FLOAT` is missing. |
| `consumerId` | `string` | The [consumer](authentication.md#the-consumer) id, or `ip:<client IP>` when the call was refused before a consumer was known. Truncated to 190 characters. |
| `consumerName` | `string` | The consumer name, same fallback. Truncated to 190 characters. |
| `authMethod` | `string` | `api_key`, `jwt`, `user`, `anonymous`, or `none` when authentication failed. |
| `ip` | `?string` | `Request::getClientIp()`. |
| `responseSize` | `?int` | Length of the response body in bytes: the file size for a `BinaryFileResponse`, `null` for a `StreamedResponse`. |
| `error` | `?string` | For a status of 400 or more raised as an exception: the first violation as `field: message` for a validation error, otherwise the problem's `detail` or `title`. For a status of 500 or more: the exception class only. At most 255 characters. `null` otherwise. |
| `steps` | `array<string, float>` | Milliseconds spent in each step that ran: `auth`, `rate_limit`, `hydrate`, `handler`, `serialize`. A call refused at authentication has only `auth`. |
| `limiters` | `?list<array{name, limit, window, remaining, reset}>` | Each rate limit as sent in the `RateLimit-Policy` and `RateLimit` response headers; `null` when the response had none. |

`CallRecord::minute()` returns the start of the call's minute, in seconds. `CallRecord::newId(int $occurredAt)` builds an id from a millisecond timestamp.

Examples of `error`:

| Call | `status` | `error` |
| --- | --- | --- |
| Body fails validation | 422 | `name: This value should not be blank.` |
| Endpoint throws `NotFoundHttpException('Thing 404 does not exist.')` | 404 | `Thing 404 does not exist.` |
| Missing API key | 401 | `Authentication is required to access this endpoint.` |
| Endpoint throws `RuntimeException('Connection refused to 10.0.0.12')` | 500 | `RuntimeException` |

## What is never stored

- Request bodies and response bodies.
- Request headers, including `Authorization`, cookies and the API key header. Response headers are not stored either, apart from the rate limit state parsed from `RateLimit-Policy` and `RateLimit`.
- Query strings.
- Exception messages of server errors: only the class is kept.

What is stored can still be personal data: the client IP, the consumer name (an e-mail address for some `user` consumers) and the path, which may contain identifiers. Size `calls_retention_days` accordingly; aggregates keep the consumer id and name but not the IP or path.

## Storage

`tollbooth:monitoring:setup` creates three tables, or alters them when an upgrade changes their schema. It is idempotent and prints *The monitoring tables are up to date.* when there is nothing to do. The schema is part of the public API: a change is announced with the SQL to run.

### `<prefix>call`: one row per call

| Column | Type |
| --- | --- |
| `id` | string(26), primary key |
| `occurred_at` | bigint, milliseconds |
| `minute` | bigint, seconds |
| `group_name` | string(64) |
| `endpoint` | string(190) |
| `method` | string(10) |
| `path` | string(1024) |
| `status` | smallint |
| `duration` | float, milliseconds |
| `latency_bucket` | smallint, index into the histogram bounds |
| `consumer_id` | string(190) |
| `consumer_name` | string(190) |
| `auth_method` | string(16) |
| `ip` | string(45), nullable |
| `response_size` | integer, nullable |
| `error` | string(255), nullable |
| `steps` | text, JSON object |
| `limiters` | text, JSON list, nullable |

Indexes: `(minute)`, `(occurred_at)`, `(endpoint, occurred_at)`, `(consumer_id, occurred_at)`.

### `<prefix>call_minute`: aggregates

One row per minute, group, endpoint and consumer (the primary key), with `consumer_name`, the counters `calls`, `client_errors` (4xx except 429), `server_errors` (5xx), `throttled` (429), `duration_sum`, `duration_max`, and one latency histogram column per bucket, `lat_0` to `lat_10`. Index: `(minute)`.

The histogram buckets have upper bounds of 5, 10, 25, 50, 100, 250, 500, 1000, 2500 and 5000 ms, plus one bucket above 5000 ms (`Tollbooth\Monitoring\LatencyHistogram::BOUNDS`). Percentiles are estimated by linear interpolation inside the bucket that holds them, capped by the maximum duration.

### `<prefix>state`

Name and value pairs: `rolled_until` (aggregated up to this minute, in seconds) and `purged_before` (the last purge's cutoff for calls).

## Aggregation and retention

**Rollup.** Minutes that ended more than 60 seconds ago are aggregated from `call` into `call_minute` in one `INSERT … SELECT`, in a transaction, from the last aggregated minute (or the oldest call) up to that point. Each minute is aggregated once. Readers combine the aggregates up to `rolled_until` with the raw calls after it, so recent minutes are exact and older ones cost one row per endpoint and consumer per minute.

**Purge.** Calls older than `calls_retention_days` and aggregates older than `aggregates_retention_days` are deleted. Keep `aggregates_retention_days` at least as long as `calls_retention_days`, and at least as long as `health.unused_days` and `changes.impact_days`, which read the aggregates.

Two ways to run them:

| `auto_maintenance` | Behaviour |
| --- | --- |
| `true` (default) | After recording a call, the request runs the rollup at most once every 60 seconds and the purge at most once an hour. The last run times are kept in `monitoring.cache_pool`. A lock from `lock.factory`, when that service exists, makes sure only one process runs each task at a time (others skip it). All of this happens at `kernel.terminate`, after the response is sent. |
| `false` | Nothing runs during requests. Schedule `tollbooth:monitoring:maintain`, for instance every minute. |

```
bin/console tollbooth:monitoring:maintain             # aggregate, then purge
bin/console tollbooth:monitoring:maintain --no-purge  # only aggregate
```

The command prints the aggregate rows written and the calls and aggregate rows purged, and always exits 0. A crontab entry:

```
* * * * * cd /srv/app && php bin/console tollbooth:monitoring:maintain --env=prod > /dev/null
```

With several servers and `auto_maintenance`, share `monitoring.cache_pool` and the lock store so that the work is not repeated on each server (see [guides/multiple-servers.md](guides/multiple-servers.md)). Running the command more often than needed is harmless: it aggregates only the minutes not yet aggregated.

## Reading the data

The storage implements four public interfaces. Each is aliased to the Doctrine storage when monitoring is enabled, so the application can inject them:

| Interface | Methods |
| --- | --- |
| `Tollbooth\Monitoring\CallRecorderInterface` | `record(CallRecord $call): void` |
| `Tollbooth\Monitoring\CallReaderInterface` | `recent(CallQuery $query): list<CallRecord>`; `series(int $from, int $to, ?CallQuery $filter = null): array<int, Stats>`; `totals(int $from, int $to, 'endpoint'\|'consumer' $by, ?CallQuery $filter = null): list<Stats>` |
| `Tollbooth\Monitoring\CallBreakdownInterface` | `statuses(int $from, int $to, ?CallQuery $filter = null): array<int, int>`; `errors(int $from, int $to, ?CallQuery $filter = null, int $limit = 10): list<ErrorGroup>` |
| `Tollbooth\Monitoring\RollupInterface` | `rollup(int $now): int` (aggregate rows written); `purge(int $callsBefore, int $minutesBefore): array{calls: int, minutes: int}` |

Units: `$from`, `$to`, `$now`, `$callsBefore` and `$minutesBefore` are Unix timestamps in **seconds**; ranges are `[$from, $to)`, and `$from` is rounded down to its minute for `series` and `totals`.

| Method | Returns |
| --- | --- |
| `recent()` | The newest calls first, at most `CallQuery::limit` (capped at 500). |
| `series()` | One `Stats` per minute that had calls, keyed and sorted by the minute's start in seconds; `key` and `label` are that timestamp. Minutes without calls are absent. |
| `totals()` | One `Stats` per endpoint (`key` is the route name, `label` the group) or per consumer (`key` is the consumer id, `label` its name), sorted by calls, most first. |
| `statuses()` | Number of calls per status code, most frequent first. |
| `errors()` | Calls with a status of 400 or more, grouped by status and `error`, most frequent first. |

### `CallQuery`

All properties are optional constructor arguments.

| Property | Type | Default | Filters | Used by |
| --- | --- | --- | --- | --- |
| `endpoint` | `?string` | `null` | route name | all |
| `group` | `?string` | `null` | group name | all |
| `consumerId` | `?string` | `null` | consumer id | all |
| `statusClass` | `?int` | `null` | `4` for 4xx, `5` for 5xx… | `recent`, `statuses`, `errors` |
| `status` | `?int` | `null` | exact status | `recent`, `statuses`, `errors` |
| `minDuration` | `?float` | `null` | duration at least, in ms | `recent`, `statuses`, `errors` |
| `id` | `?string` | `null` | one call id | `recent`, `statuses`, `errors` |
| `since` | `?int` | `null` | occurred at or after, in **milliseconds** | `recent` |
| `before` | `?string` | `null` | call id, for the older page | `recent` |
| `after` | `?string` | `null` | call id, for newer calls (live tail) | `recent` |
| `limit` | `int` | `50` | number of calls, 1 to 500 | `recent` |

`series` and `totals` only apply `endpoint`, `group` and `consumerId`, because aggregates have no status, duration or id per call.

### `Stats`

| Member | Content |
| --- | --- |
| `key`, `label` | See `series()` and `totals()`. |
| `calls` | Number of calls. |
| `clientErrors` | 4xx except 429. |
| `serverErrors` | 5xx. |
| `throttled` | 429. |
| `durationSum`, `durationMax` | Milliseconds. |
| `latency` | One count per histogram bucket (11 entries). |
| `errorRate(): float` | `(clientErrors + serverErrors) / calls`, 0 without calls. Throttled calls are not errors. |
| `percentile(float $p): ?float` | Estimated duration at percentile `$p` (`0.95` for p95), in ms, rounded to 0.1; `null` without calls. |
| `merge(Stats $other): Stats` | Sum of both, keeping this one's key and label. |

### `ErrorGroup`

| Property | Content |
| --- | --- |
| `status` | Status code. |
| `error` | The recorded `error`, `null` when none was recorded. |
| `calls` | Number of calls. |
| `consumers` | Number of distinct consumers. |
| `lastSeenAt` | Last occurrence, Unix time in **milliseconds**. |

### Example

```php
namespace App\Report;

use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\Stats;

final readonly class PartnerUsage
{
    public function __construct(private CallReaderInterface $calls)
    {
    }

    /**
     * @return list<array{consumer: string, calls: int, error_rate: float, p95: ?float}>
     */
    public function lastDay(): array
    {
        $now = time();

        return array_map(
            static fn (Stats $stats): array => [
                'consumer' => $stats->label,
                'calls' => $stats->calls,
                'error_rate' => $stats->errorRate(),
                'p95' => $stats->percentile(0.95),
            ],
            $this->calls->totals($now - 86400, $now + 60, 'consumer', new CallQuery(group: 'partner')),
        );
    }
}
```

The interfaces exist only when monitoring is enabled; a service that depends on them fails to build otherwise. Make the argument nullable with a default of `null` if the service must also work without monitoring.

## Performance

- **One insert per call.** Recording is a single append-only `INSERT` into `call`. No row is updated per call, so concurrent calls never wait on each other's locks.
- **After the response.** Recording and automatic maintenance run at `kernel.terminate`. With PHP-FPM, Symfony has already flushed the response to the client by then; the PHP worker stays busy for the insert. With servers that cannot finish the response early, the client waits for it.
- **Failures do not break the API.** Any exception while recording or maintaining is caught and logged at error level (*Tollbooth could not record an API call*), and the response is unaffected.
- **Maintenance off the web servers.** Set `auto_maintenance: false` and schedule `tollbooth:monitoring:maintain` to keep aggregation and purges out of request handling.
- **Separate database.** Point `connection` at a dedicated database under heavy traffic.
- **Reads.** The dashboard and health findings read the aggregates for past minutes and the raw calls only for the minutes not yet aggregated, so their cost depends on the number of endpoints and consumers, not on the number of calls, as long as aggregation keeps up (the `monitoring` health check warns when it does not).

## Health checks and findings

| Name | Kind | Result |
| --- | --- | --- |
| `monitoring` | check | Critical when the tables cannot be read (*Run tollbooth:monitoring:setup, or check the connection.*). Warning when aggregation is more than `health.max_lag_minutes` behind, or when calls older than `calls_retention_days` are still stored a day or more after they should have been purged. Ok otherwise. |
| Error rate | finding (warning) | An endpoint with at least 20 calls in the last hour and an error rate above `health.error_rate`. |
| Unused endpoints | finding (info) | Endpoints without a call in `health.unused_days` days (up to five named). Right after monitoring is turned on, every endpoint is listed until it receives a call. |
| Deprecated endpoints still called | finding | Each `#[DeprecatedEndpoint]` called in the last 7 days, with the call count and up to three consumers; a warning when its sunset is less than 30 days away. |

See [health.md](health.md) for the full list and the probes.
