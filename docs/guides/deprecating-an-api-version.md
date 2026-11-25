# Deprecating an API version

This guide retires endpoints, or a whole group such as `v1`, without surprising the clients that still use them: announce the deprecation in every response, follow the remaining callers by name in the dashboard, the health report and the contract diff, then make the endpoints answer `410 Gone` after the sunset date and remove them. It relies on `#[DeprecatedEndpoint]` and the Changes module, described in [contract-changes.md](../contract-changes.md); following the callers needs monitoring ([monitoring.md](../monitoring.md)).

- [1. Publish the replacement](#1-publish-the-replacement)
- [2. Mark the endpoints deprecated](#2-mark-the-endpoints-deprecated)
- [3. What clients receive](#3-what-clients-receive)
- [4. Follow the remaining callers](#4-follow-the-remaining-callers)
- [5. After the sunset](#5-after-the-sunset)
- [6. Remove the endpoints](#6-remove-the-endpoints)
- [Checklist](#checklist)

## 1. Publish the replacement

Deprecate only when the replacement exists and is documented: a new version in its own group (see [configuration.md](../configuration.md) for groups and [migrating-symfony-controllers.md](migrating-symfony-controllers.md) for a full example), or a new endpoint in the same group. Write a migration guide for your clients and keep its URL: it goes in the `link` of the deprecation.

Save a contract snapshot when the replacement is released, so that the deprecation itself shows up in the next diff:

```
bin/console tollbooth:contract:snapshot 2.0.0
```

## 2. Mark the endpoints deprecated

Add `#[DeprecatedEndpoint]` to each endpoint class:

```php
namespace App\Api\V1;

use App\Api\V1\Model\UserV1;
use Tollbooth\Core\Attribute\DeprecatedEndpoint;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/users/{id}')]
#[DeprecatedEndpoint(since: '2026-06-01', sunset: '2027-03-01', link: 'https://docs.example.com/migrate-to-v2')]
final readonly class GetUser
{
    public function __invoke(int $id): UserV1
    {
        // unchanged
    }
}
```

| Argument | Format | Notes |
| --- | --- | --- |
| `since` | `YYYY-MM-DD` | Required. The day the deprecation is announced. |
| `sunset` | `YYYY-MM-DD` | Optional but recommended: the day the endpoint may stop answering. Must not be before `since`. |
| `link` | URL | Optional: your migration guide. |

Dates are read as midnight UTC. A malformed date or a sunset before `since` fails the container build.

**A whole group.** There is no group-level deprecation setting: put the attribute on every endpoint class of the group, with the same dates. To find them:

```
grep -rL "DeprecatedEndpoint" src/Api/V1
```

lists the classes of the namespace that still lack it. The dashboard's endpoint list of the group, filtered on "Deprecated", must then show every endpoint.

Choose the sunset with the traffic in mind: the [impact](../contract-changes.md#impact-measured-with-monitoring) of the change and the consumers' pages tell you how many clients must move. Six months is common for external clients.

## 3. What clients receive

Every response of a deprecated endpoint, success or error, carries:

```http
Deprecation: @1780272000
Sunset: Mon, 01 Mar 2027 00:00:00 GMT
Link: <https://docs.example.com/migrate-to-v2>; rel="deprecation"
```

| Header | Standard | Present |
| --- | --- | --- |
| `Deprecation` | RFC 9745, `@` + Unix timestamp of `since` | always |
| `Sunset` | RFC 8594, HTTP date | with `sunset` |
| `Link` with `rel="deprecation"` | RFC 9745 | with `link`, added next to any other `Link` header |

The OpenAPI operation gets `deprecated: true`, `x-deprecated-since`, `x-sunset` and `externalDocs` pointing at the link, so documentation viewers flag it (see [openapi.md](../openapi.md#deprecation)). Nothing else changes: the endpoint keeps answering as before.

Tell your clients to watch for the `Deprecation` header in their HTTP client logs; many API client libraries can log or alert on it.

## 4. Follow the remaining callers

With monitoring enabled, every call is recorded with its consumer (the API key's name, the user identifier, or the client IP for anonymous calls).

### Dashboard

| Screen | What to look at |
| --- | --- |
| Map, Live traffic | "Needs attention" lists each deprecated endpoint called in the range: `Still called 1,240 times by acme-erp, globex +2, sunset on 2027-03-01`. Consumer names open their pages. |
| Endpoints, filter "Deprecated" | Calls, errors and p95 of every deprecated endpoint over the range (`?range=30d` for the long view). |
| Endpoint page, Statistics tab | Top consumers of one endpoint. |
| Requests | `endpoint:<name>` on the group lists each call with consumer and IP; **Export · NDJSON** gives the list to whoever contacts the clients. |
| Consumer page | "Upcoming changes" lists the deprecated endpoints this consumer called in the last 30 days, with the sunset date. |

See [dashboard.md](../dashboard.md).

### Health findings

`bin/console tollbooth:health`, `/_tollbooth/health.json` and the dashboard's Health screen list one finding per deprecated endpoint called in the last 7 days:

```
GET /v1/users/{id} is deprecated (sunset 2027-03-01) and was called 1240 times in 7 days by acme-erp, globex, initech and 2 more.
```

The finding is a warning when the sunset is less than 30 days away or already past. Findings never change the health status, so they do not fail readiness probes. See [health.md](../health.md).

### Contract diff

The first diff after the deprecation reports it:

```
bin/console tollbooth:diff --fail-on=none
 DEPRECATED GET /v1/users/{id}: Deprecated, sunset on 2027-03-01. (5130 calls from 7 consumers in 30 days)
```

A deprecation counts as a minor change for the suggested next version. The Markdown output (`--format=markdown`) gives a "Deprecated" section for your release notes. See [contract-changes.md](../contract-changes.md).

### Monitoring data from your own code

To send reminders automatically, read the same data with `CallReaderInterface` (see [extending.md](extending.md#reading-monitoring-data)):

```php
$now = time();
$consumers = $calls->totals($now - 7 * 86400, $now + 60, 'consumer', new CallQuery(endpoint: 'tollbooth_v1_get_user'));
```

The endpoint is identified by its route name: `tollbooth_<group>_<name>`, or `tollbooth_<name>` in the default group.

## 5. After the sunset

You can let the endpoints refuse calls on their own from the sunset date:

```yaml
# config/packages/tollbooth.yaml
tollbooth:
    changes:
        gone_after_sunset: true
```

From the sunset date (00:00 UTC), each deprecated endpoint with a sunset answers before authentication and rate limiting:

```http
HTTP/1.1 410 Gone
Content-Type: application/problem+json
Deprecation: @1780272000
Sunset: Mon, 01 Mar 2027 00:00:00 GMT
Link: <https://docs.example.com/migrate-to-v2>; rel="deprecation"

{"type":"about:blank","title":"Gone","status":410,"detail":"This endpoint was retired on 2027-03-01."}
```

- The setting is global: it applies to every deprecated endpoint whose sunset has passed. Push back a sunset date in the attribute if one client needs more time.
- Authentication still runs first, so the dashboard and the health findings keep showing these calls under the consumer that made them; a call without valid credentials gets a 401 instead of the 410.
- The `410` responses are still recorded and count as client errors: the map keeps listing these endpoints under "Needs attention" as deprecated endpoints still called, and also as failing once they have at least 20 calls in the range and an error rate above `tollbooth.health.error_rate`.

Without `gone_after_sunset`, the endpoints keep answering normally until you remove them.

## 6. Remove the endpoints

When the traffic has stopped, or at a date you announced:

1. Delete the endpoint classes. For a whole group, also delete the group from `tollbooth.groups` (and any firewall or authenticator that only served it).
2. Run the diff. Each removal is reported:

   ```
   BREAKING   GET /v1/users/{id}: The endpoint is removed. (12 calls from 1 consumer in 30 days)
   ```

   The impact shows whether anyone called it in the last `changes.impact_days` days (calls answered `410` count, under the client IP).
3. The removal is a breaking change by definition: release it as a new major version (a new minor version before 1.0), and save its snapshot in the same change so that `tollbooth:diff --fail-on=breaking` passes again in CI:

   ```
   bin/console tollbooth:contract:snapshot 3.0.0
   ```

4. Once removed, the paths answer `404` (problem details under the API prefix).

## Checklist

| When | Action |
| --- | --- |
| Replacement released | Snapshot the contract; publish the migration guide |
| Deprecation | `#[DeprecatedEndpoint(since, sunset, link)]` on every endpoint concerned; release notes from `tollbooth:diff --format=markdown` |
| During the period | Dashboard "Needs attention" and "Deprecated" filter; health findings; contact the consumers named there |
| 30 days before the sunset | The health findings turn into warnings; contact the remaining consumers again |
| Sunset | Optionally `changes.gone_after_sunset: true` |
| Removal | Delete the classes (and the group), diff, release a major version with its snapshot |
