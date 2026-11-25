# Tollbooth

Tollbooth is a Symfony bundle for building HTTP APIs from plain PHP classes. Declare an endpoint with one attribute and typed arguments; Tollbooth routes it, hydrates and validates the input, serializes the output and reports errors as RFC 9457 problem details.

Planned on top of that core: OpenAPI documentation, rate limiting with quotas, call monitoring, health checks, contract diffs between releases and an operator dashboard.

Requires PHP 8.4 and Symfony 7.4 or 8.x.

## Installation

```
composer require tollbooth/tollbooth-bundle
```

Enable the bundle in `config/bundles.php` if Flex did not:

```php
Tollbooth\TollboothBundle::class => ['all' => true],
```

Import the endpoint routes in `config/routes/tollbooth.yaml`:

```yaml
tollbooth:
    resource: .
    type: tollbooth
```

## Declaring an endpoint

An endpoint is an invokable service with the `#[Endpoint]` attribute. Route parameters are bound by name, `#[Query]` reads one query parameter and `#[Body]` deserializes the JSON request body and validates it (`#[Body(groups: ['create'])]` picks validation groups).

```php
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('POST', '/orders', status: 201)]
final readonly class CreateOrder
{
    public function __construct(private Orders $orders)
    {
    }

    public function __invoke(#[Body] CreateOrderInput $input): OrderView
    {
        return OrderView::from($this->orders->place($input));
    }
}
```

The returned object is serialized to JSON with the status declared on the attribute. An invalid body answers `422` with the violations:

```json
{
    "type": "about:blank",
    "title": "Validation Failed",
    "status": 422,
    "violations": [
        { "field": "quantity", "message": "This value should be positive." }
    ]
}
```

## Configuration

```yaml
tollbooth:
    path_prefix: /api
    output:
        envelope: plain    # plain, data or json_api
        case: preserve     # preserve, snake_case or camel_case
```

`path_prefix` is prepended to every endpoint path. Any error under it, including a 404 or a 405 from the router, answers problem details.

### Output conventions

`output.envelope` decides how a result is wrapped:

| Envelope | `GET /things/7` answers |
| --- | --- |
| `plain` | `{"id": 7, "name": "Gate"}` |
| `data` | `{"data": {"id": 7, "name": "Gate"}}` |
| `json_api` | `{"data": {"type": "things", "id": "7", "attributes": {"name": "Gate"}}}` |

With `json_api`, an object that has an `id` becomes a resource object, and a list of them a collection. The type is the plural of the class name without its `View`, `Output`, `Resource`, `Response` or `Dto` suffix (`OrderLineView` gives `order_lines`). Any other result goes under `meta`. Responses are sent as `application/vnd.api+json`.

`output.case` converts field names both ways: request bodies are read with it, response bodies are written with it, and validation errors name their fields with it. Tollbooth uses its own named serializers, so the case of the application's `serializer` service is left untouched.

Errors are never wrapped in an envelope: they always answer `application/problem+json`.

### Groups

A group is a set of endpoints that form one API: they share a mount prefix and their output conventions, and later their authentication, scopes, rate limits and documentation.

```yaml
tollbooth:
    path_prefix: /api
    groups:
        partner:
            namespace: App\Api\Partner     # endpoints whose class lives here
            prefix: /partner               # mounted under /api/partner
            output: { envelope: json_api, case: snake_case }
        admin:
            path: /admin                   # endpoints whose declared path starts with /admin
            output: { case: camel_case }
```

An endpoint belongs to the first of:

1. the group named on its attribute, `#[Endpoint('GET', '/ping', group: 'admin')]` (`default` puts it back in no group);
2. the group with the longest `namespace` containing its class;
3. the group with the longest `path` its declared path starts with;
4. otherwise the `default` group, which uses the top-level `output`.

A group setting left out inherits the top-level one (`auth.default` for `auth`). Endpoint names only need to be unique inside a group: the route of `get_order` is `tollbooth_get_order` in the default group and `tollbooth_partner_get_order` in `partner`. Everything is resolved when the container is built, so groups cost nothing per request.

