# Contract changes

The Changes module tells you, before a release, what your API clients will notice. At each release you save the contract (the OpenAPI documents of every group) as a snapshot committed with the code; `tollbooth:diff` then compares the code, or another snapshot, with it and classifies each difference as breaking, risky, added, changed or deprecated, taking the direction into account (a field removed from a response breaks clients, removed from a request it is only no longer read). With monitoring enabled, each breaking, risky or deprecated change shows how many calls and consumers it touches. The same module handles the deprecation of endpoints: the `Deprecation`, `Sunset` and `Link` headers, and optionally `410 Gone` after the sunset.

- [Configuration](#configuration)
- [Snapshots](#snapshots)
- [The diff](#the-diff)
- [Change kinds and rules](#change-kinds-and-rules)
- [Next version](#next-version)
- [Impact measured with monitoring](#impact-measured-with-monitoring)
- [Deprecating an endpoint](#deprecating-an-endpoint)
- [Running the diff in CI](#running-the-diff-in-ci)

## Configuration

```yaml
# config/packages/tollbooth.yaml
tollbooth:
    changes:
        enabled: true                                      # default
        directory: '%kernel.project_dir%/tollbooth/contract'
        impact_days: 30
        gone_after_sunset: false
```

| Key | Default | Effect |
| --- | --- | --- |
| `tollbooth.changes.enabled` | `true` | Registers the snapshot and diff commands and the dashboard's Changes screen. They also need `tollbooth.docs.enabled` (the snapshots are OpenAPI documents); with the Docs module off, the Changes module registers nothing. |
| `tollbooth.changes.directory` | `%kernel.project_dir%/tollbooth/contract` | Where snapshots are written and read. Commit this directory. |
| `tollbooth.changes.impact_days` | `30` | Days of monitored traffic used to measure the impact of a change. |
| `tollbooth.changes.gone_after_sunset` | `false` | Answer `410 Gone` on a deprecated endpoint once its sunset date has passed. Applies to every deprecated endpoint that has a sunset date. Works even when `changes.enabled` is `false`. |

## Snapshots

```
bin/console tollbooth:contract:snapshot [<version>] [--force]
```

| Argument / option | Default | Meaning |
| --- | --- | --- |
| `version` | `tollbooth.docs.version` | Name of the snapshot. Letters, digits, `.`, `-` and `_` only. |
| `--force` | off | Replace an existing snapshot of that version. Without it, an existing file makes the command fail. |

It writes `<directory>/<version>.json` and exits with `0`, or `1` when the snapshot already exists (without `--force`) or the version is not a valid name.

```
bin/console tollbooth:contract:snapshot 2.4.0
 [OK] Contract 2.4.0 saved to /app/tollbooth/contract/2.4.0.json (3 groups). Commit it with the release.
```

### File format

The snapshot file format is public API.

```json
{
    "version": "2.4.0",
    "created_at": "2026-11-25T10:42:00+00:00",
    "groups": {
        "default": { "openapi": "3.1.0", "info": { "...": "..." }, "paths": { "...": "..." }, "components": { "...": "..." } },
        "partner": { "openapi": "3.1.0", "...": "..." }
    }
}
```

`groups` holds, for each group that has endpoints, the exact OpenAPI document the [Docs module](openapi.md) serves. Whatever the documentation shows is therefore what is compared; what it does not show (response headers other than rate limits, plan limits, errors your handler throws) is not.

### Which snapshot is the latest

The latest snapshot is the one whose file name is the **highest version** according to PHP's `version_compare()`, not the most recent file. Name snapshots after the released versions (`2.4.0`, `2.5.0`, `3.0.0`) so that the order is right. A `v` prefix is accepted for the next-version computation but makes `version_compare()` order unreliable against names without it: pick one style.

## The diff

```
bin/console tollbooth:diff [<base>] [--target=<version>] [--fail-on=breaking|risky|none] [--format=text|markdown|json]
```

| Argument / option | Default | Meaning |
| --- | --- | --- |
| `base` | the latest snapshot | Snapshot version, or path to a snapshot file, to compare against. |
| `--target` | the current code | Snapshot version or file to compare instead of the code, to see what changed between two releases. |
| `--fail-on` | `breaking` | Exit with `1` when a change of this kind or a more severe one is found. `breaking` fails on breaking changes, `risky` on breaking or risky ones, `none` never fails. The other kind names are accepted too and follow the same order: `added` fails on breaking, risky or added changes, `changed` on everything but deprecations, `deprecated` on any change. Any other value stops the command with exit code `2`. |
| `--format` | `text` | `text` for people, `markdown` for a changelog meant for API consumers, `json` for tools. An unknown value stops the command with exit code `2`. |

### Exit codes

| Code | When |
| --- | --- |
| `0` | The comparison ran and no change reaches the `--fail-on` threshold. |
| `1` | A change reaches the threshold, or there is no snapshot to compare with, or the base or target snapshot cannot be found or read. |
| `2` | `--fail-on` or `--format` has a value that is not one of those listed. |

When the monitoring database cannot be read, the changes are listed without their impact.

### Text output

```
Contract 2.4.0 → current

 BREAKING   ProductView.stock: Removed. (139700 calls from 4 consumers in 30 days)
 RISKY      OrderStatus: New value "refunded".
 ADDED      POST /payments/{id}/refund: New endpoint.
 CHANGED    GET /products/search: The rate limit "search_products.minute" is relaxed.
 DEPRECATED GET /v1/users/{id}: Deprecated, sunset on 2027-03-01.

 1 breaking · 1 risky · 1 added · 1 changed · 1 deprecated · next version 3.0.0

 [ERROR] 1 breaking and 1 risky change against 2.4.0.
```

Changes are sorted by kind (breaking first), then group, element and message. The impact in parentheses appears only for breaking, risky and deprecated changes, and only with monitoring enabled. The `[ERROR]` line appears when the threshold is reached. When nothing changed, the command prints `[OK] No change.`

Each line names the **element** that changed:

| Element | Meaning |
| --- | --- |
| `GET /orders/{id}` | The operation itself (added, removed, security, rate limits, error statuses, deprecation). |
| `GET /orders/{id} ?page` | A path or query parameter of the operation. |
| `GET /orders/{id} body` | The request body. |
| `GET /orders/{id} response` | The success response. |
| `OrderView.status`, `OrderView.lines[]` | A field of a model, `[]` for the items of an array. A change inside a shared component is reported **once**, under the component name, with every operation that uses it. |

Paths are the paths of the OpenAPI document: the group prefix is included, `tollbooth.path_prefix` is not.

### Markdown output

```
bin/console tollbooth:diff --format=markdown --fail-on=none
```

```markdown
## 3.0.0 (unreleased)

### Breaking
- `ProductView.stock`: Removed.

### Added
- `POST /payments/{id}/refund`: New endpoint.
```

The title is the suggested next version followed by `(unreleased)` when comparing with the code, or the target version when `--target` is given. One section per kind that has changes, in the order Breaking, Risky, Added, Changed, Deprecated. Impact is not included.

### JSON output

```json
{
    "base": "2.4.0",
    "target": "current",
    "next_version": "3.0.0",
    "changes": [
        {
            "kind": "breaking",
            "group": "default",
            "element": "ProductView.stock",
            "message": "Removed.",
            "operations": ["GET /products/{id}", "GET /products"],
            "impact": { "calls": 139700, "consumers": ["acme-erp", "globex"], "consumer_ids": ["qi9ywjy0lp8x", "k2m4n6p8q0r2"] }
        }
    ]
}
```

`impact` is computed for every change in this format, whatever its kind, and is `null` when monitoring is off. `next_version` is `"next"` when the base version is not of the form `X.Y.Z`.

## Change kinds and rules

| Kind | Meaning |
| --- | --- |
| `breaking` | Existing clients will fail. |
| `risky` | Existing clients may fail if they were written strictly (an unknown enum value, a new limit). |
| `added` | New capability; existing clients are unaffected. |
| `changed` | Visible but harmless for existing clients. |
| `deprecated` | An operation became deprecated. |

"Request" below covers query and path parameters and the request body; "response" covers the success response body.

### Operations

| Difference | Kind | Message |
| --- | --- | --- |
| Operation removed | breaking | `The endpoint is removed.` |
| Operation added | added | `New endpoint.` |
| Operation becomes deprecated | deprecated | `Deprecated.` or `Deprecated, sunset on YYYY-MM-DD.` |
| Success status changes (`200` → `201`) | breaking | `The success status changes from 200 to 201.` |
| A new error status is listed | changed | `Can now answer 429.` |
| An error status is no longer listed | changed | `No longer answers 404.` |

An operation is identified by its method and path. Moving an endpoint to another path is reported as one removal and one addition.

### Parameters and body

| Difference | Kind | Message |
| --- | --- | --- |
| New required parameter | breaking | `New required parameter.` |
| New optional parameter | added | `New optional parameter.` |
| Parameter becomes required | breaking | `The parameter becomes required.` |
| Parameter removed | changed | `The parameter is no longer read.` |
| A body is now required | breaking | `A body is now required.` |
| A body is now accepted (optional) | added | `A body is now accepted.` |
| Body removed | changed | `The body is no longer read.` |

### Fields and schemas

| Difference | In a request | In a response |
| --- | --- | --- |
| Field removed | changed, `No longer read.` | **breaking**, `Removed.` |
| New field, required | **breaking**, `New required field.` | added, `New field.` |
| New field, optional | added, `New field.` | added, `New field.` |
| Existing field becomes required | **breaking**, `Becomes required.` | not reported |
| Type changes (`integer` → `string`) | **breaking** | **breaking** |
| Can now be null | changed | **risky** |
| Can no longer be null | **breaking** | changed |
| New enum value | changed | **risky** |
| Enum value removed | **breaking**, `Value … no longer accepted.` | changed, `Value … no longer sent.` |
| Bound tightened or added (`minLength`, `minimum`, `exclusiveMinimum`, `minItems` raised; `maxLength`, `maximum`, `exclusiveMaximum`, `maxItems` lowered; a bound where there was none) | **breaking**, e.g. `maxLength 255 → 64.` | not compared |
| Bound relaxed or removed | changed | not compared |

Array items and nested objects are compared recursively, following `$ref`s into `components.schemas`; a component that refers to itself is compared once.

### Security

| Difference | Kind | Message |
| --- | --- | --- |
| A public operation now requires credentials | breaking | `Credentials are now required.` |
| An operation no longer requires credentials | changed | `No credentials are required any more.` |
| A scheme is no longer accepted (`ApiKey`, `Bearer`, `Session`) | breaking | `Bearer credentials are no longer accepted.` |
| A scheme is now accepted | added | `Bearer credentials are now accepted.` |
| A scope is now required | breaking | `The scope "orders:write" is now required.` |
| A scope is no longer required | changed | `The scope "orders:write" is no longer required.` |

### Rate limits

Only the limits listed in `x-rate-limits` (the group's `rate_limits` and the endpoint's `#[RateLimit]`) are compared, by name, on their rate (calls divided by window). Plan limits are not part of the contract.

| Difference | Kind | Message |
| --- | --- | --- |
| New limit | risky | `New rate limit "search_products.minute".` |
| Limit tightened | risky | `The rate limit "…" is tightened.` |
| Limit relaxed | changed | `The rate limit "…" is relaxed.` |
| Limit removed | changed | `The rate limit "…" is lifted.` |

## Next version

The suggested next version is computed from the base snapshot's version (`X.Y.Z`, an optional leading `v` is ignored):

| Changes found | From `2.4.1` | From `0.4.1` |
| --- | --- | --- |
| At least one breaking | `3.0.0` | `0.5.0` (before 1.0, a breaking change bumps the minor version) |
| Otherwise, at least one added, risky or deprecated | `2.5.0` | `0.5.0` |
| Otherwise, only changed | `2.4.2` | `0.4.2` |
| No change | `2.4.1` | `0.4.1` |

## Impact measured with monitoring

When `tollbooth.monitoring` is enabled, the diff and the dashboard query the monitoring storage for each change: the calls made in the last `changes.impact_days` days to the operations the change touches, and the consumers who made them. The operations are matched by their `operationId`, which is the route name Tollbooth records for every call, so a removed endpoint is still measured from the base snapshot. See [monitoring.md](monitoring.md).

Impact answers "who would notice", which is what decides how a breaking change is rolled out: an endpoint nobody called in 30 days can go; one called 139,700 times by four consumers needs a deprecation period.

## Deprecating an endpoint

```php
use Tollbooth\Core\Attribute\DeprecatedEndpoint;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/users/{id}')]
#[DeprecatedEndpoint(since: '2026-06-01', sunset: '2027-03-01', link: 'https://docs.example.com/migrate-to-v2')]
final readonly class GetUserV1
{
    public function __invoke(int $id): UserV1
    {
        // ...
    }
}
```

| Argument | Required | Format | Meaning |
| --- | --- | --- | --- |
| `since` | yes | `YYYY-MM-DD` (UTC midnight) | Date of the deprecation. |
| `sunset` | no | `YYYY-MM-DD` (UTC midnight), not before `since` | Date after which the endpoint may stop answering. |
| `link` | no | URL | Migration guide. |

An invalid date, or a sunset before `since`, fails the container build with an `InvalidEndpointException`.

Every response of the endpoint, errors included, then carries:

| Header | Value | Standard |
| --- | --- | --- |
| `Deprecation` | `@<Unix timestamp of since>`, e.g. `@1780272000` | RFC 9745 |
| `Sunset` | HTTP date of the sunset, e.g. `Mon, 01 Mar 2027 00:00:00 GMT` (only with `sunset`) | RFC 8594 |
| `Link` | `<https://docs.example.com/migrate-to-v2>; rel="deprecation"` (only with `link`, added next to any `Link` header the response already has) | RFC 9745 |

What else follows from the attribute:

| Where | Effect |
| --- | --- |
| OpenAPI | `deprecated: true`, `x-deprecated-since`, `x-sunset`, `externalDocs` ([openapi.md](openapi.md#deprecation)). |
| Diff | `DEPRECATED GET /users/{id}: Deprecated, sunset on 2027-03-01.` the first time it is compared with a snapshot where it was not deprecated. |
| Health report | With monitoring, a finding per deprecated endpoint called in the last 7 days: `GET /v1/users/{id} is deprecated (sunset 2027-03-01) and was called 1240 times in 7 days by acme-erp, globex, initech and 2 more.`, flagged as a warning when the sunset is less than 30 days away or past. See [health.md](health.md). |
| Dashboard | A `DEPRECATED` or `SUNSET` tag on the map and the endpoints list, a "Deprecated" filter, the "Needs attention" list naming who still calls it, and "Upcoming changes" on each consumer's page. See [dashboard.md](dashboard.md). |

### Gone after the sunset

```yaml
tollbooth:
    changes:
        gone_after_sunset: true
```

Once the current time is at or after the sunset date, the endpoint answers after authentication but before rate limiting (when `tollbooth.changes` is enabled):

```http
HTTP/1.1 410 Gone
Content-Type: application/problem+json
Deprecation: @1780272000
Sunset: Mon, 01 Mar 2027 00:00:00 GMT
Link: <https://docs.example.com/migrate-to-v2>; rel="deprecation"

{"type":"about:blank","title":"Gone","status":410,"detail":"This endpoint was retired on 2027-03-01."}
```

Authentication runs first, so monitoring records these calls under the consumer that made them; a call without valid credentials gets the usual 401 instead of the 410.

## Running the diff in CI

The diff only needs the code and the committed snapshots. A typical job:

```yaml
# .github/workflows/contract.yaml
name: API contract
on: pull_request
jobs:
    diff:
        runs-on: ubuntu-latest
        steps:
            - uses: actions/checkout@v4
            - uses: shivammathur/setup-php@v2
              with:
                  php-version: '8.4'
            - run: composer install --no-interaction --no-progress
            - run: php bin/console tollbooth:diff --fail-on=breaking
              env:
                  APP_ENV: test
```

Keep in mind:

- With monitoring enabled in the environment the command runs in, the impact of breaking, risky and deprecated changes is read from the monitoring database. If that database cannot be read (no database in CI, for instance), the changes are listed without their impact and the exit code is unchanged.
- At release time, save the snapshot of the released contract and commit it:

  ```
  bin/console tollbooth:contract:snapshot 2.5.0
  git add tollbooth/contract/2.5.0.json
  ```

- A breaking change you make on purpose (removing an endpoint after its sunset) keeps failing `--fail-on=breaking` until a snapshot of the new major version is committed. Take that snapshot in the same change, or run the diff with `--fail-on=none` for that release.
- `bin/console tollbooth:diff --format=markdown --fail-on=none` gives the text of the release notes for API consumers.

The dashboard's Changes screen shows the same comparison, with links to the consumers concerned; see [dashboard.md](dashboard.md#changes).
