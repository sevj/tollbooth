# HTTP contract

This page describes what API clients receive from a Tollbooth API: status codes, the RFC 9457 problem details body of every error, response envelopes and field name cases, rate limit headers, deprecation headers and content types. It is written for whoever builds or documents a client, and for host developers who need to know what their endpoints promise. How endpoints are declared is in [endpoints.md](endpoints.md); the configuration keys mentioned here are in [configuration.md](configuration.md).

## Versioning

The HTTP contract is public API under the project's [versioning policy](../README.md#public-api): status codes, envelopes, field name cases, authentication headers and challenges, rate limit headers and the `limiters` list, deprecation headers and the problem details shape. Changing any of them in a way clients can observe is a MAJOR change. While Tollbooth is `0.x`, a MINOR release may do so and says it under *Upgrade notes* in the changelog.

What an individual API returns (its paths, fields and rules) is the host application's own contract. Tollbooth helps keep it stable with snapshots and a diff; see [contract-changes.md](contract-changes.md).

## Which responses follow this contract

| Request | Success | Errors |
| --- | --- | --- |
| Matched to a Tollbooth endpoint | Serialized by Tollbooth (see [Successful responses](#successful-responses)) | Problem details, with `detail` |
| Not matched to an endpoint, but under the API prefix (404, 405, and errors of the application's own controllers there) | Untouched | Problem details, without `detail` |
| Anything else | Untouched | Symfony's usual error handling |

The API prefix is `tollbooth.path_prefix`. When it is empty, the prefixes of the configured groups are used instead; when those are empty too, only requests matched to an endpoint answer problem details. A path is under a prefix when it equals it or continues with `/`.

The `Accept` header is not read: successful responses are always JSON, errors always problem details.

## Successful responses

| Situation | Status | Body | `Content-Type` |
| --- | --- | --- | --- |
| The handler returns a value | The endpoint's declared status (default `200`) | The value in the group's envelope and field case | `application/json`, or `application/vnd.api+json` with the `json_api` envelope |
| The handler returns nothing, declared status `200` | `204 No Content` | empty | none |
| The handler returns nothing, another declared status (`201`, `202`) | the declared status | empty | not set by Tollbooth |
| The handler returns a Symfony `Response` | its own | its own | its own |
| A `GET` or `HEAD` answered `200` by Tollbooth whose `If-None-Match` matches its `ETag` | `304 Not Modified` | empty | none |

Every `200` answer to a `GET` or `HEAD` that Tollbooth renders carries an `ETag` header computed from the body (strong, quoted). Send it back in `If-None-Match` to get a `304` without a body while the answer has not changed. `tollbooth.output.etag: false` turns this off; answers built by the handler as a `Response` never get one.

### Envelopes

Chosen per group by `output.envelope`. Errors are never wrapped.

**`plain`** (default): the value as is.

```json
{"id": 7, "name": "Gate", "unitCount": 4}
```

**`data`**: the value under `data`, whatever its type.

```json
{"data": {"id": 7, "name": "Gate", "unitCount": 4}}
```

**`json_api`**: a JSON:API document, sent as `application/vnd.api+json`.

```json
{
    "data": {
        "type": "things",
        "id": "7",
        "attributes": {"name": "Gate", "unit_count": 4}
    }
}
```

- An object with a non-null scalar `id` is a resource: `type`, `id` as a string, and every other field under `attributes`. Nested objects stay inside `attributes`; there are no `relationships`, `links` or `included` members.
- A list of resources is `{"data": [...]}`; an empty list is `{"data": []}`.
- Anything else is `{"meta": ...}`, for instance `{"meta": {"total": 3}}`.
- `type` is the plural of the returned class name without its `View`, `Output`, `Resource`, `Response` or `Dto` suffix, in snake_case for `snake_case` groups and camelCase otherwise (`order_lines` or `orderLines`). The rules are in [endpoints.md](endpoints.md#jsonapi-rules).

Request bodies are never enveloped: a client sends the bare object, also to `data` and `json_api` groups.

### Field name cases

Chosen per group by `output.case`: `preserve` (names as declared in PHP), `snake_case` or `camel_case`. The same case is used for object fields in responses, for fields read from request bodies, and for the `field` of validation violations. Problem members (`type`, `title`, `limiters`…), query parameter names and keys of plain arrays returned by a handler are not converted. Details in [endpoints.md](endpoints.md#field-name-cases).

### Value formats

| PHP value | JSON |
| --- | --- |
| Backed enum | its value |
| `DateTimeInterface` | RFC 3339 string, `"2026-11-25T14:02:05+00:00"` |
| `float` | a number keeping its decimal part, `1.0` |
| `null` property | `null` (the field is present) |

Slashes and non-ASCII characters are not escaped.

## Problem details

Every error answers `application/problem+json` (RFC 9457) with this shape:

```json
{
    "type": "about:blank",
    "title": "Not Found",
    "status": 404,
    "detail": "Order o_123 does not exist."
}
```

| Member | Presence | Value |
| --- | --- | --- |
| `type` | always | `"about:blank"` |
| `title` | always | The standard reason phrase of the status (`"Not Found"`, `"Too Many Requests"`, `"Unprocessable Content"`), or `"Validation Failed"` when `violations` is present. `"Error"` for a status without a reason phrase. |
| `status` | always | The HTTP status code, as an integer. Always equal to the response status. |
| `detail` | when there is something to say | The message of the exception. Absent when the message is empty, when `violations` is present, for a 5xx status unless the application runs with `kernel.debug`, and for requests not matched to an endpoint. |
| `violations` | 422 from validation | List of `{"field": string, "message": string}`. |
| other members | some statuses | Added by the exception, such as `limiters` on a 429. They cannot replace the members above. |

Members appear in the order above. `instance` is never sent. Headers carried by the exception (`Allow`, `WWW-Authenticate`, `Retry-After`) are sent with the problem.

A `detail` is meant to be read by a developer, not parsed: rely on `status` and on structured members.

## Status codes

| Status | When | `detail` | Headers or members |
| --- | --- | --- | --- |
| 400 Bad Request | Malformed JSON body | `The request body is not valid JSON.` | |
| 400 Bad Request | Required query parameter missing | `The query parameter "page" is required.` | |
| 400 Bad Request | Query parameter of the wrong type | `The query parameter "page" must be of type int.` or `... must be of type one of "red", "amber".` | |
| 400 Bad Request | Array given for a single-valued query parameter | `The query parameter "page" must be a single value.` | |
| 401 Unauthorized | No accepted credentials, or rejected credentials | see [401 and 403](#401-and-403) | `WWW-Authenticate` |
| 403 Forbidden | The consumer lacks a required scope | `This endpoint requires the scope "orders:write".` | |
| 404 Not Found | No route under the API prefix, or a path parameter not matching its type (`/orders/abc` for an `int` id, an unknown enum value) | none | |
| 404 Not Found | The handler threw `NotFoundHttpException` | its message | |
| 405 Method Not Allowed | The path exists with other methods | none | `Allow: DELETE, GET` |
| 410 Gone | Deprecated endpoint past its sunset date, with `tollbooth.changes.gone_after_sunset: true` | `This endpoint was retired on 2027-03-01.` | Deprecation headers |
| 415 Unsupported Media Type | A body endpoint received a `Content-Type` not containing `json` | `The request body must be JSON, "text/plain" given.` | |
| 422 Unprocessable Content | The body failed validation, or a field has the wrong type | none | `violations` |
| 429 Too Many Requests | A rate limit is exhausted | `Rate limit "burst" reached: 100 requests per 10 seconds.` | `Retry-After`, `limiters` |
| 403 Forbidden | Symfony Security's `AccessDeniedException`, for instance from `#[IsGranted]` | its message | |
| 401 Unauthorized | Symfony Security's `AuthenticationException` thrown by the handler, with `WWW-Authenticate: Session` | its message | |
| 422 Unprocessable Content | A `ValidationFailedException` thrown by the handler | none, `violations` listed | |
| 500 Internal Server Error | Any other exception | none, unless `kernel.debug` | |
| any | The handler threw a Symfony HTTP exception (`ConflictHttpException`…) | its message | its headers |

Examples, exactly as sent:

```json
{"type":"about:blank","title":"Method Not Allowed","status":405}
```

```json
{"type":"about:blank","title":"Bad Request","status":400,"detail":"The query parameter \"color\" must be of type one of \"red\", \"amber\"."}
```

```json
{"type":"about:blank","title":"Internal Server Error","status":500}
```

A 500 never exposes the exception message outside debug mode.

### Order of checks

A request to an endpoint goes through these steps; the first failing one decides the response:

1. routing: 404 or 405;
2. retirement after the sunset date, when enabled: 410;
3. authentication: 401;
4. scopes: 403;
5. rate limits: 429;
6. query and body hydration: 400, 415, 422;
7. the handler: its own errors, or 500.

A call refused at steps 1 to 4 is not counted by rate limiting and carries no rate limit headers.

### 401 and 403

A call without credentials accepted by the endpoint answers 401 with a `WWW-Authenticate` challenge per accepted method that has one, in the order the endpoint lists them:

| Accepted method | Challenge |
| --- | --- |
| `api_key` | `ApiKey header="X-Api-Key"` (the header named by `tollbooth.auth.api_key_header`) |
| `jwt` | `Bearer` |
| `user`, `anonymous` | none |

```
HTTP/1.1 401 Unauthorized
Content-Type: application/problem+json
WWW-Authenticate: ApiKey header="X-Api-Key", Bearer

{"type":"about:blank","title":"Unauthorized","status":401,"detail":"Authentication is required to access this endpoint."}
```

When no accepted method has a challenge (an endpoint accepting only `user`), the challenge is `Session`.

| `detail` | Cause |
| --- | --- |
| `Authentication is required to access this endpoint.` | No credentials of an accepted method. |
| `The API key is not valid.` | Malformed key, unknown id or wrong secret. |
| `The API key has expired.` | The key is past its expiry date. |
| `The API key is not allowed from this address.` | The client IP is outside the key's allowed addresses. |

When the API key header is present and the endpoint accepts `api_key`, the key is checked first: an invalid key answers 401 even if the endpoint also accepts `anonymous` or another method.

A consumer authenticated with `api_key` or `jwt` but lacking scopes answers 403, naming every missing scope:

```json
{"type":"about:blank","title":"Forbidden","status":403,"detail":"This endpoint requires the scopes \"billing:read\", \"billing:export\"."}
```

Responses that the Symfony firewall produces itself, before Tollbooth runs (for instance when an `access_token` authenticator rejects a token), are Symfony's and follow the firewall's configuration. See [authentication.md](authentication.md).

### 422 Validation Failed

```json
{
    "type": "about:blank",
    "title": "Validation Failed",
    "status": 422,
    "violations": [
        {"field": "customer_id", "message": "This value should not be blank."},
        {"field": "items[0].quantity", "message": "This value should be positive."},
        {"field": "coupon", "message": "This value should be of type string."}
    ]
}
```

- One entry per violation, in the order the validator reports them.
- `field` is the property path in the group's field case: dots for nested objects, `[n]` for list items. A constraint on the whole body has `""`.
- A value of the wrong JSON type, or a required field that is missing, gives `This value should be of type <type>.`
- There is no `detail`.

### 429 Too Many Requests

```
HTTP/1.1 429 Too Many Requests
Content-Type: application/problem+json
Retry-After: 4
RateLimit-Policy: "burst";q=100;w=10, "minute";q=600;w=60
RateLimit: "burst";r=0;t=4, "minute";r=581;t=34
```

```json
{
    "type": "about:blank",
    "title": "Too Many Requests",
    "status": 429,
    "detail": "Rate limit \"burst\" reached: 100 requests per 10 seconds.",
    "limiters": [
        {"name": "burst", "limit": 100, "remaining": 0, "reset": 4},
        {"name": "minute", "limit": 600, "remaining": 581, "reset": 34}
    ]
}
```

- `Retry-After` is in seconds: the longest wait among the exhausted limits.
- `detail` names the first exhausted limit.
- `limiters` lists every limit applied to the call, exhausted or not, in the same order as the headers: `name`, `limit` (the quota), `remaining`, `reset` (seconds until the window resets). Its member names are fixed and do not follow the field case.
- A refused call is not counted against the limits that still had room.

## Rate limit headers

Every response of an endpoint where at least one limit applies to the caller carries both headers, successes and errors from step 5 on alike (see [Order of checks](#order-of-checks)):

```
RateLimit-Policy: "burst";q=100;w=10, "minute";q=600;w=60, "search_products.minute";q=30;w=60
RateLimit: "burst";r=97;t=4, "minute";r=581;t=34, "search_products.minute";r=29;t=34
```

| Header | Item | Parameters |
| --- | --- | --- |
| `RateLimit-Policy` | `"<name>";q=<quota>;w=<window>` | `q`: requests allowed per window; `w`: window length in seconds |
| `RateLimit` | `"<name>";r=<remaining>;t=<reset>` | `r`: requests left in the current window; `t`: seconds until it resets |

These follow the IETF `draft-ietf-httpapi-ratelimit-headers` format. Items are separated by `, ` and appear in this order: the consumer's plan limits, the group's limits (named `<group>.<name>`), then the endpoint's own limits (named `<endpoint>.<name>`). Windows are aligned on the clock, so `t` is exact: a minute window resets at the top of the minute, a day window at 00:00 UTC.

With `tollbooth.rate_limit.legacy_headers: true`, responses also carry the tightest limit (fewest requests remaining, then the longest wait):

```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 97
X-RateLimit-Reset: 4
```

`X-RateLimit-Reset` is a number of seconds, not a timestamp. These headers are off by default. Plans and limits are described in [rate-limiting.md](rate-limiting.md).

## Deprecation headers

Every response of an endpoint declared with `#[DeprecatedEndpoint]`, successes and errors alike, carries:

```
Deprecation: @1780272000
Sunset: Mon, 01 Mar 2027 00:00:00 GMT
Link: <https://docs.example.com/migrate-to-v2>; rel="deprecation"
```

| Header | Standard | Value | Present |
| --- | --- | --- | --- |
| `Deprecation` | RFC 9745 | `@` followed by the Unix timestamp of the `since` date at 00:00 UTC | always |
| `Sunset` | RFC 8594 | HTTP date of the `sunset` date at 00:00 UTC | when a sunset is declared |
| `Link` | RFC 8288, relation from RFC 9745 | `<url>; rel="deprecation"` | when a link is declared; added next to other `Link` values |

After the sunset date, the endpoint keeps answering normally unless `tollbooth.changes.gone_after_sunset` is `true`; it then answers `410 Gone` with the deprecation headers:

```json
{"type":"about:blank","title":"Gone","status":410,"detail":"This endpoint was retired on 2027-03-01."}
```

See [contract-changes.md](contract-changes.md) and [guides/deprecating-an-api-version.md](guides/deprecating-an-api-version.md).

## Content types

| `Content-Type` | Used for |
| --- | --- |
| `application/json` | Successful responses of `plain` and `data` groups |
| `application/vnd.api+json` | Successful responses of `json_api` groups |
| `application/problem+json` | Every error, whatever the group's envelope |

Request bodies must be JSON: a `Content-Type` containing `json` (`application/json`, `application/vnd.api+json`, `application/merge-patch+json`…), or none. Anything else answers 415.

## Authentication headers

| Header | Meaning |
| --- | --- |
| `X-Api-Key: tb_live_<id>_<secret>` | API key. The header name is set by `tollbooth.auth.api_key_header`. Keys start with `tb_live_` or `tb_test_`, followed by a 12 character id, `_` and a 32 character secret. |
| `Authorization: Bearer <token>` | JWT, checked by the application's Symfony firewall. |
| session cookie or other credentials | Symfony users, checked by the application's firewall. |

Which endpoints accept which method is described in [endpoints.md](endpoints.md#authentication-and-scopes) and [authentication.md](authentication.md).