## Authentication

Every endpoint requires an API key unless configured otherwise. Whatever method authenticates a call, Tollbooth turns it into a `Tollbooth\Auth\Consumer` (id, name, method, scopes, plan), which an endpoint can take as an argument and which rate limiting and monitoring will key on.

| Method | Credentials | Checked by |
| --- | --- | --- |
| `api_key` | `X-Api-Key: tb_live_…` | Tollbooth, no firewall needed |
| `jwt` | `Authorization: Bearer …` | Symfony's `access_token` authenticator (OIDC, JWKS); Tollbooth reads `client_id` and `scope` from the claims |
| `user` | the session or any other authenticator of the firewall | Symfony Security; the consumer is the user |
| `anonymous` | none | the consumer is the client IP |

`jwt` and `user` need `symfony/security-bundle` and a firewall covering the endpoints. Tollbooth refuses to build the container otherwise.

The accepted methods are resolved from the most specific declaration:

```php
#[Endpoint('POST', '/orders')]
#[Auth('api_key', 'jwt')]          // replaces the group or the default
#[Scope('orders:write')]           // repeatable, every scope is required
final class CreateOrder { … }

#[Endpoint('GET', '/status')]
#[PublicEndpoint]                  // no authentication, no scope
final class Status { … }
```

```yaml
tollbooth:
    auth:
        default: [api_key]         # endpoints that declare nothing
    groups:
        partner:
            namespace: App\Api\Partner
            auth: [api_key]
            scopes: auto           # GET /orders/… requires orders:read, POST /orders orders:write
        mobile:
            path: /app
            auth: [jwt]
            scopes: [mobile]
```

A call without accepted credentials answers 401 with a `WWW-Authenticate` challenge; a consumer lacking a scope answers 403 naming it. Scopes apply to `api_key` and `jwt` consumers; `user` consumers are left to the application's own authorization (`#[IsGranted]`, voters).

### API keys

```
bin/console tollbooth:api-key:generate acme-erp --scope=orders:read --scope=orders:write --plan=pro
```

prints the key once and the configuration to add:

```yaml
tollbooth:
    auth:
        api_keys:
            acme-erp:
                id: qi9ywjy0lp8x
                hash: 8395c743…
                scopes: [orders:read, orders:write]
                plan: pro
                expires_at: '2027-03-02'     # optional
                allowed_ips: [203.0.113.0/24] # optional
```

Only a SHA-256 hash of the secret is kept. To store keys elsewhere, implement `Tollbooth\Auth\ApiKey\ApiKeyProviderInterface` and set `tollbooth.auth.api_key_provider` to its service id. The header is set by `tollbooth.auth.api_key_header`.

#### Keys in the database

With Doctrine DBAL, keys can also live in a table, where the dashboard creates, rotates, re-plans and revokes them:

```yaml
tollbooth:
    auth:
        doctrine_keys:
            enabled: true
            connection: default      # DBAL connection name or service id
            table_prefix: tollbooth_
```

```
bin/console tollbooth:api-key:setup                  # creates tollbooth_api_key, --dump-sql for a migration
bin/console tollbooth:api-key:generate acme-erp --plan=pro --store
```

Keys declared under `api_keys` keep working next to them and stay read-only. A provider of your own can support the same management by implementing `ManageableApiKeyProviderInterface` (`save()`, `remove()`, `manages()`). The health report turns critical when the table cannot be read.

On the dashboard, "New API key" on the consumers screen asks for a name, plan, scopes, expiry date, allowed addresses and live or test prefix, then shows the key once. A consumer's page can rotate its key (same id, new secret; the old one stops working at once), change its plan or revoke it. These forms carry a token signed with `kernel.secret` and are refused when the browser says they come from another site.

## Rate limiting

A plan is a set of limits, as many as needed, counted per consumer across every endpoint. A consumer gets the plan of its API key, or `default_plan`; anonymous consumers get `anonymous_plan`.

