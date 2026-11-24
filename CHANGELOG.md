# Changelog

All notable changes to the **tollbooth** bundle are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). See the *Versioning* section
of `README.md` for the definition of the public API.

## [Unreleased]

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
