# Dashboard

The operator dashboard is a set of server-rendered pages under `/_tollbooth` that show what the API declares and, with monitoring, how it is used: a map of each group's resources, every endpoint with its pipeline and statistics, the models, the recorded calls with a live tail, the consumers and their quotas, the contract changes, the health report and the configuration. It can also create, rotate, re-plan and revoke API keys and edit rate limit plans when those live in the database. It ships prebuilt (stylesheet, script, fonts) in English and French, needs no build step and no CDN, and is not protected by Tollbooth: the host application's firewall must guard it.

- [Requirements and configuration](#requirements-and-configuration)
- [What each screen needs](#what-each-screen-needs)
- [Layout](#layout)
- [Map](#map)
- [Endpoints](#endpoints)
- [Endpoint page](#endpoint-page)
- [Models](#models)
- [Requests](#requests)
- [Consumers](#consumers)
- [Consumer page and API keys](#consumer-page-and-api-keys)
- [Changes](#changes)
- [Health](#health)
- [Configuration](#configuration)
- [Themes and language](#themes-and-language)
- [Routes](#routes)
- [Securing the dashboard](#securing-the-dashboard)
- [Signed forms](#signed-forms)

## Requirements and configuration

The dashboard needs Twig and the Translation component. When `symfony/twig-bundle` is not registered or `symfony/translation` is not installed, Tollbooth turns the dashboard off by itself.

```
composer require symfony/twig-bundle symfony/translation
```

```yaml
# config/packages/tollbooth.yaml
tollbooth:
    dashboard:
        enabled: true          # default
        path: /_tollbooth      # base path of every page
        locale: ~              # en or fr; null follows the browser's Accept-Language
```

| Key | Default | Effect |
| --- | --- | --- |
| `tollbooth.dashboard.enabled` | `true` | `false` registers no dashboard service and no route. |
| `tollbooth.dashboard.path` | `/_tollbooth` | Base path of the pages. |
| `tollbooth.dashboard.locale` | `null` | `en` or `fr` for everyone, or `null` for the language the browser prefers among `en` and `fr` (English otherwise). |

The dashboard reads the rest of the configuration: `tollbooth.health.error_rate` sets when an endpoint counts as failing, and the other modules decide which screens exist.

## What each screen needs

| Screen | Always | Needs |
| --- | --- | --- |
| Map | Structure: resources, endpoints, declared status and authentication | Live traffic and "Needs attention": `monitoring`. Links between resources: `docs` |
| Endpoints | List, sort by path, "Deprecated" filter | Traffic columns, other sorts and filters: `monitoring` |
| Endpoint page | Pipeline, declaration (source code) | Request fields, responses and examples: `docs`. Statistics tab: `monitoring` |
| Models | | `docs` |
| Requests | | `monitoring` |
| Consumers, consumer page | | `monitoring` |
| API key creation, rotation, plan change, revocation | | `monitoring` and `auth.doctrine_keys` |
| Changes | | `changes` and `docs` (and at least one snapshot to compare) |
| Health | | `health` |
| Configuration | Settings, plans (read-only) | Plan editing: `rate_limit.doctrine_plans` |
| "OpenAPI spec" link | | `docs` with `docs.ui: true` |

`monitoring` means `tollbooth.monitoring.enabled: true` with its tables created; see [monitoring.md](monitoring.md). A screen whose module is off is removed from the menu and has no route.

## Layout

Every page has:

- a side menu with the group switcher (each group with its mount path), the screens listed above with counters (endpoints, models, breaking changes in red), the collector state when monitoring and health are on (`collector healthy`, `collector lagging` or `collector down`, from the monitoring health check, cached 15 seconds), the theme switch, and the Tollbooth and Symfony versions;
- a top bar with breadcrumbs, a search box and the kernel environment (green dot in `prod`, amber otherwise);
- the search box filters the rows of the current page (endpoints, models, consumers) as you type. `Ctrl+K` or `⌘K` focuses it, `Escape` clears it.

Every page answers `Cache-Control: no-store` and carries `<meta name="robots" content="noindex">`.

## Map

`<path>/map/{group}`, also where `<path>` redirects (to the first group: `default` when it has endpoints, then the configured groups in their order).

The map shows the group's endpoints by **resource**: the first segment of each path once the group prefix is removed (`/v2/orders/{id}/lines` in a group with prefix `/v2` belongs to `orders`). The header gives the number of endpoints, resources and models and the group's envelope and case.

Two resources are linked by a dotted line when a model one of them returns embeds a model the other returns, or carries its id (`customer_id` links to `customers`); the label names the field. These links come from the OpenAPI document and need `docs`.

### Live traffic and structure

With monitoring, the map opens in **Live traffic** mode; the **Structure** button (`?mode=structure`) switches to the declaration view. Without monitoring, only Structure exists and the page says how to turn monitoring on.

| Mode | Each endpoint row shows |
| --- | --- |
| Structure | Method, path, declared success status, accepted authentication (`key`, `jwt`, `user`, `open`) |
| Live traffic | Method, path, calls, error rate, p95; a bar for its share of the resource's calls; a red row when it is failing; a `429` tag when it is throttling |

Deprecated endpoints are struck through with a `DEPRECATED` tag, or `SUNSET` when they have a sunset date. A row opens the endpoint page (on its Statistics tab in Live mode).

### Ranges

In Live mode, the toolbar picks the range: `?range=1h`, `24h` (default), `7d` or `30d`. Five indicators sum up the range, each with a sparkline and its trend against the previous range of the same length:

| Indicator | Value |
| --- | --- |
| Requests | Calls, with the change in percent |
| Error rate | 4xx (except 429) and 5xx over calls, with the change in points |
| Latency p95 | Estimated 95th percentile in ms, with the change |
| Throttled · 429 | Calls refused by rate limiting, and their share |
| Active consumers | Consumers that called, "of N keys" when the key provider can list its keys, otherwise the change in count |

### Needs attention

In Live mode, a list of at most 10 items for the range, busiest endpoints first, each with a link to the endpoint's statistics:

| Kind | When | Says |
| --- | --- | --- |
| `DEPRECATED` | A deprecated endpoint was called | `Still called 1,240 times by acme-erp, globex +2`, with the sunset date when there is one; consumer names link to their pages |
| `ERRORS` | At least 20 calls and an error rate above `tollbooth.health.error_rate` (5% by default) | `Error rate 7.5%, mostly 422 · quantity: This value should be positive.` (the most frequent recent error) |
| `THROTTLING` | At least 10 calls refused with 429, and at least 1% of the calls | `312 × 429, from acme-erp hitting their quota` |
| `SLOW` | p95 of at least 250 ms, at least 20 calls in the range and in the previous 7 days, and p95 up by at least 50% against those 7 days | `p95 410ms, up 64% against the last 7 days` |

When nothing qualifies: "Nothing needs attention over the last 24h."

### Mermaid export

**Export · Mermaid** downloads `<path>/map/{group}.mmd` (`{group}-map.mmd`), a Mermaid flowchart with one subgraph per resource, one node per endpoint (`GET /orders/{id}`) and the model references as dotted labelled edges:

```
flowchart LR
  subgraph r0["Orders"]
    r0_0["GET /orders"]
    r0_1["GET /orders/{id}"]
  end
  subgraph r1["Customers"]
    r1_0["GET /customers/{id}"]
  end
  r0 -.->|customer_id| r1
```

Paste it in any Markdown renderer that supports Mermaid (GitHub, GitLab, Notion) to put the map in a design document.

## Endpoints

`<path>/endpoints/{group}`: every endpoint of the group in one table.

| Column | Content |
| --- | --- |
| (method) | `GET`, `POST`… |
| path | Path without the group prefix, the endpoint name below, `DEPRECATED`/`SUNSET` tag |
| resource | Resource name |
| auth | Accepted methods |
| calls, errors, p95, 429 | With monitoring, over the range (`?range=1h|24h|7d|30d`, default `24h`) |
| success | Without monitoring, the declared success status |

With monitoring:

- **Sort** on `path`, `calls`, `errors`, `p95` or `429` by clicking the column header (`?sort=calls&dir=desc`). The default is `calls` descending; `path` sorts ascending first.
- **Filter** (`?filter=`): `all`, `failing` (above the error-rate threshold, at least 20 calls), `slow` (p95 of 250 ms or more), `throttled` (at least one 429), `deprecated`, `idle` (no call in the range).

Without monitoring, the list is sorted by path and the filters are `all` and `deprecated`. A row opens the endpoint page.

## Endpoint page

`<path>/endpoints/{group}/{name}`, where `{name}` is the endpoint name (`get_order`).

The header gives the method and path, the name, the class, the resource, and `stable` or `deprecated · sunset YYYY-MM-DD`. Buttons: **Copy cURL**, and with the documentation UI on, **OpenAPI** (the JSON document) and **Try it** (the Scalar page at the resource's tag).

With monitoring, five figures for the range (`?range=`): calls, p50, p95, error rate, throttled calls.

### Request pipeline

Six steps every call goes through, each showing this endpoint's settings and the statuses it answers:

| Step | Shows | Stops the call with |
| --- | --- | --- |
| Auth | `public`, or the accepted methods and `scope …` | 401 · 403 |
| Rate limit | `consumer plan`, preceded by the endpoint's own and group limits, or `not limited` | 429 |
| Hydrate | `body → <Model>`, number of query and typed path parameters, or `no input` | 400 · 415 |
| Validate | Number of constrained and nested fields of the body, or `no body` | 422 |
| Handler | `<Class>::__invoke` | the other error statuses recorded for the endpoint, 500 when none |
| Serialize | Response model, or `no content`; envelope and case | answers the success status(es) |

With monitoring, each step also shows the share of the range's calls that ended there, and a bar shows where the time goes for calls around the p95 (kernel, auth, limiter, hydrate · validate, handler, serialize, in ms).

### Definition and Statistics tabs

With monitoring, the rest of the page has two tabs. The selected tab is in the URL hash, so `<path>/endpoints/{group}/{name}#stats` opens the Statistics tab directly (links from the map and the endpoints list do this). The range buttons keep the hash.

**Definition** (the only content without monitoring):

| Panel | Content |
| --- | --- |
| Declaration | The class's source code, syntax-highlighted, with its file path (read from the project directory) |
| Request body / Parameters | Each field with where it comes from (path, query), its type and its rules (required, constraints); a link to the body model |
| Responses | The success status with its model, then each error status with its meaning; `errors: application/problem+json` |
| Example | cURL, PHP and JS snippets built for this endpoint (with the API key header, a Bearer token or Basic credentials as placeholders, and an example body), and an example of the success response |

The request, response and example panels come from the OpenAPI document and need `docs`.

**Statistics · {range}**:

| Panel | Content |
| --- | --- |
| Latency | p50 and p95 over the range |
| Status codes | Calls per status class and code |
| Top consumers | Consumers by calls, each linked to its page |
| Recent errors | Errors grouped by status and message, with count, number of consumers and last occurrence |

## Models

`<path>/models/{group}` redirects to the first model; `<path>/models/{group}/{model}` shows one. Needs `docs`: the models are the component schemas of the group's OpenAPI document (see [openapi.md](openapi.md)).

- **List** on the left, by kind: Output, Input, Enums, each model with the number of endpoints that use it directly.
- **Diagram** (`?view=diagram`, default): the selected model and the models it refers to, at depth 1, 2 (default) or all (`?depth=1|2|all`); direct and nested references, `?` on nullable fields, `N×` on models reused in N models. **Export · SVG** downloads `<path>/models/{group}/{model}.svg?depth=…` (`{model}.svg`).
- **Table** (`?view=table`): each field with its type (linked when it is a model) and its rules; for an enum, its values.
- **Used by**: the endpoints that take or return the model, with its role (request body, or the response status). A model only found inside other models says so.
- **Serialized as / Sent as**: a preview of the JSON the model produces, with buttons for each envelope (`plain`, `data`, `json_api`, output models only) and each case (`preserve`, `snake_case`, `camel_case`). The group's configured values are marked; `?envelope=` and `?case=` change the preview only, never the configuration.

## Requests

`<path>/requests/{group}`: the recorded calls of the group, newest first, 50 per page with **Load older** (`?before=<id>`). Needs `monitoring`. Bodies, request headers and query strings are never stored; see [monitoring.md](monitoring.md).

### Filters

The filter box takes a query (`?q=`) made of `key:value` terms and free text, separated by spaces. The chips next to it build the same query.

| Term | Matches |
| --- | --- |
| `endpoint:<name>` | One endpoint, by its name (`get_order`) or its route name (`tollbooth_v2_get_order`) |
| `status:2xx`, `status:4xx`, `status:5xx` (any `Nxx`) | A status class |
| `status:429` (any three-digit code) | One status |
| `consumer:<name or id>` | One consumer, by its name or its id (matched against the consumers seen in the group in the last 30 days) |
| `duration:>100` | Calls that took at least this many milliseconds (`>` and `=` are ignored, `duration:500` works too) |
| `last:15m`, `last:1h`, `last:24h`, `last:7d` | Time window, `1h` by default |
| any other word | Case-insensitive match against the method, the path and the recorded error, applied to the page of results |

```
consumer:acme-erp status:4xx duration:>100 last:24h
endpoint:create_order status:422 quantity
```

The foot of the list gives the number of matching calls and how many are shown.

### Live tail

**Live tail** polls `<path>/requests/{group}/feed?q=…&after=<id>` every 2 seconds and prepends the new calls matching the same filters. The browser remembers whether it is on (`localStorage`); it does not start on a page opened with **Load older**.

### NDJSON export

**Export · NDJSON** downloads `<path>/requests/{group}.ndjson?q=…` (`{group}-requests.ndjson`, `application/x-ndjson`): the calls matching the filters, newest first, at most 10,000, one JSON object per line:

```json
{"id":"019a3f2e8c41d2b7f09a1c3e5d","at":"2026-11-25T10:42:07.318Z","endpoint":"tollbooth_v2_create_order","method":"POST","path":"/api/v2/orders","status":422,"duration_ms":38.214,"consumer":"acme-erp","consumer_id":"qi9ywjy0lp8x","auth":"api_key","ip":"203.0.113.7","size":187,"error":"quantity: This value should be positive.","steps":{"auth":0.412,"rate_limit":0.803,"hydrate":2.17},"limiters":[{"name":"minute","limit":600,"window":60,"remaining":581,"reset":34}]}
```

### Call detail

Clicking a call (`?call=<id>`, the newest by default) opens its detail:

| Block | Content |
| --- | --- |
| Title | Status, method, path, recorded error, **Copy as cURL** (credentials as placeholders) |
| Facts | Request id, time (UTC, milliseconds), consumer (linked, with a link to filter its calls), authentication method and IP, endpoint (linked), response size |
| Pipeline | Each step `passed`, `answered here`, `not run` or `not configured`, with its duration, and where the call stopped |
| Limiters at that moment | Each limiter with its quota, window, what was left and when it reset |
| Response headers | Rebuilt from what was recorded (content type, rate limit headers, `Retry-After`…) |
| Response body | For an error, the problem details rebuilt from the status and the recorded error; success bodies are not stored |

## Consumers

`<path>/consumers/{group}`. Needs `monitoring`.

- **Plans**: one card per rate limit plan with its main limits (day and minute), its other limits and the number of keys on it; **Manage plans** opens the configuration screen. **New API key** appears when keys are stored in the database (see below).
- **Who calls what**: a flow diagram of the last 24 hours from consumers to resources, with each one's share; consumer names link to their pages.
- **Consumers** table, filtered with `?show=all|near|throttled|idle`:

| Column | Content |
| --- | --- |
| consumer | Name (linked); `jwt` or `user` tag for consumers without an API key; anonymous calls are grouped in one row, "anonymous · N IPs" |
| key | Key id |
| plan | Plan name |
| calls 24h | Calls in the last 24 hours |
| daily quota | Share of the plan's day limit used today, `no cap` without one |
| peak / min | Highest calls in a minute over the last hour, against the minute limit |
| 429 | Calls refused |
| errors | Error rate |
| last seen | Time since the last call, or `never` |

| Filter | Keeps consumers that |
| --- | --- |
| `near` | Used at least 80% of their daily quota, or peaked at 90% of their minute limit |
| `throttled` | Got at least one 429 |
| `idle` | Made no call |

## Consumer page and API keys

`<path>/consumers/{group}/{consumer}`, where `{consumer}` is the consumer id: the key id for API keys, the user identifier for `user` and `jwt` consumers.

| Panel | Content |
| --- | --- |
| Header | Name, plan, key id and expiry, **Open requests** (the requests screen filtered on this consumer) |
| Figures | Calls in 24 h, daily quota used, peak per minute against the limit, throttled calls, error rate |
| Daily quota | Calls since 00:00 UTC against the day limit, and at the current pace the time the quota runs out (after which every call gets a 429 until midnight UTC) |
| Minute limiter | Calls per minute over the last hour against the minute limit, the minutes that refused calls highlighted |
| Endpoints used | Up to 12 endpoints with calls, 429s and error rate |
| Upcoming changes | With the Changes and Docs modules, breaking and risky changes between the latest snapshot and the code that touch endpoints this consumer called in the last 30 days (with its number of calls), and the deprecated endpoints it called in that period, with their sunset |
| Key | Id, expiry, allowed IPs, last call and IP, scopes (the key is stored hashed and never shown) |
| Plan limits | The limits of the consumer's plan |

### Managing keys

When `tollbooth.auth.doctrine_keys` is enabled (and monitoring, which the consumers screens need), keys stored in the database can be managed here. Keys declared under `tollbooth.auth.api_keys` are shown as "declared in the configuration, read-only". See [authentication.md](authentication.md).

| Action | Where | Effect |
| --- | --- | --- |
| **New API key** | Consumers screen → `<path>/consumers/{group}/keys/new` | Form: name (letters, digits, `.`, `-`, `_`, 64 at most, unique), plan (or the default plan), scopes (separated by spaces or commas), expiry date (in the future, optional), allowed IPs or ranges (optional), test key (`tb_test_` prefix). The key is then shown **once** with a copy button; only its hash is kept. |
| **Rotate key** | Consumer page, after a confirmation | Same id, plan, scopes, expiry and IPs, new secret, shown once. The old secret stops working at once. |
| **Change plan** | Consumer page, menu of plans | Applies from the next call. |
| **Revoke** | Consumer page, after a confirmation | Deletes the key; every call with it is refused with 401. Back to the consumers screen with a confirmation. |

The pages that show a new key answer `Cache-Control: no-store` and `Pragma: no-cache`.

## Changes

`<path>/changes/{group}`. Needs the Changes and Docs modules. See [contract-changes.md](contract-changes.md) for the rules.

Without any snapshot, the screen shows the command to save the first one. Otherwise:

- two menus pick the comparison: base (`?base=<version>`, the latest snapshot by default) → target (`?target=<version>`, or the current code by default);
- figures per kind (breaking, risky, added, changed, deprecated) and the next version;
- the changes of this group by kind, each with its element and message, and for breaking, risky and deprecated changes with monitoring on, the calls and consumers they touched in the last `changes.impact_days` days (consumers linked); otherwise the number of operations concerned;
- **In CI**: the matching `tollbooth:diff` command and its exit code;
- **Changelog for consumers**: the Markdown changelog, with **Copy Markdown**;
- **Releases**: the 8 most recent snapshots with their date and their number of breaking changes against the previous one; each opens the comparison from that version.

The side menu shows the number of breaking changes between the latest snapshot and the code in red.

## Health

`<path>/status/{group}`. Needs the Health module. See [health.md](health.md).

- Overall status (Healthy, Degraded, Down), number of warnings, criticals and checks, time of the run; **Copy probe URL** (the absolute URL of `/ready`) and **Run checks** (reloads the page, which runs every check again);
- **Checks**, by source (`tollbooth`, or `application · HealthCheckInterface` for yours), each with its status, summary and duration;
- **Findings**, warnings first in amber: they do not change the status;
- **Facts**: Tollbooth, Symfony and PHP versions (with OPcache), environment, conventions, rate limit storage, monitoring storage, endpoint and model counts;
- **Probes**: the `/live` and `/ready` URLs and what `/ready` answers to a probe that sends no token.

## Configuration

`<path>/configuration/{group}`.

### Rate limit plans

The plans with their limits, the number of API keys on each (when the key provider can list its keys) and the `default` and `anonymous` markers.

- Plans declared under `tollbooth.rate_limit.plans` are read-only here.
- With `tollbooth.rate_limit.doctrine_plans` enabled, the screen creates (**New plan**, `<path>/configuration/{group}/plans/new`), edits (`<path>/configuration/{group}/plans/{plan}`) and deletes plans:

| Field | Rules |
| --- | --- |
| Name | Lowercase letters, digits, `-` and `_`, unique; cannot change later |
| Limits | One per line, `count/period` (`60/minute`, `100/10 seconds`, `10000/day`), optionally named: `burst: 10/10 seconds`; names must be unique |
| Default plan | For keys without a plan |
| Anonymous plan | For anonymous calls |

A change applies from the next call (the plans cache is cleared, see [multiple servers](guides/multiple-servers.md)). **Delete** is offered only for a plan that no key uses and that is neither the default nor the anonymous plan; the server refuses it otherwise with 409. See [rate-limiting.md](rate-limiting.md).

### Settings

The current value of every `tollbooth.*` setting, by section: General, This API group (the current group's settings), Response format, Authentication, Rate limiting, Monitoring, Documentation, Health, Changes, Dashboard. Declared API keys are left out and the health token is shown as `••••••`. Everything except stored plans is changed in `config/packages/tollbooth.yaml`, not here.

## Themes and language

The dashboard has a dark and a light theme. The switch at the bottom of the menu offers **auto** (follows the system's `prefers-color-scheme`), **light** and **dark**; the choice is kept in the browser's `localStorage` under `tollbooth.theme`.

The language is `tollbooth.dashboard.locale`, or the browser's preferred language among English and French.

## Routes

Every route is a `GET` unless stated otherwise. `{group}` matches `[A-Za-z0-9_.-]+`. The routes marked *public* are part of the public API (their paths are stable; what the pages show is not versioned); the others may change in any release.

| Route name | Path | Content | Public |
| --- | --- | --- | --- |
| `tollbooth_dashboard` | `<path>` | Redirects to the map of the first group | yes |
| `tollbooth_dashboard_map` | `<path>/map/{group}` | Map (`?mode=structure`, `?range=`) | yes |
| `tollbooth_dashboard_map_mermaid` | `<path>/map/{group}.mmd` | Mermaid export | |
| `tollbooth_dashboard_endpoints` | `<path>/endpoints/{group}` | Endpoints (`?range=`, `?filter=`, `?sort=`, `?dir=`) | yes |
| `tollbooth_dashboard_endpoint` | `<path>/endpoints/{group}/{name}` | Endpoint page (`?range=`, `#stats`) | yes |
| `tollbooth_dashboard_models` | `<path>/models/{group}` | Redirects to the first model | |
| `tollbooth_dashboard_model` | `<path>/models/{group}/{model}` | Model (`?view=`, `?depth=`, `?envelope=`, `?case=`) | yes |
| `tollbooth_dashboard_model_svg` | `<path>/models/{group}/{model}.svg` | SVG export (`?depth=`) | |
| `tollbooth_dashboard_requests` | `<path>/requests/{group}` | Requests (`?q=`, `?before=`, `?call=`) | yes |
| `tollbooth_dashboard_requests_feed` | `<path>/requests/{group}/feed` | Live tail feed, JSON (`?q=`, `?after=`) | |
| `tollbooth_dashboard_requests_export` | `<path>/requests/{group}.ndjson` | NDJSON export (`?q=`) | |
| `tollbooth_dashboard_consumers` | `<path>/consumers/{group}` | Consumers (`?show=`) | yes |
| `tollbooth_dashboard_key_new` | `<path>/consumers/{group}/keys/new` | New key form, `GET` and `POST` | |
| `tollbooth_dashboard_key_rotate` | `<path>/consumers/{group}/{consumer}/rotate` | `POST` | |
| `tollbooth_dashboard_key_plan` | `<path>/consumers/{group}/{consumer}/plan` | `POST` | |
| `tollbooth_dashboard_key_revoke` | `<path>/consumers/{group}/{consumer}/revoke` | `POST` | |
| `tollbooth_dashboard_consumer` | `<path>/consumers/{group}/{consumer}` | Consumer page | |
| `tollbooth_dashboard_changes` | `<path>/changes/{group}` | Changes (`?base=`, `?target=`) | yes |
| `tollbooth_dashboard_health` | `<path>/status/{group}` | Health | yes |
| `tollbooth_dashboard_configuration` | `<path>/configuration/{group}` | Configuration | yes |
| `tollbooth_dashboard_plan_new` | `<path>/configuration/{group}/plans/new` | New plan form, `GET` and `POST` | |
| `tollbooth_dashboard_plan_edit` | `<path>/configuration/{group}/plans/{plan}` | Plan form, `GET` and `POST` | |
| `tollbooth_dashboard_plan_delete` | `<path>/configuration/{group}/plans/{plan}/delete` | `POST` | |
| `tollbooth_dashboard_asset` | `<path>/assets/{file}` | Stylesheet, script, fonts, icon | |

With the default paths, the documentation routes (`/_tollbooth/docs/{group}`, `/_tollbooth/openapi/{group}.json`, see [openapi.md](openapi.md)) and the health routes (`/_tollbooth/health.json`, `/_tollbooth/health/live`, `/_tollbooth/health/ready`, see [health.md](health.md)) share the `/_tollbooth` prefix.

## Securing the dashboard

Tollbooth does not protect `/_tollbooth`. The dashboard shows consumer names, IPs, errors and configuration, and can issue and revoke API keys: put it behind the application firewall and an access rule. The README and the health report warn about it: a finding says so when `<dashboard.path>/map`, `<docs.path>/docs` or `<docs.path>/health.json` is outside any firewall. That finding only checks that a firewall with security enabled covers the path; the roles come from `access_control`.

A typical setup: a stateful firewall with a login form for operators, the API on its own stateless firewall, the probes and (optionally) the documentation open.

```yaml
# config/packages/security.yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: auto

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email

    firewalls:
        dev:
            pattern: ^/(_profiler|_wdt|assets|build)/
            security: false
        tollbooth_probes:
            pattern: ^/_tollbooth/health/(live|ready)$
            security: false
        api:
            pattern: ^/api
            stateless: true
            # the API's own authenticators, if any (see authentication.md)
        main:
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: app_login
                check_path: app_login
                enable_csrf: true
            logout:
                path: app_logout

    access_control:
        - { path: ^/_tollbooth, roles: ROLE_ADMIN }
```

- Firewalls are matched in order: keep the `api` firewall before `main` when its pattern would otherwise be caught by a catch-all.
- The probes (`<health.probe_path>/live` and `/ready`, `/_tollbooth/health/...` by default) must stay reachable by orchestrators: they are excluded above. `/ready` details only appear with `Authorization: Bearer <tollbooth.health.token>`. Alternatively move them with `tollbooth.health.probe_path`.
- To publish the documentation, add a firewall with `pattern: ^/_tollbooth/(docs|openapi)(/|$)` and `security: false` before `main`. The health report then lists the documentation as outside any firewall, which is what you want.
- `<docs.path>/health.json` (the full report) stays behind the `^/_tollbooth` rule.
- To serve the dashboard elsewhere, change `tollbooth.dashboard.path` and the patterns together.

## Signed forms

Every form that changes something (create, rotate, re-plan or revoke a key; create, edit or delete a plan) carries a `_token` field: an HMAC-SHA256 of the action and its subject (the key id or plan name) signed with `kernel.secret`. The server refuses the request with 403 when:

- the token is missing or does not match;
- the browser sends `Sec-Fetch-Site` with a value other than `same-origin` or `none`;
- the browser sends an `Origin` header (other than `null`) whose host differs from the request's host. Only the host is compared, so a proxy that terminates TLS (the browser on `https`, PHP seeing `http`) does not break the forms.

Consequences:

- The token does not depend on the session: it protects against forms posted from other sites, while the firewall decides who may use the dashboard at all.
- All servers must share the same `kernel.secret` (`APP_SECRET`), or a form rendered by one server is refused by another. Changing the secret only invalidates forms already open in a browser; reload them. See [guides/multiple-servers.md](guides/multiple-servers.md).
- Behind a reverse proxy that rewrites the host, configure Symfony's trusted proxies (`framework.trusted_proxies` and `framework.trusted_headers`) so that the host Symfony sees matches the browser's `Origin`; otherwise every form is refused.
