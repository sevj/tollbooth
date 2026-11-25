# Changelog

All notable changes to the **tollbooth** bundle are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). See the *Versioning* section
of `README.md` for the definition of the public API.

## [Unreleased]

## [0.9.2] - 2026-11-25

### Fixed
- Lists of objects in a `#[Body]` (`@param list<OrderItemInput> $items`) were left as arrays of arrays
  unless the application happened to install `phpdocumentor/type-resolver`, because FrameworkBundle only
  reads docblock types with it. Tollbooth now requires it, so nested lists are hydrated and validated.
  Found while building the demo application.

## [0.9.1] - 2026-11-25

### Fixed
- `scopes: auto` on a group matched by `path` derived every scope from that path: `/backoffice/tickets`
  required `backoffice:write`. The group's path is now skipped, so it requires `tickets:write`.

### Upgrade notes
- Keys and tokens of a path-matched group with `scopes: auto` need the resource scopes instead of the
  group one.

## [0.9.0] - 2026-11-25

### Added
- `#[DeprecatedEndpoint(since:, sunset:, link:)]` adds `Deprecation`, `Sunset` and `Link` headers to every
  response of the endpoint and marks its OpenAPI operation `deprecated`. With
  `tollbooth.changes.gone_after_sunset`, a retired endpoint answers 410.
- `tollbooth:contract:snapshot <version>` saves the OpenAPI documents of every group under
  `tollbooth.changes.directory`, to be committed with the release.
- `tollbooth:diff [base]` compares the code (or `--target`) with a snapshot and classifies every change as
  breaking, risky, added, changed or deprecated, taking its direction into account. It reports the impact
  on monitored traffic, suggests the next version, prints a consumer changelog with `--format=markdown`,
  and fails CI with `--fail-on=breaking|risky`.
- Health finding: deprecated endpoints still called in the last 7 days, and by whom.

## [0.8.0] - 2026-11-25

### Added
- Health probes: `/_tollbooth/health/live` answers as long as PHP does, `/_tollbooth/health/ready` gives
  the overall status and fails with 503 on a critical check. Its details need the `tollbooth.health.token`.
- A full report at `/_tollbooth/health.json` and from `tollbooth:health`: checks, findings and facts
  (versions, environment, conventions, storages).
- Tollbooth checks the compiled metadata, the rate limit counter storage, and the monitoring tables with
  how far behind aggregation and purge are.
- Applications add checks by implementing `HealthCheckInterface` and findings with
  `FindingProviderInterface`; autoconfigured services are picked up. A failing check is critical and
  reported with its exception class only.
- Findings: endpoints above `error_rate` in the last hour, endpoints without a call in `unused_days`, API
  keys expired or expiring within `key_expiry_days`, and Tollbooth pages left outside any firewall.
- `ListableApiKeyProviderInterface` lets a key provider list its keys; the in-memory one does.

### Upgrade notes
- The probes are public routes. Leave `/live` and `/ready` reachable by the orchestrator, keep
  `/_tollbooth/health.json` behind the firewall, and set `tollbooth.health.token` from an environment
  variable.

## [0.7.0] - 2026-11-25

### Added
- `tollbooth.monitoring` records every call to an endpoint after the response is sent: endpoint, group,
  method, path, status, duration, consumer, IP, size, a short error description and the time spent in
  each step (`auth`, `rate_limit`, `hydrate`, `handler`, `serialize`). Bodies, headers and query strings
  are never stored, and server errors keep their exception class only.
- Calls are stored through Doctrine DBAL in the application's connection by default, or in a dedicated
  one. Each call is a single append-only insert. Finished minutes are aggregated in batch with a latency
  histogram; percentiles are estimated from it.
- `auto_maintenance` aggregates and purges at the end of requests, at most once a minute and once an hour,
  under a lock. `tollbooth:monitoring:maintain` does the same from a scheduler.
