# Health

The Health module tells operators and orchestrators whether the API can serve. It runs **checks**, each answering ok, warning or critical, and combines them into an overall status: `ok`, `degraded` (a warning) or `critical`. It also collects **findings**, observations worth a look that never change the status: an endpoint failing too often, a key about to expire, a page left outside the firewall. Tollbooth checks its own parts (compiled metadata, rate limit counters, stored plans and keys, monitoring tables); the application adds its own checks and finding providers by implementing two interfaces. The results are served on two probes for uptime tools and Kubernetes, a full JSON report, the `tollbooth:health` command and the dashboard's status screen.

## Contents

- [Routes](#routes)
- [The JSON report](#the-json-report)
- [The `tollbooth:health` command](#the-tollboothhealth-command)
- [Configuration](#configuration)
- [Built-in checks](#built-in-checks)
- [Built-in findings](#built-in-findings)
- [Writing a check](#writing-a-check)
- [Writing a finding provider](#writing-a-finding-provider)
- [Registration and order](#registration-and-order)

## Routes

The routes are added by the `tollbooth` route loader, so they exist once the endpoint routes are imported (see [getting-started.md](getting-started.md)).

| Route name | Path | Methods | Answers |
| --- | --- | --- | --- |
| `tollbooth_health_live` | `<health.probe_path>/live`, by default `/_tollbooth/health/live` | GET, HEAD | `200 {"status": "ok"}` as long as PHP answers. Runs no check. |
| `tollbooth_health_ready` | `<health.probe_path>/ready`, by default `/_tollbooth/health/ready` | GET, HEAD | The overall status and check time. `503` when the status is `critical`, `200` otherwise (`degraded` included). The checks are listed only when the request carries the token. Findings are never computed. |
| `tollbooth_health` | `<probe_path>.json`, by default `/_tollbooth/health.json` | GET | The full report: checks, findings and facts, always `200`. |

Every answer is JSON with `Cache-Control: no-store`.

Tollbooth does not protect these routes. The probes are meant to stay reachable by the orchestrator: exclude them from the firewall, or allow them without authentication. The report lists the application's internals and belongs behind the firewall, with the dashboard. A firewall setup that leaves the probes and the documentation open and requires a role for the rest:

```yaml
security:
    firewalls:
        probes:
            pattern: ^/_tollbooth/(health/(live|ready)|docs|openapi)
            security: false
        main:
            lazy: true
            provider: app_users
            form_login:
                login_path: /login
                check_path: /login
    access_control:
        - { path: ^/_tollbooth, roles: ROLE_ADMIN }
```

### `/ready` without the token

```
GET /_tollbooth/health/ready
```

```json
{
    "status": "ok",
    "checked_at": "2026-11-25T14:02:05Z"
}
```

### `/ready` with the token

The token is `health.token`, sent as `Authorization: Bearer <token>` or as the query parameter `?token=<token>` (the header wins when it is a Bearer header). It is compared in constant time. A wrong token is ignored: the answer is the same as without one. With `health.token` unset or empty, the checks are never shown on `/ready`.

```
GET /_tollbooth/health/ready
Authorization: Bearer 9f2c…
```

```json
{
    "status": "degraded",
    "checked_at": "2026-11-25T14:02:05Z",
    "checks": [
        { "name": "metadata", "source": "tollbooth", "status": "ok", "summary": "24 endpoints compiled in 3 groups.", "duration_ms": 0.02 },
        { "name": "rate_limit_storage", "source": "tollbooth", "status": "ok", "summary": "Cache pool \"cache.app\" answers in 0.4 ms.", "duration_ms": 0.45 },
        { "name": "monitoring", "source": "tollbooth", "status": "warning", "summary": "Aggregation is 58 minutes behind. Check auto_maintenance, or schedule tollbooth:monitoring:maintain.", "duration_ms": 1.8 },
        { "name": "payment_gateway", "source": "application", "status": "ok", "summary": "Answered 200.", "duration_ms": 112.3 }
    ],
    "findings": [],
    "facts": {
        "tollbooth": "v0.22.0",
        "symfony": "7.4.0",
        "php": "8.4.14 · opcache on",
        "environment": "prod",
        "conventions": "plain · preserve · problem+json",
        "rate_limit": "cache pool cache.app",
        "monitoring": "doctrine · default"
    }
}
```

`/ready` runs every check on every request, including the database queries of the built-in checks and whatever your own checks do. Point frequent liveness probes at `/live`, and keep your checks fast.

## The JSON report

`/_tollbooth/health.json` and `/ready` with the token share this shape (on `/ready`, `findings` is always empty):

| Member | Type | Content |
| --- | --- | --- |
| `status` | string | `ok`, `degraded` or `critical`: the worst check status (`warning` maps to `degraded`). |
| `checked_at` | string | UTC time, `YYYY-MM-DDTHH:MM:SSZ`. |
| `checks` | list | One object per check, in run order. |
| `checks[].name` | string | The check's `name()`. |
| `checks[].source` | string | `tollbooth` for built-in checks, `application` for yours. |
| `checks[].status` | string | `ok`, `warning` or `critical`. |
| `checks[].summary` | string | The check's summary. |
| `checks[].duration_ms` | float | Time the check took. |
| `findings` | list | `{"level": "warning" \| "info", "message": "…"}`, warnings first. |
| `facts` | object | `tollbooth` (installed version, `dev` outside Composer), `symfony`, `php` (with `· opcache on` when OPcache is active), `environment`, `conventions` (top-level envelope and case), `rate_limit` (`cache pool <id>` or `off`), `monitoring` (`doctrine · <connection>` or `off`). |

The routes and this JSON shape are part of the public API.

A check that throws is reported as `critical` with the summary `The check failed with <exception class>.`: the exception message is never shown, since it may hold hosts or credentials. A finding provider that throws adds the warning finding `Some findings could not be computed: <exception class>.` and the other providers still run.

## The `tollbooth:health` command

```
bin/console tollbooth:health
```

prints the checks as a table (status `OK`, `WARN` or `FAIL`, name, source, summary, milliseconds), then the findings (`!` for warnings, `-` for information), then `Status: ok`, `Status: degraded` or `Status: critical`. It runs the same checks and findings as the report. It takes no option and exits with **1 when the status is critical**, 0 otherwise, so it can gate a deployment:

```
bin/console tollbooth:health --env=prod || exit 1
```

## Configuration

```yaml
tollbooth:
    health:
        enabled: true
        probe_path: /_tollbooth/health
        token: '%env(TOLLBOOTH_HEALTH_TOKEN)%'
        error_rate: 0.05
        unused_days: 30
        key_expiry_days: 14
        max_lag_minutes: 10
```

| Key | Default | Used by |
| --- | --- | --- |
| `enabled` | `true` | `false` registers no check, finding provider, route or command, and stops autoconfiguring the two interfaces. |
| `probe_path` | `/_tollbooth/health` | Base path of `/live` and `/ready`. |
| `token` | `null` | Unlocks the checks on `/ready`. |
| `error_rate` | `0.05` | Error rate finding (and the dashboard's thresholds). |
| `unused_days` | `30` | Unused endpoint finding. |
| `key_expiry_days` | `14` | API key expiry finding. |
| `max_lag_minutes` | `10` | `monitoring` check. |

The report path is `<probe_path>.json`, next to the probes. See [configuration.md](configuration.md#health).

## Built-in checks

They run first, in this order, each only when its part is enabled:

| Name | Registered when | Ok | Warning | Critical |
| --- | --- | --- | --- | --- |
| `metadata` | always | `<n> endpoints compiled in <m> groups.` | No endpoint is compiled: *No endpoint is declared, or the container was built before they were.* | never |
| `rate_limit_storage` | rate limiting on, with `plans` or `doctrine_plans` | Writes a random value to `rate_limit.cache_pool`, reads it back: *Cache pool "<id>" answers in <t> ms.* | never | The value read back differs: *The cache pool "<id>" does not keep what is written to it: rate limits cannot be counted.* An exception from the pool is also critical. |
| `plan_storage` | rate limiting on, with `doctrine_plans` | `<n> plans, default <name>.` | The table is out of date (*Run tollbooth:plan:setup.*), or holds no plan (calls are not limited; the message suggests `tollbooth:plan:setup` when plans are declared in YAML, the dashboard otherwise). | The table cannot be read: only the plans declared in configuration apply. |
| `monitoring` | monitoring on | *Tables ready, no call recorded yet.*, *Recording calls, nothing to aggregate yet.* or *Recording calls, aggregated up to <time> UTC.* | Aggregation is more than `max_lag_minutes` behind (the lag counts from two minutes ago, since the last minute is aggregated one minute after it ends); or calls older than `monitoring.calls_retention_days` are still stored one day or more past their retention. | The tables cannot be read: *Run tollbooth:monitoring:setup, or check the connection.* |
| `api_key_storage` | `auth.doctrine_keys` on | `<n> stored keys.` | The table is out of date: *Run tollbooth:api-key:setup.* | The table cannot be read: calls with a key not declared in configuration fail. |

Each module ships the checks of its own parts; see [rate-limiting.md](rate-limiting.md#health-checks), [monitoring.md](monitoring.md#health-checks-and-findings) and [authentication.md](authentication.md#health-checks-and-findings).

## Built-in findings

Findings appear only in the full report, the command and the dashboard, never on `/ready`.

| Finding | Registered when | Level | Rule |
| --- | --- | --- | --- |
| Error rate | monitoring on | warning | Each endpoint with at least 20 calls in the last hour whose error rate (4xx except 429, and 5xx, over all calls) is above `error_rate`: *tollbooth_partner_create_order answered 12.5% errors in the last hour (5 of 40 calls).* |
| Unused endpoints | monitoring on | info | Endpoints without any call in the last `unused_days` days, counted and named (the first five): *3 endpoints received no call in 30 days: …* Based on monitoring data, so it lists every endpoint right after monitoring is turned on, and endpoints whose calls are older than `monitoring.aggregates_retention_days`. |
| Deprecated endpoints still called | monitoring on | info; warning when the sunset is less than 30 days away | Each `#[DeprecatedEndpoint]` called in the last 7 days: *GET /v1/users/{id} is deprecated (sunset 2027-03-01) and was called 1342 times in 7 days by acme-erp, mobile-app, ip:203.0.113.7 and 2 more.* |
| Expired API keys | always, when the key provider implements `ListableApiKeyProviderInterface` | info | *The API key of "acme-erp" expired on 2026-10-01 and is refused.* |
| API keys expiring soon | same | warning | Keys expiring within `key_expiry_days` days: *The API key of "acme-erp" expires in 9 days, on 2026-12-04.* |
| Pages outside any firewall | always | info | For the health report (`<probe_path>.json`), the documentation (`<docs.path>/docs`, when docs are on) and the dashboard (`<dashboard.path>/map`, when the dashboard is on): *The dashboard at /_tollbooth/map is outside any firewall: anyone can read it.* Raised when no firewall matches the path, when the matching firewall has `security: false`, or when the security bundle is not installed. A second message is raised when a firewall covers the path but no `access_control` rule requires a role for it (none, or only `PUBLIC_ACCESS`): *The dashboard at /_tollbooth/map is behind the "main" firewall, but no access_control rule requires a role for it: anyone can read it.* |

Endpoints are named by their route name in the error rate and unused findings (`tollbooth_<group>_<name>`), and by method and path in the deprecation finding.

## Writing a check

Implement `Tollbooth\Core\Health\HealthCheckInterface`:

| Method | Returns |
| --- | --- |
| `name(): string` | A stable identifier, shown in the report, for instance `payment_gateway`. |
| `check(): Tollbooth\Core\Health\CheckResult` | The status and a one-line summary. |

`CheckResult` is a readonly value object with `status` (`Tollbooth\Core\Health\HealthStatus`: `Ok`, `Warning`, `Critical`) and `summary` (string), built with the named constructors `CheckResult::ok($summary)`, `CheckResult::warning($summary)` and `CheckResult::critical($summary)`.

| Status | Effect on the overall status | `/ready` |
| --- | --- | --- |
| `ok` | none | 200 |
| `warning` | `degraded`, unless something is critical | 200 |
| `critical` | `critical` | 503 |

```php
namespace App\Health;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tollbooth\Core\Health\CheckResult;
use Tollbooth\Core\Health\HealthCheckInterface;

final readonly class PaymentGatewayCheck implements HealthCheckInterface
{
    public function __construct(private HttpClientInterface $client)
    {
    }

    public function name(): string
    {
        return 'payment_gateway';
    }

    public function check(): CheckResult
    {
        $start = microtime(true);
        $status = $this->client->request('GET', 'https://payments.example.com/ping', ['timeout' => 2])->getStatusCode();
        $elapsed = (microtime(true) - $start) * 1000;

        if (200 !== $status) {
            return CheckResult::critical(\sprintf('Answered %d.', $status));
        }

        return $elapsed > 1000
            ? CheckResult::warning(\sprintf('Answered in %.0f ms.', $elapsed))
            : CheckResult::ok('Answered 200.');
    }
}
```

Guidelines:

- Make `critical` mean the API cannot serve: it fails readiness, and an orchestrator stops sending traffic to the instance. Use `warning` for slowness or partial loss.
- Keep checks fast and bounded with timeouts: `/ready` runs them all on every probe.
- Let unexpected exceptions escape if you like: the runner reports them as critical with their class only. Do not put secrets in summaries, which are shown to whoever holds the token.

## Writing a finding provider

Implement `Tollbooth\Core\Health\FindingProviderInterface`:

| Method | Returns |
| --- | --- |
| `findings(): iterable<Tollbooth\Core\Health\Finding>` | Zero or more findings. A generator is fine. |

`Finding` is a readonly value object with `message` (string) and `warning` (bool, default `false`): `new Finding('…')` is information, `new Finding('…', true)` a warning. Findings never change the status.

```php
namespace App\Health;

use App\Catalog\ProductRepository;
use Tollbooth\Core\Health\Finding;
use Tollbooth\Core\Health\FindingProviderInterface;

final readonly class CatalogFindings implements FindingProviderInterface
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function findings(): iterable
    {
        $unpriced = $this->products->countWithoutPrice();
        if ($unpriced > 0) {
            yield new Finding(\sprintf('%d products have no price and are hidden from the API.', $unpriced), true);
        }
    }
}
```

## Registration and order

With `autoconfigure: true` (the default in `config/services.yaml`), any service implementing `HealthCheckInterface` is tagged `tollbooth.health_check` and any service implementing `FindingProviderInterface` is tagged `tollbooth.finding_provider`. Nothing else is needed. Without autoconfiguration, tag them yourself; the tag names are the interfaces' `TAG` constants:

```yaml
services:
    App\Health\PaymentGatewayCheck:
        tags:
            - { name: tollbooth.health_check, priority: 10 }
    App\Health\CatalogFindings:
        tags: [tollbooth.finding_provider]
```

Built-in checks always run first, then the application's checks, ordered by the tag's `priority` (highest first), then by registration order. Findings are listed with warnings first.

Autoconfiguration is registered by the Health module: with `health.enabled: false`, your services are not tagged and nothing runs them.

The `Tollbooth\Core\Health` namespace (`HealthCheckInterface`, `FindingProviderInterface`, `CheckResult`, `HealthStatus`, `Finding`) is part of the public API. More extension examples are in [guides/extending.md](guides/extending.md).
