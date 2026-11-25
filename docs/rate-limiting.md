# Rate limiting

Tollbooth limits how many calls each [consumer](authentication.md#the-consumer) makes. A **plan** is a named set of limits counted per consumer across every endpoint; a consumer gets the plan of its API key, or the default plan, and anonymous callers get the anonymous plan. Groups and endpoints can add limits of their own on top. Windows are fixed and aligned on the clock, so the reset time sent to clients is exact. Every response of a limited endpoint reports every limit in the IETF `RateLimit-Policy` and `RateLimit` headers, and a refused call answers `429` with `Retry-After` and the state of each limiter. Plans can be declared in YAML or kept in a database table edited from the dashboard.

## Contents

- [Limit syntax](#limit-syntax)
- [Plans](#plans)
- [Which plan a consumer gets](#which-plan-a-consumer-gets)
- [Group limits](#group-limits)
- [Endpoint limits](#endpoint-limits)
- [How calls are counted](#how-calls-are-counted)
- [Counter storage](#counter-storage)
- [Response headers](#response-headers)
- [The 429 response](#the-429-response)
- [Stored plans](#stored-plans)
- [Health checks](#health-checks)
- [Disabling rate limiting](#disabling-rate-limiting)

## Limit syntax

A limit is a string `<count>/<interval>`:

| Part | Rule |
| --- | --- |
| count | Integer, at least 1. |
| interval | An optional integer (default 1, at least 1) followed by a unit. Spaces are allowed around every part. |
| unit | Case-insensitive: `s`, `sec`, `second`, `seconds`; `m`, `min`, `minute`, `minutes`; `h`, `hour`, `hours`; `d`, `day`, `days`. Only the first letter matters, so `5 min` and `5m` are the same. |

| Spec | Limit | Window | Default name |
| --- | --- | --- | --- |
| `600/minute` | 600 | 60 s | `minute` |
| `100/10 seconds` | 100 | 10 s | `10s` |
| `50/5m` | 50 | 300 s | `5m` |
| ` 3 / 2 hours ` | 3 | 7200 s | `2h` |
| `200000/day` | 200000 | 86400 s | `day` |
| `1000/1 hour` | 1000 | 3600 s | `hour` |

The default name is the unit's name (`second`, `minute`, `hour`, `day`) when the interval is 1, and `<interval><unit letter>` otherwise. Any other form, such as `600 per minute`, `0/minute` or `10/0s`, is rejected when the container is built:

```
"600 per minute" is not a rate limit. Write it as "<count>/<interval>", for instance "600/minute", "100/10 seconds" or "200000/day".
```

Limits are written as a list, which uses the default names, or as a map, which names them:

```yaml
['5/10 seconds', '60/minute', '1000/day']          # named 10s, minute, day
{burst: '100/10 seconds', sustained: '600/minute'}  # named burst, sustained
```

Two limits of one plan, group or endpoint cannot share a name: `['600/minute', '100/1 minute']` fails the build with `Two rate limits of "plan" are named "minute". Name them explicitly, for instance {burst: "100/1 minute"}.`

The limit syntax is part of the public API.

## Plans

```yaml
tollbooth:
    rate_limit:
        plans:
            free: ['5/10 seconds', '60/minute', '1000/day']
            pro:
                burst: '100/10 seconds'
                minute: '600/minute'
                day: '200000/day'
            internal: []
        default_plan: free
        anonymous_plan: free
```

| Key | Default | Meaning |
| --- | --- | --- |
| `plans.<name>` | none | List or map of limits. Plan limits keep their own names (`burst`, `minute`), with no prefix. A plan with an empty list limits nothing. |
| `default_plan` | `null` | Plan of consumers that have no plan or an unknown one. Must be declared under `plans`. |
| `anonymous_plan` | `null` | Plan of `anonymous` consumers. Must be declared under `plans`. `null` falls back to `default_plan`. |

A `default_plan` or `anonymous_plan` that is not a key of `plans` fails the build: `The rate limit plan "gold" is not declared under "tollbooth.rate_limit.plans".`

## Which plan a consumer gets

| Consumer | Plan |
| --- | --- |
| `anonymous` (including calls to `#[PublicEndpoint]` endpoints) | `anonymous_plan`, or `default_plan` when it is `null` |
| `api_key` | the key's `plan`; if it is `null` or not an existing plan, `default_plan` |
| `jwt`, `user` | `default_plan` (these consumers have no plan) |

When the resolved plan does not exist either, the consumer has no plan limits; group and endpoint limits still apply. To give JWT clients or users their own quotas, run them through a group with `rate_limits`, or put them on a key-based method.

Public endpoints are not exempt: their calls are anonymous and count against the anonymous plan, per client IP. Configure `framework.trusted_proxies` behind a proxy, otherwise every anonymous caller is the proxy and they all share one counter.

## Group limits

```yaml
tollbooth:
    groups:
        partner:
            namespace: App\Api\Partner
            rate_limits: ['10000/hour']           # named "partner.hour"
        search:
            path: /search
            rate_limits:
                burst: '20/10 seconds'            # named "search.burst"
```

A group limit is counted per consumer across all the endpoints of the group, on top of the consumer's plan. Its name is `<group>.<limit name>`.

## Endpoint limits

```php
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\RateLimit;

#[Endpoint('GET', '/products/search')]
#[RateLimit('30/minute')]
final class SearchProducts
{
    public function __invoke(): array
    {
        return [];
    }
}

#[Endpoint('POST', '/orders/import')]
#[RateLimit(imports: '20/hour', burst: '2/10 seconds')]
final class ImportOrders
{
    public function __invoke(): array
    {
        return [];
    }
}
```

`#[RateLimit]` takes limits as positional arguments (default names) or named arguments (explicit names), and is repeatable. An endpoint limit is counted per consumer for that endpoint only, and is named `<endpoint name>.<limit name>`: `search_products.minute`, `import_orders.imports`. An invalid limit fails the build with the endpoint's class in the message. The endpoint name is the one declared on `#[Endpoint(name: …)]` or derived from the class name (see [endpoints.md](endpoints.md)).

## How calls are counted

Rate limiting runs on `kernel.request` at priority 6, right after [authentication](authentication.md#how-a-call-is-authenticated), for requests routed to an endpoint:

1. The limits of the call are, in this order: the consumer's plan limits, the group's limits, the endpoint's limits. If there are none, nothing is counted and no header is sent.
2. Each limit is hit once for the consumer. A limit whose counter has reached its count refuses the hit and is not incremented.
3. If any limit refused, the hits already granted to the other limits of this call are given back: **a refused call is counted against no limit**. The call answers 429.
4. Otherwise the call proceeds, and the states are kept for the response headers.

Calls refused before rate limiting (401, 403) are not counted. Calls that fail afterwards (400, 404, 422, 500) are counted.

### Clock-aligned fixed windows

A window starts at a multiple of its length since the Unix epoch, in UTC:

| Window | Resets at |
| --- | --- |
| 10 seconds | :00, :10, :20… of every minute |
| 1 minute | the top of every minute |
| 1 hour | the top of every hour |
| 1 day | 00:00 UTC |
| 5 minutes | :00, :05, :10… |
| 7 hours | every 25200 seconds since the epoch, which is not aligned on days |

Choose windows that divide a day (or an hour) if clients reason in local calendar units. Because windows are fixed, a client can make up to twice the limit across a window boundary (the end of one window and the start of the next); add a short `burst` limit if that matters.

### Counter keys

A counter is identified by the consumer id, the limit's scope (plan, `group:<name>` or `endpoint:<group>.<name>`), the limit name and the window start. Consequences:

- Every plan limit with the same name shares its counter: when a key moves from `free` to `pro`, its `minute` count carries over within the current window.
- Two consumers never share a counter, except anonymous callers behind the same IP.
- Changing a limit's count takes effect on the next call; changing its window starts new counters.

## Counter storage

Counters live in the PSR-6 cache pool named by `rate_limit.cache_pool` (`cache.app` by default). Each update runs under a lock from the `lock.factory` service when it exists (Symfony's `framework.lock`), held at most 5 seconds; without a lock factory, updates are not locked and concurrent calls can be under-counted.

| Setup | Use |
| --- | --- |
| One server | The default `cache.app` (filesystem) works. |
| Several servers | A shared pool, for instance Redis, and a shared lock store; otherwise each server counts on its own and a consumer gets the limit once per server. See [guides/multiple-servers.md](guides/multiple-servers.md). |

```yaml
framework:
    cache:
        pools:
            tollbooth.rate_limits:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_URL)%'
    lock: '%env(REDIS_URL)%'

tollbooth:
    rate_limit:
        cache_pool: tollbooth.rate_limits
```

Items expire one second after their window ends. Clearing the pool resets every counter.

## Response headers

Every response of a call that went through rate limiting with at least one limit carries both headers, errors included (a 422, a 404 or the 429 itself):

```
RateLimit-Policy: "burst";q=100;w=10, "minute";q=600;w=60, "day";q=200000;w=86400
RateLimit: "burst";r=97;t=4, "minute";r=581;t=34, "day";r=46020;t=35869
```

| Parameter | Header | Meaning |
| --- | --- | --- |
| `q` | `RateLimit-Policy` | Quota: the limit's count. |
| `w` | `RateLimit-Policy` | Window, in seconds. |
| `r` | `RateLimit` | Remaining calls in the current window. |
| `t` | `RateLimit` | Seconds until the window resets, at least 1. |

Limits appear in counting order: plan, group, endpoint. Responses to calls refused at 401 or 403 carry no rate limit header.

### Legacy headers

```yaml
tollbooth:
    rate_limit:
        legacy_headers: true
```

adds three headers describing the tightest limit, the one with the fewest remaining calls (on a tie, the one resetting last):

```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 97
X-RateLimit-Reset: 4
```

`X-RateLimit-Reset` is a number of seconds, like `t`, not a timestamp.

## The 429 response

```
HTTP/1.1 429 Too Many Requests
Content-Type: application/problem+json
Retry-After: 4
RateLimit-Policy: "burst";q=100;w=10, "minute";q=600;w=60
RateLimit: "burst";r=0;t=4, "minute";r=581;t=34

{
    "type": "about:blank",
    "title": "Too Many Requests",
    "status": 429,
    "detail": "Rate limit \"burst\" reached: 100 requests per 10 seconds.",
    "limiters": [
        { "name": "burst", "limit": 100, "remaining": 0, "reset": 4 },
        { "name": "minute", "limit": 600, "remaining": 581, "reset": 34 }
    ]
}
```

- `Retry-After` is the longest reset among the refusing limits, in seconds.
- `detail` names the first refusing limit, with its window written as `second`, `N seconds`, `minute`, `N minutes`, `hour`, `N hours`, `day` or `N days`.
- `limiters` lists every limit of the call, with its state after the refund.
- The `limiters` member and its fields (`name`, `limit`, `remaining`, `reset`) are written as shown whatever the group's `output.case`. Problem details are never wrapped in an envelope (see [http-contract.md](http-contract.md)).

The exception behind it, `Tollbooth\RateLimit\RateLimitExceededException`, is public. It extends Symfony's `TooManyRequestsHttpException` and implements `Tollbooth\Core\Problem\ProblemMembersInterface`, whose `problemMembers()` provides the `limiters` member.

## Stored plans

With Doctrine DBAL, plans can live in a table, where the dashboard's configuration screen creates, edits and deletes them and picks the default and anonymous plans, so a quota changes without a deployment.

```yaml
tollbooth:
    rate_limit:
        doctrine_plans:
            enabled: true
            connection: default      # DBAL connection name, or a service id containing a dot
            table_prefix: tollbooth_
```

```
bin/console tollbooth:plan:setup              # create or update the table, then seed it
bin/console tollbooth:plan:setup --dump-sql   # print the SQL only, for a migration
bin/console tollbooth:plan:setup --import     # also copy declared plans missing from a non-empty table
```

### The table is the source of truth

Once `doctrine_plans` is on, the plans, the default plan and the anonymous plan come from the table `<prefix>rate_limit_plan`:

| Column | Type | Content |
| --- | --- | --- |
| `name` | string(64), primary key | plan name |
| `limits` | text | JSON list of `{name, limit, window, scope}` |
| `is_default` | smallint | 1 for the default plan (at most one row) |
| `is_anonymous` | smallint | 1 for the anonymous plan (at most one row) |
| `updated_at` | bigint | Unix timestamp of the last change |

The YAML keys `plans`, `default_plan` and `anonymous_plan` are optional and play two roles:

| Role | When |
| --- | --- |
| **Seed** | `tollbooth:plan:setup` copies every declared plan into the table when the table holds no plan. The declared `default_plan` and `anonymous_plan` are flagged as such. With `--import`, it also copies the declared plans the table lacks when it is not empty, without overwriting existing plans and without moving existing default or anonymous flags. The command lists the plans it copied. |
| **Fallback** | When the table cannot be read (missing table, database down), the declared plans apply and an error is logged. The `plan_storage` health check turns critical. |

The same build-time rule applies: a `default_plan` or `anonymous_plan` must be declared under `plans`. If you keep plans only in the table, leave both keys out.

If the table is readable but empty, no plan applies and calls are only limited by group and endpoint limits; the `plan_storage` check warns.

### Caching

The plan set (plans, default, anonymous) is read once per request and cached for **60 seconds** in `rate_limit.cache_pool` under the key `tollbooth.rate_limit.plans`. Every change made through Tollbooth (dashboard, `tollbooth:plan:setup`) deletes that cache item, so with a shared pool the change applies on every server at the next call. A change made directly in the database applies within a minute.

### Editing from the dashboard

The dashboard's configuration screen (see [dashboard.md](dashboard.md)) edits stored plans:

- plan names match `^[a-z0-9][a-z0-9_-]{0,63}$` (lowercase), and `new` is reserved;
- limits are written one per line, `60/minute` or `burst: 100/10 seconds`, with the same syntax and naming rules as YAML;
- checking *default* or *anonymous* moves that flag to the plan;
- a plan cannot be deleted while API keys use it or while it is the default or anonymous plan (the request answers 409).

Without `doctrine_plans`, the screen lists the declared plans read-only.

## Health checks

| Check | Registered when | Result |
| --- | --- | --- |
| `rate_limit_storage` | rate limiting enabled with `plans` or `doctrine_plans` | Writes then reads a probe item in `cache_pool`. Critical when the value read back differs (*does not keep what is written to it*); ok with the round-trip time. |
| `plan_storage` | `doctrine_plans` on | Critical when the table cannot be read (the declared plans apply meanwhile); warning when it is out of date (run `tollbooth:plan:setup`) or empty; ok with the number of plans and the default plan. |

See [health.md](health.md).

## Disabling rate limiting

```yaml
tollbooth:
    rate_limit: false
```

registers no rate limiting service: no limit is counted (not even group or endpoint limits), no header is sent, and `tollbooth:plan:setup` does not exist. The OpenAPI documents still list a 429 response and the rate limit headers on endpoints that declare group or endpoint limits, although nothing counts them. With rate limiting enabled but neither `plans` nor `doctrine_plans`, group and endpoint limits are still counted, but the OpenAPI documents describe the 429 response and the rate limit headers only on the endpoints that have such limits, and the `rate_limit_storage` check is not registered.
