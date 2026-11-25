# Changelog

All notable changes to the **tollbooth** bundle are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). See the *Versioning* section
of `README.md` for the definition of the public API.

## [Unreleased]

### Changed
- The dashboard map shows every path whole: blocks widen to the longest path, and the map switches
  between three and two columns to fit the screen instead of cutting paths short.
- Each endpoint row of the map has fixed, labelled columns: calls, error rate and p95 with live
  traffic, success status and authentication in structure mode. The error rate no longer takes the
  place of the call count, and deprecation and throttling show as tags next to the path.

## [0.16.0] - 2026-11-25

### Added
- API keys can be stored in a database table (`tollbooth.auth.doctrine_keys`), next to the keys declared
  in the configuration, which keep working and stay read-only. `tollbooth:api-key:setup` creates the
  table (or prints its SQL with `--dump-sql`), and `tollbooth:api-key:generate --store` saves a new key
  in it.
- API key management in the dashboard: "New API key" on the consumers screen creates a key with its
  plan, scopes, expiry, allowed addresses and live or test prefix and shows it once; a consumer's page
  rotates its key (same id, new secret), changes its plan or revokes it. The forms carry a token signed
  with `kernel.secret` and are refused when the browser reports another origin.
- `ManageableApiKeyProviderInterface`, for providers that can save and remove keys, and the optional
  `createdAt` and `test` properties of `ApiKey`.
- A health check turns critical when the table of API keys cannot be read.

### Upgrade notes
- To manage keys from the dashboard, enable `tollbooth.auth.doctrine_keys` and run
  `bin/console tollbooth:api-key:setup`. Until the table exists, calls with a key not declared in the
  configuration fail.

## [0.15.1] - 2026-11-25

### Fixed
- Dashboard pages took 0.6 to 1.3 seconds: every page rebuilt the OpenAPI document of its group, sometimes
  twice, and checked the monitoring tables for the navigation. The OpenAPI documents are now built once
  per request and kept in the `cache.system` pool outside debug mode, the navigation keeps the model
  count and the collector state in `cache.app`, and the map draws its sparklines from one query. Pages
  now answer in 0.2 to 0.45 seconds on the demo. The documentation routes are faster for the same reason.

## [0.15.0] - 2026-11-25

### Added
- Changes screen in the dashboard (`/_tollbooth/changes/{group}`): the group's contract compared between
  the latest snapshot and the code, or between any two snapshots, with the count of each kind of change,
  the next version, each change with the calls and consumers it touches, the matching
  `tollbooth:diff` command for CI, a Markdown changelog for consumers to copy, and the releases with
  their breaking changes. The navigation shows how many breaking changes are waiting.
- Health screen in the dashboard (`/_tollbooth/status/{group}`): the overall status, every check grouped
  by source with its summary and duration, the findings, the facts and the probes with what they answer
  without a token.

## [0.14.0] - 2026-11-25

### Added
- Consumers screen in the dashboard (`/_tollbooth/consumers/{group}`): the rate limit plans with their
  limits and how many keys use them, a flow from consumers to the resources they call over 24 hours, and
  a table of consumers (every API key, plus users and JWT clients seen in the traffic, and anonymous
  callers grouped together) with plan, calls, daily quota used, peak per minute against the minute
  limit, 429s, error rate and last call. It filters on consumers near their limit, throttled or idle.
- Consumer page (`/_tollbooth/consumers/{group}/{consumer}`): calls since midnight against the daily
  quota with the time it runs out at the current pace, calls per minute over the last hour against the
  minute limit, endpoints used, the breaking and risky changes since the last contract snapshot and the
  deprecated endpoints it still calls, and its key: id, expiry, allowed IPs, scopes and last call.

## [0.13.0] - 2026-11-25

### Added
- Requests screen in the dashboard (`/_tollbooth/requests/{group}`): the recorded calls, newest first,
  filtered by endpoint, status class or exact status, consumer, minimum duration and period, with a live
  tail that adds new calls every two seconds and an NDJSON export. Opening a call shows its consumer and
  client, the pipeline steps it went through and the one that answered, the state of each rate limiter
  at that moment, and the response headers and problem body rebuilt from what was recorded.
- Monitoring records the state of each rate limiter with every call, read from the `RateLimit-Policy`
  and `RateLimit` headers of the response (`CallRecord::$limiters`).
- `CallQuery` accepts an exact `status`, a `minDuration` in milliseconds, an `after` cursor and an `id`.

### Upgrade notes
- The monitoring table of calls gains a `limiters` column. Run `bin/console tollbooth:monitoring:setup`,
  or `ALTER TABLE tollbooth_call ADD limiters TEXT DEFAULT NULL` (with your table prefix). Calls recorded
  before have no limiter state.

## [0.12.0] - 2026-11-25

### Added
- Models screen in the dashboard (`/_tollbooth/models/{group}/{model}`). It lists the group's output
  models, input models and enums, draws the selected model with the models it refers to (one level, two
  levels or all), shows its fields with their rules as a table, the endpoints that return or accept it,
  and a preview of how it is serialized in each envelope and field name case. The diagram downloads as
  SVG. The endpoint page links its body and response models to this screen.

### Changed
- Enum schemas in the OpenAPI documents carry the name of their PHP enum as `title`. Contract diffs
  ignore it.

## [0.11.0] - 2026-11-25

### Added
- Endpoint page in the dashboard (`/_tollbooth/endpoints/{group}/{name}`), opened from any row of the map.
  It shows the request pipeline with the endpoint's own settings (authentication and scopes, limits, body
  model, constraints, handler, response model and conventions) and, with monitoring, the share of calls
  each step answers and where the time goes around the p95. Below: the declaration as written in the
  source file, the request fields with their rules, the documented responses, example calls in cURL, PHP
  and JavaScript with an example response, the p50 and p95 latency over time, status codes, top
  consumers and recent errors grouped by message.
- `CallBreakdownInterface`, implemented by the Doctrine storage: calls by status code and errors grouped
  by status and message, with how many consumers met them and when they were last seen.

## [0.10.0] - 2026-11-25

### Added
- Operator dashboard under `/_tollbooth`, with its first screen: the API map of each group
  (`/_tollbooth/map/{group}`). It draws the group's endpoints by resource, links resources whose models
  embed or reference each other, and, with monitoring enabled, overlays the traffic of the last hour, day,
  week or month: requests, error rate, p95 latency, throttled calls and active consumers with their trend,
  each endpoint's share of calls and latency, and a "Needs attention" list of deprecated endpoints still
  called, failing, throttled and slower endpoints. The map exports to Mermaid. The pages are rendered with
  Twig, in English or French, and ship their own stylesheet, script and fonts.
- `tollbooth.dashboard.enabled`, `tollbooth.dashboard.path` and `tollbooth.dashboard.locale`.
- The health report warns when the dashboard is reachable outside any firewall.

### Upgrade notes
- The dashboard turns itself on when the application has `symfony/twig-bundle` and `symfony/translation`.
  Put `/_tollbooth` behind your firewall (an `access_control` rule such as
  `{ path: ^/_tollbooth, roles: ROLE_ADMIN }`), or set `tollbooth.dashboard.enabled: false`.

## [0.9.3] - 2026-11-25

### Fixed
- Names of groups, API keys and rate limit plans lost their dashes: Symfony's configuration turned
  `legacy-ios` into `legacy_ios`, so the consumer, its monitoring rows and its findings carried another
  name than the one configured. Names are now kept as written.

### Upgrade notes
- A consumer whose key name contains a dash shows under its real name from now on; monitoring rows
  recorded before keep the underscored one.

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