- `tollbooth:monitoring:setup` creates or updates the tables, or prints the SQL with `--dump-sql`.
- `CallReaderInterface` lists the latest calls with filters and a cursor, and gives per-minute series and
  totals per endpoint or per consumer.

### Changed
- A call refused for a missing scope (403) is now attributed to its consumer.

### Upgrade notes
- Monitoring is off until `tollbooth.monitoring.enabled: true`. It needs `doctrine/dbal`, usually through
  `doctrine/doctrine-bundle`, and `tollbooth:monitoring:setup` once.

## [0.6.0] - 2026-11-25

### Added
- One OpenAPI 3.1 document per group, generated from the compiled endpoint metadata and served at
  `/_tollbooth/openapi/{group}.json`, with a documentation page at `/_tollbooth/docs/{group}`.
- Schemas are read from PHP types, docblock types (`list<OrderView>`) and validation constraints, named
  in the group's case and wrapped in the group's envelope, JSON:API resource documents included.
- Each operation lists the errors it can actually answer (400, 401, 403, 404, 415, 422, 429) as problem
  details, its security requirements with their scopes, and its rate limit headers.
- `tollbooth:openapi:export [group] [--output=file] [--yaml]` prints or writes a document.
- `tollbooth.docs` sets the base `path`, the `title` and `version` of the documents, and whether the
  `ui` page is served. `tollbooth.docs.enabled: false` removes the routes.

### Upgrade notes
- The documentation routes are imported with the endpoints. Protect `/_tollbooth` with the firewall if
  the documentation must not be public.

## [0.5.0] - 2026-11-25

### Added
- Rate limiting per consumer. `tollbooth.rate_limit.plans` declares plans made of any number of limits
  (`["5/10 seconds", "60/minute", "1000/day"]`, or a map to name them). A consumer gets the plan of its
  API key or `default_plan`; anonymous consumers get `anonymous_plan`.
- Groups take `rate_limits`, shared by every call to the group, and endpoints take `#[RateLimit(...)]`,
  counted for that endpoint only. Both come on top of the plan.
- Windows are fixed and aligned on the clock (a day resets at 00:00 UTC), so clients get an exact reset
  time. A call refused by one limit is not counted against the others.
- Every response of a limited endpoint, errors included, carries `RateLimit-Policy` and `RateLimit`
  headers describing every limit. A refused call answers 429 with `Retry-After` and a `limiters` list in
  its problem details. `legacy_headers: true` adds `X-RateLimit-*` for the tightest limit.
- Counters live in any PSR-6 pool (`cache_pool`, `cache.app` by default), guarded by symfony/lock.
- Exceptions implementing `ProblemMembersInterface` add their own members to problem details.

### Upgrade notes
- Nothing is limited until plans or limits are declared. `tollbooth.rate_limit.enabled: false` removes
  the listeners altogether.
- With several servers, point `cache_pool` at a shared pool (Redis) and configure a shared lock store, or
  each server counts on its own.

## [0.4.0] - 2026-11-25

### Added
- Endpoints are authenticated, and every call gets a `Tollbooth\Auth\Consumer` (id, name, method, scopes,
  plan) that an endpoint can take as an argument. Four methods: `api_key`, checked by Tollbooth without
  any firewall; `jwt` and `user`, read from the token Symfony Security produced; `anonymous`, keyed on
  the client IP.
- Accepted methods come from `#[PublicEndpoint]`, then `#[Auth(...)]`, then the group's `auth`, then
  `tollbooth.auth.default`.
- Required scopes come from `#[Scope(...)]` (repeatable), then the group's `scopes`. `scopes: auto` on a
  group derives `<resource>:read` or `<resource>:write` from the first segment of the path and the HTTP
  method. Scopes apply to `api_key` and `jwt` consumers.
- A call without accepted credentials answers 401 with a `WWW-Authenticate` challenge per accepted method.
  An invalid, expired or IP-restricted API key answers 401 with the reason. A missing scope answers 403
  naming it.
