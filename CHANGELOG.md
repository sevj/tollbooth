# Changelog

All notable changes to the **tollbooth** bundle are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). See the *Versioning* section
of `README.md` for the definition of the public API.

## [Unreleased]

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
