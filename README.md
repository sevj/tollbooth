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

Every call to an endpoint is recorded once the response has been sent: endpoint, group, method, path (without its query string), status, duration, consumer, IP, response size, a short error description, and the time spent in each step (`auth`, `rate_limit`, `hydrate`, `handler`, `serialize`). Bodies and headers are never stored. A 500 is recorded with its exception class only, not its message.

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

## Versioning

Tollbooth follows [Semantic Versioning 2.0.0](https://semver.org/). Notable changes for each release are recorded in [`CHANGELOG.md`](CHANGELOG.md), and each version is a git tag.

### Public API

The public API is the contract host applications and API clients rely on:

- the attributes `Tollbooth\Core\Attribute\Endpoint`, `Body`, `Query`, `Auth`, `Scope`, `PublicEndpoint` and `RateLimit`, and the limit syntax `<count>/<interval>`;
- the exception `Tollbooth\RateLimit\RateLimitExceededException` and the interface `Tollbooth\Core\Problem\ProblemMembersInterface`;
- the enum `Tollbooth\Core\Security\AuthMethod`, the value objects `Tollbooth\Auth\Consumer` and `Tollbooth\Auth\ApiKey\ApiKey`, and the interface `Tollbooth\Auth\ApiKey\ApiKeyProviderInterface`;
- the format of API keys (`tb_live_` or `tb_test_`, a 12 character id, a 32 character secret);
- the `tollbooth` route loader type;
- the `tollbooth.*` configuration keys;
- the console commands `tollbooth:api-key:generate`, `tollbooth:openapi:export`, `tollbooth:monitoring:setup` and `tollbooth:monitoring:maintain` and their options;
- the monitoring interfaces `CallRecorderInterface`, `CallReaderInterface` and `RollupInterface`, and the value objects `CallRecord`, `CallQuery` and `Stats`;
- the health contract in `Tollbooth\Core\Health`: `HealthCheckInterface`, `FindingProviderInterface`, `CheckResult`, `HealthStatus` and `Finding`; the API key interface `ListableApiKeyProviderInterface`;
- the health routes and the shape of their JSON, and the `tollbooth:health` command;
- the monitoring tables: a change to their schema is announced with the SQL to run;
- the documentation routes (`<docs.path>/docs/{group}`, `<docs.path>/openapi/{group}.json`); the content of the OpenAPI documents follows the endpoints and is not itself versioned;
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