- API keys (`tb_live_` or `tb_test_`, an id and a secret) are declared under `tollbooth.auth.api_keys`
  with their scopes, plan, expiry and allowed IPs. Only a SHA-256 hash of the secret is kept.
  `tollbooth:api-key:generate` creates one and prints its configuration. Keys can come from any service
  implementing `ApiKeyProviderInterface`.
- The container build fails when an endpoint accepts `jwt` or `user` and `symfony/security-bundle` is
  not installed.

### Upgrade notes
- Endpoints now require an API key by default. To keep them open as in 0.3, set
  `tollbooth.auth.default: [anonymous]`, or mark open endpoints with `#[PublicEndpoint]`.

## [0.3.0] - 2026-11-25

### Added
- `tollbooth.groups` declares sets of endpoints that form one API. An endpoint joins a group by namespace
  (`namespace: App\Api\Partner`), by path (`path: /admin`), or explicitly with
  `#[Endpoint(group: 'partner')]`; the explicit group wins, then the longest namespace, then the longest
  path. A group can mount its endpoints under a `prefix` and override `output.envelope` and `output.case`;
  what it leaves out comes from the top-level settings.
- Endpoint names only need to be unique inside their group. Routes of grouped endpoints are named
  `tollbooth_<group>_<name>`.
- When `path_prefix` is empty, errors under the prefix of any group answer problem details.

### Upgrade notes
- `default` is reserved as the name of the group of endpoints that match no other group.

## [0.2.0] - 2026-11-25

### Added
- `tollbooth.output.envelope` wraps every successful response: `plain` (as is, the default), `data`
  (`{"data": …}`) or `json_api`. With `json_api`, an object with an `id` becomes a resource object
  (`type`, `id`, `attributes`), a list of them a collection, anything else goes under `meta`, and the
  response is sent as `application/vnd.api+json`.
- `tollbooth.output.case` converts field names in request bodies, response bodies and validation errors:
  `preserve` (the default), `snake_case` or `camel_case`. Tollbooth serializes through its own named
  serializers, so the application's `serializer` service keeps its own configuration.
- `#[Body]` takes validation `groups`.
- A body of the wrong JSON type (`"quantity": "two"`) answers 422 with one violation per field, instead of
  a generic 400. A body that is not JSON answers 415.
- Any error under `tollbooth.path_prefix` answers problem details, including the router's 404 and 405 (with
  their `Allow` header). Router errors carry no `detail`, so internal URLs are not echoed back.

### Changed
- `#[Body]` no longer extends Symfony's `#[MapRequestPayload]`: Tollbooth reads the body itself, so that
  it follows `tollbooth.output.case`. Only JSON bodies are accepted.

### Upgrade notes
- `#[Body]` only takes `groups`. Remove any `#[MapRequestPayload]` option passed to it, such as
  `acceptFormat` or `validationFailedStatusCode`.

## [0.1.0] - 2026-11-25

### Added
- `#[Endpoint]` turns an invokable service into an API endpoint: HTTP method, path, success status and an
  optional name. The route is generated from it, and its metadata is read once when the container is built,
  never on a request.
- The `tollbooth` route loader imports every endpoint, under the optional `tollbooth.path_prefix`.
- Route parameters are bound to `__invoke()` arguments by name. An `int` or backed enum argument restricts
  its route parameter, so `/things/abc` answers 404 instead of reaching the endpoint.
- `#[Query]` reads one query parameter into a typed argument (`string`, `int`, `float`, `bool`, backed enum,
  `array`). A missing parameter falls back to the default value or `null`; a missing required parameter or
  a value of the wrong type answers 400.
- `#[Body]` deserializes the request body into an object and validates it, on top of Symfony's
  `#[MapRequestPayload]`.
- The value returned by an endpoint is serialized to JSON with the declared status. `null` answers
  `204 No Content` when the declared status is 200.
- Errors thrown by an endpoint answer RFC 9457 `application/problem+json`, with a `violations` list for
  validation failures. The message of a server error is only shown in debug mode.