```yaml
tollbooth:
    rate_limit:
        plans:
            free: ['5/10 seconds', '60/minute', '1000/day']
            pro:
                burst: '100/10 seconds'    # names are optional, "10s" otherwise
                minute: '600/minute'
                day: '200000/day'
        default_plan: free
        anonymous_plan: free
        cache_pool: cache.app            # use a shared pool (Redis) with several servers
        legacy_headers: false
    groups:
        partner:
            namespace: App\Api\Partner
            rate_limits: ['10000/hour']    # shared by every call to the group, named "partner.hour"
```

An endpoint can add its own limits, counted for that endpoint only:

```php
#[Endpoint('GET', '/products/search')]
#[RateLimit('30/minute')]                 // named "search_products.minute"
final class SearchProducts { … }
```

Limits are written `<count>/<interval>`, with intervals in seconds, minutes, hours or days (`100/10 seconds`, `50/5m`, `200000/day`). Windows are fixed and aligned on the clock: a minute window resets at the top of the minute, a day window at 00:00 UTC, so the reset time sent to clients is exact. A call refused by one limit is not counted against the others.

Every response of a limited endpoint, errors included, reports every limit with the [IETF RateLimit headers](https://datatracker.ietf.org/doc/draft-ietf-httpapi-ratelimit-headers/):

```
RateLimit-Policy: "burst";q=100;w=10, "minute";q=600;w=60, "day";q=200000;w=86400
RateLimit: "burst";r=97;t=4, "minute";r=581;t=34, "day";r=46020;t=35869
```

`q` is the quota, `w` the window and `t` the time until the reset, in seconds; `r` is what remains. A refused call answers 429 with `Retry-After` and the state of every limit:

```json
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

`legacy_headers: true` also sends `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` for the tightest limit. Calls rejected before authentication are not counted.

## Monitoring

Every call to an endpoint is recorded once the response has been sent: endpoint, group, method, path (without its query string), status, duration, consumer, IP, response size, a short error description, the time spent in each step (`auth`, `rate_limit`, `hydrate`, `handler`, `serialize`), and the state of each rate limiter as sent in the `RateLimit` headers. Bodies, request headers and query strings are never stored. A 500 is recorded with its exception class only, not its message.

```yaml
tollbooth:
    monitoring:
        enabled: true
        connection: default              # Doctrine DBAL connection, or "monitoring" for a dedicated database
        table_prefix: tollbooth_
        calls_retention_days: 7
        aggregates_retention_days: 30
        auto_maintenance: true
```

Create the tables once (PostgreSQL, MySQL and SQLite are supported through DBAL):

```
bin/console tollbooth:monitoring:setup             # or --dump-sql to put it in a migration
```

Each call costs one append-only `INSERT`: no row is updated on every call, so calls do not wait on each other's locks. Finished minutes are aggregated in batch into per-minute counters with a latency histogram, from which percentiles are estimated; the current minutes are read from the raw calls. With `auto_maintenance`, the end of a request runs that aggregation at most once a minute and the purge at most once an hour, after the response is sent and under a lock. Set it to `false` and schedule `tollbooth:monitoring:maintain` instead to keep that work off the web servers.

Under heavy traffic, point `connection` at a dedicated database so that monitoring never competes with the application's queries.

`Tollbooth\Monitoring\CallReaderInterface` gives the latest calls (filtered by endpoint, group, consumer or status class, with a cursor), a series per minute and totals per endpoint or per consumer. The dashboard reads it; so can the application.

## Health

| Route | Answers |
| --- | --- |
| `/_tollbooth/health/live` | `200 {"status": "ok"}` as long as PHP answers. Runs no check. |
| `/_tollbooth/health/ready` | The overall status, `503` only when a check is critical. The checks are listed only with `Authorization: Bearer <tollbooth.health.token>`. |
| `/_tollbooth/health.json` | The full report: checks, findings and facts. Meant for operators, behind the firewall. |

`bin/console tollbooth:health` prints the same report and fails on a critical status.

Tollbooth checks its own parts: compiled metadata, the rate limit counter storage, the monitoring tables and how far behind their aggregation and purge are. The application adds its own checks by implementing `Tollbooth\Core\Health\HealthCheckInterface`; autoconfigured services are picked up:

```php
final readonly class PaymentGatewayCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'payment_gateway';
    }

    public function check(): CheckResult
    {
        return $this->gateway->ping()
            ? CheckResult::ok('Answered 200.')
            : CheckResult::critical('Unreachable.');
    }
}
```

A check that throws is reported as critical with its exception class, never its message. A warning makes the status `degraded` without failing readiness.

Findings point at things worth a look without changing the status: endpoints above an error rate in the last hour, endpoints without a call in 30 days, API keys expired or about to, and Tollbooth pages left outside any firewall. The application adds its own with `FindingProviderInterface`.

```yaml
tollbooth:
    health:
        probe_path: /_tollbooth/health
        token: '%env(TOLLBOOTH_HEALTH_TOKEN)%'
        error_rate: 0.05
        unused_days: 30
        key_expiry_days: 14
        max_lag_minutes: 10
