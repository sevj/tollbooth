# Running on several servers

When several PHP servers (or containers, or pods) answer the same API, some of Tollbooth's state must be shared between them, or each server keeps its own view: rate limits multiplied by the number of servers, a plan change seen by one server only, monitoring split across databases, dashboard forms refused when the next request lands elsewhere. This guide lists what must be shared, what may stay local, and gives a complete configuration with Redis and a shared database.

- [Summary](#summary)
- [Rate limit counters](#rate-limit-counters)
- [Locks](#locks)
- [Clocks](#clocks)
- [Stored plans](#stored-plans)
- [API keys](#api-keys)
- [Monitoring](#monitoring)
- [Dashboard forms and kernel.secret](#dashboard-forms-and-kernelsecret)
- [What stays local](#what-stays-local)
- [Complete example](#complete-example)
- [Checking it](#checking-it)

## Summary

| State | Where it lives | Default | With several servers |
| --- | --- | --- | --- |
| Rate limit counters | `tollbooth.rate_limit.cache_pool` | `cache.app` (usually the filesystem of each server) | **Shared** cache pool, Redis for instance |
| Locks around counters and monitoring maintenance | Symfony's `lock.factory` | the store of `framework.lock` (usually `flock`, local) | **Shared** lock store, Redis for instance |
| Stored plans (`rate_limit.doctrine_plans`) | A table, cached 60 s in `rate_limit.cache_pool` | | **Shared** database; shared pool to see changes at once |
| Stored API keys (`auth.doctrine_keys`) | A table | | **Shared** database |
| Monitoring calls and aggregates | `tollbooth.monitoring.connection` | | **Shared** database |
| "Maintenance last ran" marker | `tollbooth.monitoring.cache_pool` | `cache.app` | Shared pool, or `auto_maintenance: false` and one scheduler |
| Dashboard form tokens | `kernel.secret` | `APP_SECRET` | **Same value** on every server |
| Contract snapshots | `tollbooth.changes.directory` | `tollbooth/contract` in the project | Deployed with the code (committed) |
| OpenAPI documents | `cache.system` | local | Local is fine |
| Configured plans, API keys, health token | `config/packages/tollbooth.yaml`, environment variables | | Same deployment everywhere |

## Rate limit counters

Each limit is a counter per consumer and per clock-aligned window, stored in the cache pool named by `tollbooth.rate_limit.cache_pool`. With the default `cache.app` on the filesystem, every server counts on its own: with 4 servers behind a round-robin load balancer, a consumer limited to `600/minute` can make up to 2,400 calls a minute. Point the setting at a pool every server shares:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            tollbooth.rate_limit:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_URL)%'
```

```yaml
# config/packages/tollbooth.yaml
tollbooth:
    rate_limit:
        cache_pool: tollbooth.rate_limit
```

- Use a dedicated pool rather than `cache.app`: clearing the application cache (`cache:pool:clear cache.app`) would otherwise reset every counter.
- Keys are prefixed `tollbooth_rl_` and expire one second after their window ends, so the pool never grows beyond the active windows. On Redis, avoid an eviction policy that may drop keys before they expire (`maxmemory-policy noeviction` or a `volatile-*` policy with enough memory).
- The health check `rate_limit_storage` writes and reads back a value in the pool on every report and turns critical when the pool does not keep what is written to it. See [health.md](../health.md).

The counters themselves are described in [rate-limiting.md](../rate-limiting.md).

## Locks

A counter update reads the current value, checks it against the limit and writes the new value. Tollbooth wraps this in a lock from Symfony's `lock.factory` (one lock per counter key). The lock store must be shared too: with a local store (`flock`, `semaphore`), two servers can read the same value at the same moment and one increment is lost, which lets a consumer slightly exceed its limit under load. Without the Lock component at all, no lock is taken.

```
composer require symfony/lock
```

```yaml
# config/packages/lock.yaml
framework:
    lock: '%env(LOCK_DSN)%'
```

```
# .env
LOCK_DSN=redis://redis:6379
```

The same lock factory guards monitoring maintenance (see [below](#monitoring)).

## Clocks

Windows are aligned on each server's clock: a minute window ends at the top of the minute, a day window at 00:00 UTC. Keep the servers synchronized (NTP) so that they agree on which window a call belongs to and on the reset times sent in the `RateLimit` headers.

## Stored plans

With `tollbooth.rate_limit.doctrine_plans` enabled, the plans live in a table that every server reads through the configured DBAL connection: point it at the shared database.

```yaml
tollbooth:
    rate_limit:
        cache_pool: tollbooth.rate_limit
        doctrine_plans:
            enabled: true
            connection: default
```

The plans are cached for 60 seconds in `rate_limit.cache_pool`, and a change made from the dashboard deletes that cache entry:

- with a shared pool, every server reads the new plans on its next request;
- with a local pool, the server that saved the change sees it at once and the others within a minute.

Within a request (and between requests of a long-running worker, which Symfony resets with `kernel.reset`), each process keeps the plans in memory. While the table cannot be read, the plans declared in YAML apply and the health report is critical. See [rate-limiting.md](../rate-limiting.md).

## API keys

- Keys declared under `tollbooth.auth.api_keys` are part of the configuration: deploy the same configuration everywhere.
- With `tollbooth.auth.doctrine_keys`, keys are read from the table on each call that presents a key; point `connection` at the shared database. A key created, rotated, re-planned or revoked from the dashboard or `tollbooth:api-key:generate --store` is effective on every server at once.

See [authentication.md](../authentication.md).

## Monitoring

Every server writes its calls with one `INSERT` into the database of `tollbooth.monitoring.connection`. All servers must use the same database, or the dashboard, the health findings and the impact of contract changes only see part of the traffic. Under heavy traffic, use a dedicated database:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            monitoring:
                url: '%env(resolve:MONITORING_DATABASE_URL)%'
```

```yaml
# config/packages/tollbooth.yaml
tollbooth:
    monitoring:
        enabled: true
        connection: monitoring
```

### Maintenance

Aggregating finished minutes and purging old data must run once, not once per server. Two options:

**Automatic, coordinated.** With `auto_maintenance: true` (the default), the end of a request runs the aggregation at most once a minute and the purge at most once an hour. The "last run" time is kept in `tollbooth.monitoring.cache_pool` and the work runs under a lock from `lock.factory`. With a shared pool and a shared lock store, one server does the work and the others skip it:

```yaml
tollbooth:
    monitoring:
        cache_pool: tollbooth.rate_limit     # any shared pool
```

With local pools and locks, every server tries; concurrent aggregations of the same minutes fail on the aggregate table's primary key and are logged as errors ("Tollbooth could not record an API call").

**Scheduled.** Turn automatic maintenance off and run the command from one place (cron, a Kubernetes CronJob, Symfony Scheduler) every minute:

```yaml
tollbooth:
    monitoring:
        auto_maintenance: false
```

```
* * * * * php /app/bin/console tollbooth:monitoring:maintain
```

The health report shows how far behind aggregation and purge are (`tollbooth.health.max_lag_minutes`). See [monitoring.md](../monitoring.md).

## Dashboard forms and kernel.secret

The dashboard's forms (API keys, plans) carry a token signed with `kernel.secret`. A form rendered by one server and submitted to another is refused with 403 unless both have the same secret: set the same `APP_SECRET` on every server.

The forms are also refused when the browser's `Origin` header differs from the scheme and host Symfony sees. Behind a load balancer that terminates TLS, configure the trusted proxies:

```yaml
# config/packages/framework.yaml
framework:
    trusted_proxies: '%env(TRUSTED_PROXIES)%'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port']
```

If the dashboard's login uses sessions, the session storage must be shared too (or sticky sessions used), as for any Symfony application. See [dashboard.md](../dashboard.md#signed-forms).

## What stays local

| Item | Why it can stay local |
| --- | --- |
| Compiled endpoint metadata, routes | Built into the container at deployment, identical on every server |
| OpenAPI documents | Built from that metadata and cached in `cache.system` per server (see [openapi.md](../openapi.md#caching)) |
| Dashboard counters (models, breaking changes, collector state) | Cached briefly in `cache.app`, recomputed on each server |
| Contract snapshots | Files committed with the code (`tollbooth.changes.directory`); `tollbooth:contract:snapshot` is run at release time, not on the servers |
| Health probes | `/live` and `/ready` answer for the server that receives them, which is what orchestrators need |

## Complete example

```yaml
# config/packages/framework.yaml
framework:
    secret: '%env(APP_SECRET)%'
    lock: '%env(LOCK_DSN)%'
    trusted_proxies: '%env(TRUSTED_PROXIES)%'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port']
    cache:
        pools:
            tollbooth.shared:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_URL)%'
```

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            monitoring:
                url: '%env(resolve:MONITORING_DATABASE_URL)%'
```

```yaml
# config/packages/tollbooth.yaml
tollbooth:
    path_prefix: /api
    auth:
        doctrine_keys:
            enabled: true
            connection: default
    rate_limit:
        cache_pool: tollbooth.shared
        doctrine_plans:
            enabled: true
            connection: default
    monitoring:
        enabled: true
        connection: monitoring
        cache_pool: tollbooth.shared
        auto_maintenance: true
    health:
        token: '%env(TOLLBOOTH_HEALTH_TOKEN)%'
```

```
# environment of every server
APP_SECRET=<the same value everywhere>
REDIS_URL=redis://redis:6379
LOCK_DSN=redis://redis:6379
DATABASE_URL=postgresql://app:secret@db:5432/app
MONITORING_DATABASE_URL=postgresql://app:secret@monitoring-db:5432/monitoring
TRUSTED_PROXIES=10.0.0.0/8
TOLLBOOTH_HEALTH_TOKEN=<the same value everywhere>
```

Create the tables once, from one machine or a deployment job:

```
bin/console tollbooth:api-key:setup
bin/console tollbooth:plan:setup
bin/console tollbooth:monitoring:setup
```

Each accepts `--dump-sql` to put the SQL in a migration instead. See [configuration.md](../configuration.md) for every key.

## Checking it

- `bin/console tollbooth:health` on each server: the `rate_limit` fact names the counter pool, and the checks cover the counter storage, the plan and key tables and the monitoring lag.
- Send more calls than a limit allows through the load balancer: the 429 must come at the limit, not at a multiple of it.
- Change a plan in the dashboard and call the API through another server: the new `RateLimit-Policy` must appear at once.
- Create an API key from the dashboard several times in a row: if a submission is refused with 403, check `APP_SECRET` and the trusted proxies.
