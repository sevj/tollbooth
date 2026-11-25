# Configuration reference

Every Tollbooth setting lives under the `tollbooth` key, usually in `config/packages/tollbooth.yaml`. This page lists each key with its type, default and effect, the checks Tollbooth runs when the container is built, the services each module registers or leaves out, and the console commands with their options. Every key is optional: an empty `tollbooth:` block gives a working API protected by API keys, with documentation, health probes and, when Twig and the Translation component are installed, the dashboard. Run `bin/console config:dump-reference tollbooth` to print the tree with its built-in descriptions, and `bin/console debug:config tollbooth` to see the values your application resolves.

## Contents

- [Full tree with defaults](#full-tree-with-defaults)
- [Top level](#top-level)
- [`output`](#output)
- [`groups`](#groups)
- [`auth`](#auth)
- [`rate_limit`](#rate_limit)
- [`monitoring`](#monitoring)
- [`docs`](#docs)
- [`health`](#health)
- [`changes`](#changes)
- [`dashboard`](#dashboard)
- [Enabling and disabling modules](#enabling-and-disabling-modules)
- [Build-time validation](#build-time-validation)
- [Console commands](#console-commands)

## Full tree with defaults

```yaml
tollbooth:
    path_prefix: ''
    output:
        envelope: plain            # plain | data | json_api
        case: preserve             # preserve | snake_case | camel_case
        etag: true                 # ETag on successful GET answers, 304 on If-None-Match
    groups:
        <name>:
            namespace: null
            path: null
            prefix: ''
            output:
                envelope: null     # null | plain | data | json_api
                case: null         # null | preserve | snake_case | camel_case
            auth: null             # list of methods, null inherits auth.default
            scopes: null           # list of scopes, "auto", or null
            rate_limits: []
    auth:
        default: [api_key]
        api_key_header: X-Api-Key
        api_key_provider: null
        api_keys:
            <name>:
                id: ~              # required
                hash: ~            # required
                scopes: []
                plan: null
                expires_at: null
                allowed_ips: []
                test: false
        doctrine_keys:
            enabled: false
            connection: default
            table_prefix: tollbooth_
    rate_limit:
        enabled: true
        plans:
            <name>: ~              # list or map of limits
        default_plan: null
        anonymous_plan: null
        cache_pool: cache.app
        legacy_headers: false
        doctrine_plans:
            enabled: false
            connection: default
            table_prefix: tollbooth_
    monitoring:
        enabled: false
        connection: default
        table_prefix: tollbooth_
        calls_retention_days: 7
        aggregates_retention_days: 30
        auto_maintenance: true
        cache_pool: cache.app
    docs:
        enabled: true
        path: /_tollbooth
        title: API
        version: 1.0.0
        ui: true
    health:
        enabled: true
        probe_path: /_tollbooth/health
        token: null
        error_rate: 0.05
        unused_days: 30
        key_expiry_days: 14
        max_lag_minutes: 10
    changes:
        enabled: true
        directory: '%kernel.project_dir%/tollbooth/contract'
        impact_days: 30
        gone_after_sunset: false
    dashboard:
        enabled: true              # turned off automatically without Twig and Translation
        path: /_tollbooth
        locale: null               # null | en | fr
```

Unknown keys are rejected by Symfony's configuration component when the container is built.

### How `enabled` works

Sections with an `enabled` key follow Symfony's conventions:

- `monitoring`, `auth.doctrine_keys` and `rate_limit.doctrine_plans` are **off by default**. Writing any key inside them turns them on, so `monitoring: { connection: monitoring }` enables monitoring just as `enabled: true` does. `monitoring: true` and `monitoring: ~` also enable it.
- `rate_limit`, `docs`, `health`, `changes` and `dashboard` are **on by default**. `docs: false` is a shorthand for `docs: { enabled: false }`.

## Top level

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `path_prefix` | string | `''` | Prefix added to the path of every endpoint, for instance `/api`. Any error under this prefix, including a 404 or a 405 raised by the router before an endpoint is matched, answers RFC 9457 problem details. When it is empty, the group prefixes play that role. |

```yaml
tollbooth:
    path_prefix: /api
```

With `path_prefix: /api`, `#[Endpoint('GET', '/orders/{id}')]` answers `GET /api/orders/{id}`. See [endpoints.md](endpoints.md) for paths and route names, and [http-contract.md](http-contract.md) for the problem details format.

## `output`

Output conventions of the endpoints that belong to no group (the `default` group). Each group can override them.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `output.envelope` | enum `plain`, `data`, `json_api` | `plain` | How a returned value is wrapped: as is, under `{"data": …}`, or as a JSON:API document (`application/vnd.api+json`). Errors are never wrapped. |
| `output.case` | enum `preserve`, `snake_case`, `camel_case` | `preserve` | Case of field names, applied when reading request bodies, writing responses and naming the fields of validation errors. Tollbooth uses its own named serializers, so the application's `serializer` service keeps its own settings. |
| `output.etag` | bool | `true` | Global only. Adds an `ETag` computed from the body to every successful (`200`) `GET` or `HEAD` answer that Tollbooth renders, and answers `304 Not Modified` without a body when the request's `If-None-Match` matches it. Endpoints that return their own `Response` are not touched. The OpenAPI document lists the header and the 304. |

```yaml
tollbooth:
    output:
        envelope: data
        case: snake_case
```

Envelopes and cases are described with examples in [endpoints.md](endpoints.md) and [http-contract.md](http-contract.md).

## `groups`

A group is a set of endpoints forming one API: they share a mount prefix, output conventions, authentication, scopes and group-wide rate limits, and get their own OpenAPI document and dashboard pages. Groups are keyed by name. The name `default` is reserved for the endpoints that match no group and cannot be declared.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `groups.<name>.namespace` | string or null | `null` | Endpoints whose class lives in this namespace (or a sub-namespace) belong to the group. Leading and trailing backslashes are ignored. |
| `groups.<name>.path` | string or null | `null` | Endpoints whose **declared** path is this path or starts with it followed by `/` belong to the group. |
| `groups.<name>.prefix` | string | `''` | Prefix added to the paths of the group, after `path_prefix`. |
| `groups.<name>.output.envelope` | enum or null | `null` | Envelope of the group; `null` inherits `output.envelope`. |
| `groups.<name>.output.case` | enum or null | `null` | Field name case of the group; `null` inherits `output.case`. |
| `groups.<name>.auth` | list of strings or null | `null` | Authentication methods accepted by the group's endpoints (`api_key`, `jwt`, `user`, `anonymous`); `null` inherits `auth.default`. |
| `groups.<name>.scopes` | list of strings, `auto`, or null | `null` | Scopes every endpoint of the group requires. `auto` derives `<resource>:read` (GET, HEAD, OPTIONS) or `<resource>:write` (other methods) from the first path segment. `null` requires none. |
| `groups.<name>.rate_limits` | list or map of limit strings | `[]` | Limits shared by all the calls one consumer makes to the group, on top of its plan. `['10000/hour']` or `{burst: '50/10 seconds'}`. Each limit is named `<group>.<limit name>`. |

An endpoint belongs to the first of: the group named on its `#[Endpoint(group: …)]` attribute (`default` puts it in no group); the group with the longest matching `namespace`; the group with the longest matching `path`; otherwise `default`. Everything is resolved when the container is built.

```yaml
tollbooth:
    path_prefix: /api
    groups:
        partner:
            namespace: App\Api\Partner
            prefix: /partner
            output: { envelope: json_api, case: snake_case }
            auth: [api_key]
            scopes: auto
            rate_limits: ['10000/hour']
        backoffice:
            path: /backoffice
            output: { case: camel_case }
            auth: [jwt]
            scopes: auto
```

Group resolution is detailed in [endpoints.md](endpoints.md), authentication and scopes in [authentication.md](authentication.md), group limits in [rate-limiting.md](rate-limiting.md).

## `auth`

The Auth module is always loaded: it has no `enabled` key.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `auth.default` | list of strings | `[api_key]` | Methods accepted by endpoints whose group and class declare none. Values: `api_key`, `jwt`, `user`, `anonymous`. |
| `auth.api_key_header` | string | `X-Api-Key` | Request header carrying the API key. Also used in the `WWW-Authenticate` challenge, the OpenAPI security scheme and the dashboard's example calls. |
| `auth.api_key_provider` | string or null | `null` | Service id of an implementation of `Tollbooth\Auth\ApiKey\ApiKeyProviderInterface` that replaces the built-in lookup. `null` uses the keys of `api_keys`, plus the table when `doctrine_keys` is on. When the service implements `ManageableApiKeyProviderInterface`, the dashboard and `generate --store` manage keys with it. Cannot be combined with `doctrine_keys` (the build stops). |
| `auth.api_keys` | map | `{}` | API keys declared in configuration, keyed by consumer name. Read-only. |
| `auth.api_keys.<name>.id` | string, required | none | The 12 character key id, as printed by `tollbooth:api-key:generate`. Cannot be empty. |
| `auth.api_keys.<name>.hash` | string, required | none | SHA-256 hex digest of the secret part of the key. Cannot be empty. |
| `auth.api_keys.<name>.scopes` | list of strings | `[]` | Scopes granted to the key. |
| `auth.api_keys.<name>.plan` | string or null | `null` | Rate limit plan of the consumer. `null`, or a plan that does not exist, falls back to `rate_limit.default_plan`. Not validated at build time. |
| `auth.api_keys.<name>.expires_at` | string or null | `null` | Date or date-time parsed by `new \DateTimeImmutable()`, for instance `'2027-03-02'` (midnight at the start of that day, in PHP's default timezone). From that instant the key is refused. A value that is not a date stops the build. |
| `auth.api_keys.<name>.allowed_ips` | list of strings | `[]` | IP addresses or CIDR ranges (IPv4 or IPv6) the key may be used from. Empty allows any address. Checked against `Request::getClientIp()`, so configure `framework.trusted_proxies` behind a proxy. |
| `auth.api_keys.<name>.test` | bool | `false` | Whether the key was generated with `--test` (`tb_test_` prefix). A key presented with the other prefix is refused. |
| `auth.doctrine_keys.enabled` | bool | `false` | Also read API keys from a database table, where the dashboard and `tollbooth:api-key:generate --store` create, rotate, re-plan and revoke them. Keys of `api_keys` keep working and stay read-only. Requires `doctrine/dbal`. |
| `auth.doctrine_keys.connection` | string | `default` | Doctrine DBAL connection. A value without a dot is a DoctrineBundle connection name (`doctrine.dbal.<name>_connection`); a value with a dot is used as a service id. |
| `auth.doctrine_keys.table_prefix` | string | `tollbooth_` | Prefix of the table name; the table is `<prefix>api_key`. |

```yaml
tollbooth:
    auth:
        default: [api_key]
        api_key_header: X-Api-Key
        api_keys:
            acme-erp:
                id: qi9ywjy0lp8x
                hash: 8395c743d4b6c0a1b4a2d8a6e3c5f0e9a7b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5
                scopes: [orders:read, orders:write]
                plan: pro
                expires_at: '2027-03-02'
                allowed_ips: [203.0.113.0/24]
        doctrine_keys:
            enabled: true
            connection: default
            table_prefix: tollbooth_
```

`api_key_provider` and `doctrine_keys` cannot be set together: the build stops with a message, since keys saved in the table would never authenticate. See [authentication.md](authentication.md) for the full behaviour.

## `rate_limit`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `rate_limit.enabled` | bool | `true` | Registers the rate limiting services. When `false`, no limit is counted, not even the group or endpoint limits, and no rate limit header is sent. |
| `rate_limit.plans` | map of plan name to list or map of limit strings | `{}` | Limits of each plan, counted per consumer across every endpoint. `free: ['10/10 seconds', '60/minute']` or `pro: {burst: '100/10 seconds', day: '200000/day'}`. With `doctrine_plans`, these only seed the table and serve as fallback. |
| `rate_limit.default_plan` | string or null | `null` | Plan of consumers that have none, or whose plan does not exist. Must be a key of `plans`. `null` means such consumers have no plan limits. |
| `rate_limit.anonymous_plan` | string or null | `null` | Plan of `anonymous` consumers. Must be a key of `plans`. `null` falls back to `default_plan`. |
| `rate_limit.cache_pool` | string | `cache.app` | Service id of the PSR-6 cache pool holding the counters, and, with `doctrine_plans`, the plans cached for 60 seconds. Use a shared pool (Redis, Memcached) when several servers answer. |
| `rate_limit.legacy_headers` | bool | `false` | Also send `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` for the tightest limit. |
| `rate_limit.doctrine_plans.enabled` | bool | `false` | Keep the plans in a database table, edited from the dashboard. The table becomes the source of the plans and of the default and anonymous plans. Requires `doctrine/dbal`. |
| `rate_limit.doctrine_plans.connection` | string | `default` | Doctrine DBAL connection name, or a service id when it contains a dot. |
| `rate_limit.doctrine_plans.table_prefix` | string | `tollbooth_` | Prefix of the table name; the table is `<prefix>rate_limit_plan`. |

```yaml
tollbooth:
    rate_limit:
        plans:
            free: ['5/10 seconds', '60/minute', '1000/day']
            pro:
                burst: '100/10 seconds'
                minute: '600/minute'
                day: '200000/day'
        default_plan: free
        anonymous_plan: free
        cache_pool: cache.app
        legacy_headers: false
```

Limit syntax, windows, headers and stored plans are covered in [rate-limiting.md](rate-limiting.md).

## `monitoring`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `monitoring.enabled` | bool | `false` | Record every endpoint call in the database. Requires `doctrine/dbal` and the tables created by `tollbooth:monitoring:setup`. |
| `monitoring.connection` | string | `default` | Doctrine DBAL connection name (`default`, `monitoring`…), or a service id when it contains a dot. A dedicated database keeps the load off the application's. |
| `monitoring.table_prefix` | string | `tollbooth_` | Prefix of the tables `<prefix>call`, `<prefix>call_minute` and `<prefix>state`. |
| `monitoring.calls_retention_days` | int, at least 1 | `7` | Days raw calls are kept before the purge deletes them. |
| `monitoring.aggregates_retention_days` | int, at least 1 | `30` | Days the per-minute aggregates are kept. |
| `monitoring.auto_maintenance` | bool | `true` | Aggregate finished minutes (at most once a minute) and purge old rows (at most once an hour) at the end of requests, after the response is sent. Set to `false` and schedule `tollbooth:monitoring:maintain` instead. |
| `monitoring.cache_pool` | string | `cache.app` | Cache pool remembering when maintenance last ran. Share it between servers so they do not each run it. |

```yaml
tollbooth:
    monitoring:
        enabled: true
        connection: monitoring
        table_prefix: tollbooth_
        calls_retention_days: 7
        aggregates_retention_days: 30
        auto_maintenance: false
        cache_pool: cache.app
```

See [monitoring.md](monitoring.md).

## `docs`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `docs.enabled` | bool | `true` | Generate OpenAPI documents and serve the documentation routes. Turning it off also turns off the Changes module, and the dashboard loses its models screen. |
| `docs.path` | string | `/_tollbooth` | Base path of `<path>/docs/{group}` and `<path>/openapi/{group}.json`. The health report route `<path>/health.json` is also built from this path, even when `docs.enabled` is `false`. |
| `docs.title` | string | `API` | `info.title` of the OpenAPI documents. |
| `docs.version` | string | `1.0.0` | `info.version` of the OpenAPI documents, and the default version of `tollbooth:contract:snapshot`. |
| `docs.ui` | bool | `true` | Serve the documentation page. When `false`, only the JSON documents are served. |

```yaml
tollbooth:
    docs:
        path: /_tollbooth
        title: Shop API
        version: 2.4.0
        ui: true
```

See [openapi.md](openapi.md).

## `health`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `health.enabled` | bool | `true` | Register the health checks, findings, probes, report route and `tollbooth:health`, and autoconfigure the application's checks and finding providers. |
| `health.probe_path` | string | `/_tollbooth/health` | Base path of the `/live` and `/ready` probes. |
| `health.token` | string or null | `null` | Token that unlocks the checks in the `/ready` answer, sent as `Authorization: Bearer <token>` or `?token=<token>`. `null` or empty: `/ready` only ever gives the status. |
| `health.error_rate` | float, 0 to 1 | `0.05` | Error rate (4xx except 429, and 5xx) over the last hour above which an endpoint with at least 20 calls becomes a finding. The dashboard uses the same threshold. Needs monitoring. |
| `health.unused_days` | int, at least 1 | `30` | Endpoints without any call in this many days become a finding. Needs monitoring. |
| `health.key_expiry_days` | int, at least 1 | `14` | API keys expiring within this many days become a warning finding. |
| `health.max_lag_minutes` | int, at least 1 | `10` | The monitoring check turns to warning when aggregation is more than this many minutes behind. |

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

See [health.md](health.md).

## `changes`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `changes.enabled` | bool | `true` | Register `tollbooth:contract:snapshot`, `tollbooth:diff` and the dashboard's changes screen. Only effective while `docs.enabled` is `true`. |
| `changes.directory` | string | `%kernel.project_dir%/tollbooth/contract` | Where snapshots are written and read. Commit this directory. |
| `changes.impact_days` | int, at least 1 | `30` | Days of monitored traffic used to count the calls and consumers a change touches. |
| `changes.gone_after_sunset` | bool | `false` | Answer `410 Gone` once the sunset date of a `#[DeprecatedEndpoint]` has passed. Applies even when `changes.enabled` is `false`. |

```yaml
tollbooth:
    changes:
        directory: '%kernel.project_dir%/tollbooth/contract'
        impact_days: 30
        gone_after_sunset: true
```

See [contract-changes.md](contract-changes.md).

## `dashboard`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `dashboard.enabled` | bool | `true` | Serve the operator dashboard. Turned off automatically when the `twig` extension (symfony/twig-bundle) is not registered or symfony/translation is not installed. |
| `dashboard.path` | string | `/_tollbooth` | Base path of the dashboard pages. |
| `dashboard.locale` | enum `en`, `fr`, or null | `null` | Language of the dashboard. `null` uses the language the browser prefers. |

```yaml
tollbooth:
    dashboard:
        path: /_tollbooth
        locale: en
```

The automatic switch-off only changes the default: writing `enabled: true` explicitly without Twig or Translation installed makes the container fail to compile, because the dashboard services need the `twig` and `translator` services. Other keys (`path`, `locale`) do not turn it back on.

Tollbooth does not protect the dashboard: put its path behind a firewall. See [dashboard.md](dashboard.md).

## Enabling and disabling modules

`Core` and `Auth` are always loaded. Every other module registers no service when it is off.

| Module | Off when | Registered when on |
| --- | --- | --- |
| Core | never | Route loader `tollbooth`, argument resolvers, output renderer, problem details, timing and deprecation listeners. |
| Auth | never | Configured key provider, API key authenticator, Symfony token reader, access listener, `Consumer` argument resolver, `tollbooth:api-key:generate`. With `doctrine_keys`: the key table, the chained provider and `tollbooth:api-key:setup`. |
| RateLimit | `rate_limit.enabled: false` | Window counter, plans, rate limit listener. With `doctrine_plans`: the plan table and `tollbooth:plan:setup`. With neither `plans` nor `doctrine_plans`, the listener still counts group and endpoint limits, but the OpenAPI documents describe rate limiting only on the endpoints that have such limits, and the counter storage health check is not registered. |
| Docs | `docs.enabled: false` | OpenAPI generator, documentation routes, `tollbooth:openapi:export`. |
| Monitoring | `monitoring.enabled: false` (default) | Call storage, collector listener, automatic maintenance, `tollbooth:monitoring:setup`, `tollbooth:monitoring:maintain`. |
| Health | `health.enabled: false` | Built-in checks and finding providers, probes and report routes, `tollbooth:health`, autoconfiguration of `HealthCheckInterface` and `FindingProviderInterface`. |
| Changes | `changes.enabled: false`, or `docs.enabled: false` | Snapshot store, impact measurement, `tollbooth:contract:snapshot`, `tollbooth:diff`. |
| Dashboard | `dashboard.enabled: false`, or Twig or Translation missing | Dashboard controllers, routes, Twig extension, locale listener. Screens that need another module appear only with it (see [dashboard.md](dashboard.md)). |

Console commands are registered only when `symfony/console` is installed.

Required libraries:

| Setting | Needs | Without it |
| --- | --- | --- |
| `auth.doctrine_keys.enabled: true` | `doctrine/dbal` | The build fails: *Tollbooth stores API keys with Doctrine DBAL. Run "composer require doctrine/dbal", or disable tollbooth.auth.doctrine_keys.* |
| `rate_limit.doctrine_plans.enabled: true` | `doctrine/dbal` | The build fails: *Tollbooth stores rate limit plans with Doctrine DBAL. Run "composer require doctrine/dbal", or disable tollbooth.rate_limit.doctrine_plans.* |
| `monitoring.enabled: true` | `doctrine/dbal` | The build fails: *Tollbooth monitoring stores calls with Doctrine DBAL. Run "composer require doctrine/dbal" (or doctrine/doctrine-bundle), or disable tollbooth.monitoring.* |
| A connection name such as `default` | `doctrine/doctrine-bundle` | The service `doctrine.dbal.<name>_connection` does not exist and the container fails to compile. Without DoctrineBundle, register a `Doctrine\DBAL\Connection` service yourself and give its id (containing a dot) as `connection`. |
| `jwt` or `user` on any endpoint | `symfony/security-bundle` | The build fails: *Endpoint "…" accepts the "jwt" authentication method, which needs symfony/security-bundle and a firewall in front of it.* |
| `dashboard.enabled` | `symfony/twig-bundle` and `symfony/translation` | The dashboard is turned off silently. |

## Build-time validation

These errors stop `cache:clear` or the first request in debug mode, so a misconfiguration never reaches production.

| Situation | Error |
| --- | --- |
| A group named `default` | `"default" is the group of endpoints that match no other group and cannot be declared.` |
| `groups.<name>.auth` is not a list of strings | `"auth" must be a list of authentication methods.` |
| `groups.<name>.scopes` is neither a list of strings nor `auto` | `"scopes" must be a list of scopes or "auto".` |
| `groups.<name>.rate_limits` or a plan is not a list or map of strings | `"rate_limits" must be a list of limits such as "600/minute", or a map of names to limits.` / `A plan must be a list of limits such as "600/minute", or a map of names to limits.` |
| Unknown authentication method in `auth.default`, a group or `#[Auth]` | `tollbooth.auth.default uses the unknown authentication method "oauth". Known methods: "api_key", "jwt", "user", "anonymous".` (the context names the group or endpoint) |
| A limit that does not parse | `"600 per minute" is not a rate limit. Write it as "<count>/<interval>", for instance "600/minute", "100/10 seconds" or "200000/day".` |
| A count of 0, or an interval of 0 | Same message, or `The interval of the rate limit "…" must be at least one minute.` |
| Two limits of one plan, group or endpoint with the same name | `Two rate limits of "plan" are named "minute". Name them explicitly, for instance {burst: "100/1 minute"}.` |
| `default_plan` or `anonymous_plan` not declared under `plans` | `Invalid configuration for path "tollbooth.rate_limit": The rate limit plan "gold" is not declared under "tollbooth.rate_limit.plans".` This also applies with `doctrine_plans`: leave both keys out when the plans exist only in the table. |
| `auth.api_keys.<name>` without `id` or `hash`, or with an empty one | Symfony's required or empty-value error for that path. |
| An enum value outside its list (`output.envelope`, `output.case`, group output, `dashboard.locale`) | Symfony's invalid-value error listing the permitted values. |
| An integer below its minimum, `error_rate` outside 0 to 1 | Symfony's out-of-range error. |
| `jwt` or `user` without the security bundle | See [Enabling and disabling modules](#enabling-and-disabling-modules). |
| Missing `doctrine/dbal` for a stored feature | See [Enabling and disabling modules](#enabling-and-disabling-modules). |
| Endpoint errors: duplicate names or routes, unknown group, `#[Auth]` without method, invalid status or HTTP method, malformed deprecation dates | Described in [endpoints.md](endpoints.md). |

Not checked at build time:

- the `plan` of an API key: an unknown plan falls back to `default_plan` at runtime;
- the format of `expires_at` and `allowed_ips`;
- that a firewall actually covers the endpoints accepting `jwt` or `user`: only the presence of the security bundle is checked;
- that the cache pools, connections and provider services behave: the [health checks](health.md) cover the pools and tables at runtime.

An empty list in `auth.default` or `groups.<name>.auth` is accepted and leaves the endpoints concerned with no accepted method: every call then answers 401. Use `#[PublicEndpoint]` or `[anonymous]` for open endpoints.

## Console commands

| Command | Registered when | Arguments and options | Exit code |
| --- | --- | --- | --- |
| `tollbooth:api-key:generate` | always | `name` (required): consumer name. `--scope=<scope>`: scope granted, repeatable. `--plan=<plan>`: plan of the consumer. `--test`: generate a `tb_test_` key instead of `tb_live_`. `--store`: save it in the key table instead of printing its configuration. | 0; 1 with `--store` when `doctrine_keys` is off. |
| `tollbooth:api-key:setup` | `auth.doctrine_keys` on | `--dump-sql`: print the SQL instead of running it, to put it in a migration. | 0 |
| `tollbooth:plan:setup` | `rate_limit` and `rate_limit.doctrine_plans` on | `--dump-sql`: print the SQL and do nothing else. `--import`: also copy the declared plans the table lacks when it already holds plans. | 0 |
| `tollbooth:monitoring:setup` | `monitoring` on | `--dump-sql`: print the SQL instead of running it. | 0 |
| `tollbooth:monitoring:maintain` | `monitoring` on | `--no-purge`: only aggregate, keep everything. | 0 |
| `tollbooth:openapi:export` | `docs` on | `group` (optional, default `default`). `--output=<file>`: write to that file instead of printing. `--yaml`: YAML instead of JSON. | 0; 1 when no endpoint belongs to the group. |
| `tollbooth:contract:snapshot` | `changes` and `docs` on | `version` (optional, default `docs.version`). `--force`: replace an existing snapshot of that version. | 0; 1 when the snapshot exists without `--force`, or when the version uses characters other than letters, digits, dots, dashes and underscores. |
| `tollbooth:diff` | `changes` and `docs` on | `base` (optional): snapshot version or file, the latest snapshot by default. `--target=<version>`: compare with that snapshot instead of the code. `--fail-on=breaking\|risky\|none` (default `breaking`). `--format=text\|markdown\|json` (default `text`). | 1 when there is no snapshot, a snapshot cannot be read, or a change reaches the `--fail-on` level; 0 otherwise. |
| `tollbooth:health` | `health` on | none | 1 when the overall status is critical, 0 otherwise. |

The setup commands are idempotent: they create missing tables, alter outdated ones, and report *up to date* when nothing is left to do. Details live with each topic: [authentication.md](authentication.md#stored-keys), [rate-limiting.md](rate-limiting.md#stored-plans), [monitoring.md](monitoring.md#storage), [openapi.md](openapi.md), [contract-changes.md](contract-changes.md), [health.md](health.md#the-tollboothhealth-command).