```

## Documentation

Tollbooth writes one OpenAPI 3.1 document per group from the same metadata that routes the calls, so the documentation cannot drift from the code.

| Route | Content |
| --- | --- |
| `/_tollbooth/docs/{group}` | Documentation page (Scalar), with a link per group |
| `/_tollbooth/openapi/{group}.json` | OpenAPI document |

```
bin/console tollbooth:openapi:export partner --yaml --output=public/openapi.yaml
```

What the document contains:

- **Schemas** from the PHP types of `__invoke()` arguments and return values and of the public properties of DTOs, including docblock types such as `list<OrderView>`. Backed enums become `enum`, nullable types accept `null`, dates are `date-time`, defaults are kept. Field names follow the group's case.
- **Constraints** from the validation attributes: `NotBlank`, `Length`, `Range`, `Positive`, `Count`, `Choice`, `Email`, `Url`, `Uuid` and their siblings.
- **Responses** wrapped in the group's envelope, with JSON:API resource documents whose `type` is the one Tollbooth sends.
- **Errors** each endpoint can actually answer: 400 for query or body problems, 401 when it needs credentials, 403 when it requires scopes, 404 when it has path parameters, 415 and 422 when it reads a body, 429 when it is rate limited, all as problem details.
- **Security** from the accepted methods: `ApiKey` (header), `Bearer` (JWT), `Session` (cookie), with the required scopes; public endpoints have none.
- **Rate limits** as `RateLimit-Policy` and `RateLimit` response headers, and the endpoint's own limits under `x-rate-limits`.

```yaml
tollbooth:
    docs:
        path: /_tollbooth
        title: Shop API
        version: 2.4.0
        ui: true        # false serves only the JSON documents
```

The routes are imported with the endpoints and are not protected by Tollbooth: put them behind the application firewall, or leave them open when the documentation is public. The page loads `@scalar/api-reference` 1.77.0 from jsDelivr.

## Contract changes

### Deprecating an endpoint

```php
#[Endpoint('GET', '/v1/users/{id}')]
#[DeprecatedEndpoint(since: '2026-06-01', sunset: '2027-03-01', link: 'https://docs.example.com/migrate-to-v2')]
final class GetUserV1 { … }
```

Every response then carries `Deprecation: @1780272000` (RFC 9745), `Sunset: Mon, 01 Mar 2027 00:00:00 GMT` (RFC 8594) and `Link: <…>; rel="deprecation"`. The OpenAPI operation is marked `deprecated`, and the health report names the consumers still calling it. With `tollbooth.changes.gone_after_sunset: true`, the endpoint answers 410 Gone once its sunset date has passed.

### Snapshots and diff

At each release, save the contract and commit it:

```
bin/console tollbooth:contract:snapshot 2.4.0     # writes tollbooth/contract/2.4.0.json
```

Then compare the code with the latest snapshot, in CI for instance:

```
bin/console tollbooth:diff --fail-on=breaking
```

```
 BREAKING   ProductView.stock: Removed. (139700 calls from 4 consumers in 30 days)
 RISKY      OrderStatus: New value "refunded".
 ADDED      POST /payments/{id}/refund: New endpoint.
 CHANGED    GET /products/search: The rate limit "minute" is relaxed.
 DEPRECATED GET /v1/users/{id}: Deprecated, sunset on 2027-03-01.

 1 breaking · 1 risky · 1 added · 1 changed · 1 deprecated · next version 3.0.0
```

The snapshot of a release is the set of OpenAPI documents of its groups, so whatever the documentation shows is compared. The direction matters: a field removed from a response breaks clients, removed from a request body it is only no longer read; a new enum value is risky in a response and harmless in a request; tightening a request constraint breaks, relaxing it does not. A change to a shared model is reported once, with the operations that use it. With monitoring enabled, breaking, risky and deprecated changes show how many calls and consumers they touch.

`--fail-on` takes `breaking` (default), `risky` or `none`. `--format=markdown` prints a changelog for API consumers, `--format=json` the raw changes. `--target=<version>` compares two snapshots instead of the code. The suggested next version follows semver from the base snapshot (a breaking change bumps the minor version before 1.0).

## Dashboard

An operator dashboard, rendered on the server, in English or French. It needs Twig and the Translation component, and stays off without them:

```
composer require symfony/twig-bundle symfony/translation
```

| Route | Content |
| --- | --- |
| `/_tollbooth` | Opens the map of the first group |
| `/_tollbooth/map/{group}` | API map: the group's endpoints by resource, linked where their models refer to each other |
| `/_tollbooth/map/{group}.mmd` | The same map as a Mermaid flowchart |
| `/_tollbooth/endpoints/{group}/{name}` | One endpoint: its request pipeline with this endpoint's settings, its declaration, request fields and rules, responses, example calls (cURL, PHP, JS) and response, and with monitoring its latency, status codes, top consumers and grouped errors |
| `/_tollbooth/models/{group}/{model}` | Models of the group (outputs, inputs, enums) with a diagram of the selected one and the models it refers to (depth 1, 2 or all), a table of its fields and rules, the endpoints that use it and a preview of how it is serialized in each envelope and case; the diagram exports to SVG |
| `/_tollbooth/requests/{group}` | Recorded calls, newest first, filtered with `endpoint:`, `status:4xx` or `status:429`, `consumer:`, `duration:>100` and `last:15m|1h|24h|7d`, with a live tail and an NDJSON export (`/_tollbooth/requests/{group}.ndjson`). A call opens its detail: consumer, pipeline steps and where it stopped, the limiters at that moment, the response headers and problem body rebuilt from what was recorded |
| `/_tollbooth/consumers/{group}` | Plans with their limits and keys, who calls what (consumers to resources over 24 hours), and every consumer with its plan, calls, daily quota used, peak per minute against its limit, 429s, errors and last call; filtered by near limit, throttled or idle |
| `/_tollbooth/consumers/{group}/{consumer}` | One consumer: daily quota with the time it runs out at the current pace, the minute limiter over the last hour, endpoints used, upcoming breaking or risky changes and deprecated endpoints it still calls, and its key (id, expiry, allowed IPs, scopes, last call) |
| `/_tollbooth/changes/{group}` | The contract of the group compared between the latest snapshot (or any two) and the code: changes by kind with the calls and consumers they touch, the next version, the CI command, a Markdown changelog for consumers and the releases with their breaking changes |
| `/_tollbooth/status/{group}` | The health report: overall status, each check with its duration, findings, facts and the probes with what they answer without a token |

With monitoring enabled, the map shows the traffic over the last hour, day, week or month (`?range=1h|24h|7d|30d`): requests, error rate, p95 latency, throttled calls and active consumers with their trend; each endpoint's share of its resource's calls, its p95, deprecated endpoints, endpoints throttled or answering errors above `tollbooth.health.error_rate`. A "Needs attention" list names the deprecated endpoints still called and by whom, the endpoints that fail (with their most frequent error), those hitting quotas and those whose p95 rose by half against the previous 7 days. `?mode=structure` shows the declared status and authentication instead.

Resources are the first segment of each path once the group prefix is removed. Two resources are linked when a model one of them returns embeds a model the other returns, or carries its id (`customer_id` links to `customers`).

```yaml
tollbooth:
    dashboard:
        enabled: true
        path: /_tollbooth
        locale: ~          # en or fr; defaults to the language the browser prefers
```

The pages ship with their stylesheet, script and fonts (Inter and JetBrains Mono, SIL Open Font License): no build step, no CDN. They are not protected by Tollbooth: put `/_tollbooth` behind the application firewall. The health report warns when the dashboard is reachable without one.

## Versioning

Tollbooth follows [Semantic Versioning 2.0.0](https://semver.org/). Notable changes for each release are recorded in [`CHANGELOG.md`](CHANGELOG.md), and each version is a git tag.

### Public API

The public API is the contract host applications and API clients rely on:

- the attributes `Tollbooth\Core\Attribute\Endpoint`, `Body`, `Query`, `Auth`, `Scope`, `PublicEndpoint`, `RateLimit` and `DeprecatedEndpoint`, and the limit syntax `<count>/<interval>`;
- the snapshot file format, the commands `tollbooth:contract:snapshot` and `tollbooth:diff` with their options and exit codes;
- the exception `Tollbooth\RateLimit\RateLimitExceededException` and the interface `Tollbooth\Core\Problem\ProblemMembersInterface`;
- the enum `Tollbooth\Core\Security\AuthMethod`, the value objects `Tollbooth\Auth\Consumer` and `Tollbooth\Auth\ApiKey\ApiKey`, and the interfaces `Tollbooth\Auth\ApiKey\ApiKeyProviderInterface` and `ManageableApiKeyProviderInterface`;
- the format of API keys (`tb_live_` or `tb_test_`, a 12 character id, a 32 character secret);
- the `tollbooth` route loader type;
- the `tollbooth.*` configuration keys;
- the console commands `tollbooth:api-key:generate`, `tollbooth:api-key:setup`, `tollbooth:openapi:export`, `tollbooth:monitoring:setup` and `tollbooth:monitoring:maintain` and their options;
- the monitoring interfaces `CallRecorderInterface`, `CallReaderInterface`, `CallBreakdownInterface` and `RollupInterface`, and the value objects `CallRecord`, `CallQuery`, `Stats` and `ErrorGroup`;
- the health contract in `Tollbooth\Core\Health`: `HealthCheckInterface`, `FindingProviderInterface`, `CheckResult`, `HealthStatus` and `Finding`; the API key interface `ListableApiKeyProviderInterface`;
- the health routes and the shape of their JSON, and the `tollbooth:health` command;
- the monitoring tables: a change to their schema is announced with the SQL to run;
- the documentation routes (`<docs.path>/docs/{group}`, `<docs.path>/openapi/{group}.json`); the content of the OpenAPI documents follows the endpoints and is not itself versioned;
- the dashboard routes (`<dashboard.path>`, `<dashboard.path>/map/{group}`, `<dashboard.path>/endpoints/{group}/{name}`, `<dashboard.path>/models/{group}/{model}`, `<dashboard.path>/requests/{group}`, `<dashboard.path>/consumers/{group}`, `<dashboard.path>/changes/{group}` and `<dashboard.path>/status/{group}`); what the pages show and how they look is not versioned;
- the HTTP contract: status codes, envelopes, field name cases, authentication headers and challenges, rate limit headers and the `limiters` list, and the problem details shape.

Anything else is internal and may change in any release.

### Breaking-change policy

- Removing or renaming a public attribute, interface, method or configuration key is breaking (MAJOR).
- Adding a method to a public interface that hosts implement is breaking, unless it has a default implementation.
- Changing what API clients receive (headers, problem details shape, envelopes) is breaking.
- New attributes, interfaces and optional configuration keys are MINOR.
- Fixes, performance work and refactors that keep the public API are PATCH.
- Before 1.0, a MINOR release may break; its changelog says so under *Upgrade notes*.

## Development

PHP runs in Docker:

```
docker compose run --rm php composer install
docker compose run --rm php composer qa
```

## License

MIT
