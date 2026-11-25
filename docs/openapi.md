# OpenAPI documentation

The Docs module writes one OpenAPI 3.1 document per endpoint group, built from the same compiled metadata that routes the calls (attributes, PHP types, docblocks, validation constraints, group conventions, authentication, rate limits and deprecations). The document cannot drift from the code: there is nothing to annotate by hand. This page lists the routes and the command that serve the documents, then describes exactly how each part of a document is derived, so you can predict what your endpoints will look like.

- [Configuration](#configuration)
- [Routes](#routes)
- [The documentation page](#the-documentation-page)
- [Exporting a document](#exporting-a-document)
- [What a document contains](#what-a-document-contains)
- [How schemas are derived](#how-schemas-are-derived)
- [Errors](#errors)
- [Security](#security)
- [Rate limits](#rate-limits)
- [Deprecation](#deprecation)
- [Caching](#caching)
- [Known limits](#known-limits)

## Configuration

```yaml
# config/packages/tollbooth.yaml
tollbooth:
    docs:
        enabled: true          # default; false registers no Docs service and no route
        path: /_tollbooth      # base path of the documentation routes
        title: Shop API        # info.title (non-default groups get " · <group>" appended)
        version: 2.4.0         # info.version, also the default version of tollbooth:contract:snapshot
        ui: true               # false serves only the JSON documents, the HTML page answers 404
```

| Key | Default | Effect |
| --- | --- | --- |
| `tollbooth.docs.enabled` | `true` | Turns the module on. The [Changes module](contract-changes.md) and the dashboard's Models screen and request/response panels need it. |
| `tollbooth.docs.path` | `/_tollbooth` | Base of `<path>/docs/{group}` and `<path>/openapi/{group}.json`. The health report (`<path>/health.json`) also lives under it. |
| `tollbooth.docs.title` | `API` | `info.title`. For a group other than `default`, the title is `<title> · <group>`. |
| `tollbooth.docs.version` | `1.0.0` | `info.version`. |
| `tollbooth.docs.ui` | `true` | Serves the HTML page. When `false`, `<path>/docs/{group}` answers 404 and the JSON documents stay available. |

Other settings that change the documents: `tollbooth.path_prefix` (the `servers` URL), the group settings (`prefix`, `output`, `auth`, `scopes`, `rate_limits`, see [configuration.md](configuration.md)), `tollbooth.auth.api_key_header` (the `ApiKey` security scheme) and whether rate limiting is active (see [rate-limiting.md](rate-limiting.md)).

## Routes

The routes are added by the `tollbooth` route loader, the same import that registers the endpoints (see [getting-started.md](getting-started.md)). They are part of the public API.

| Route name | Path | Method | Answers |
| --- | --- | --- | --- |
| `tollbooth_docs` | `<docs.path>/docs/{group}` | `GET` | The HTML documentation page of the group. `{group}` defaults to `default`, so `<docs.path>/docs` opens the default group. |
| `tollbooth_docs_openapi` | `<docs.path>/openapi/{group}.json` | `GET` | The OpenAPI document of the group, `application/json`, `Cache-Control: no-store`. |

`{group}` must match `[A-Za-z0-9_.-]+`. A group with no endpoint answers 404, so does the page when `ui` is `false`. With the defaults:

```
GET /_tollbooth/docs              → page of the "default" group
GET /_tollbooth/docs/partner      → page of the "partner" group
GET /_tollbooth/openapi/partner.json
```

Tollbooth does not protect these routes. Put them behind the application firewall, or leave them open when the documentation is public; the health report adds a finding when `<docs.path>/docs` is outside any firewall (see [health.md](health.md) and [dashboard.md](dashboard.md#securing-the-dashboard)). Example of a public documentation next to a protected dashboard:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        public_docs:
            pattern: ^/_tollbooth/(docs|openapi)(/|$)
            security: false
        # ... the firewalls of the application, the one covering /_tollbooth last
```

## The documentation page

The page is a single HTML document rendered by the controller, with:

- a navigation bar with one link per group (the `default` group first, then the others in alphabetical order), the current group highlighted;
- [Scalar API Reference](https://github.com/scalar/scalar) `@scalar/api-reference` **1.77.0**, loaded from `https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.77.0`, pointed at `<docs.path>/openapi/{group}.json`, dark mode, download button shown.

The browser needs access to jsDelivr. If it has none (air-gapped network, strict Content Security Policy), set `ui: false` and serve the JSON document with a viewer of your own, or export it with the command below.

## Exporting a document

```
bin/console tollbooth:openapi:export [<group>] [--output=FILE] [--yaml]
```

| Argument / option | Default | Meaning |
| --- | --- | --- |
| `group` | `default` | Group to export. |
| `--output=FILE` | none | Write to this file (directories are created) instead of printing to the standard output. |
| `--yaml` | off | Write YAML instead of pretty-printed JSON. |

The command exits with `0` on success and `1` when no endpoint belongs to the group (the error lists the existing groups).

```
bin/console tollbooth:openapi:export partner --yaml --output=public/openapi.yaml
bin/console tollbooth:openapi:export > openapi.json
```

## What a document contains

| Part | Content |
| --- | --- |
| `openapi` | `3.1.0` |
| `info` | `title` and `version` from `tollbooth.docs`. |
| `servers` | One server whose URL is `tollbooth.path_prefix` without its trailing slash, or `/` when there is none. |
| `paths` | One entry per endpoint path, sorted. The path is the group `prefix` followed by the declared path; `path_prefix` is in `servers`, not in the paths. With `path_prefix: /api` and a group `prefix: /v2`, `#[Endpoint('GET', '/products/{id}')]` appears as `/v2/products/{id}` under the server `/api`. |
| `components.schemas` | Every model reached from an argument or a return type, plus `Problem`, `ValidationProblem` and `RateLimitProblem` when an operation can answer them. |
| `components.responses` | The error responses used by the operations (`BadRequest`, `Unauthorized`, …). |
| `components.headers` | `RateLimit-Policy` and `RateLimit`, when the group is rate limited. |
| `components.securitySchemes` | The schemes used by the operations (`ApiKey`, `Bearer`, `Session`). |

Each operation has:

| Field | Value |
| --- | --- |
| `operationId` | The route name: `tollbooth_<name>` in the default group, `tollbooth_<group>_<name>` elsewhere. The name is the `name` of `#[Endpoint]`, or the class short name in snake_case (`GetOrder` → `get_order`). |
| `summary` | The class short name split into words: `GetOrder` → `Get order`, `SearchProducts` → `Search products`. |
| `tags` | One tag: the first segment of the path once the group prefix is removed (`/v2/orders/{id}` in a group with prefix `/v2` → `orders`). When that segment is empty or a placeholder, the group name. The dashboard calls this the resource. |
| `parameters` | Path parameters, then query parameters (see below). |
| `requestBody` | When an argument has `#[Body]`. |
| `responses` | The success response, then the errors the endpoint can answer. |
| `security` | From the accepted authentication methods and the required scopes. |
| `deprecated`, `x-deprecated-since`, `x-sunset`, `externalDocs` | From `#[DeprecatedEndpoint]`. |
| `x-rate-limits` | The limits counted for this endpoint: the group's `rate_limits` and the endpoint's `#[RateLimit]`. |

## How schemas are derived

Types are read with Symfony TypeInfo from the `__invoke()` signature and from the **public, non-static properties** of the classes it reaches, docblocks included. Field names follow the group's `output.case` (`preserve`, `snake_case` or `camel_case`), on input and output models alike. Query parameter names are not converted.

### Parameters

| Source | Becomes |
| --- | --- |
| `__invoke()` argument named like a `{placeholder}` of the path | A path parameter, `required: true`, typed from the argument. A placeholder with no matching argument is a `string` path parameter. |
| Argument with `#[Query]` | A query parameter named after `#[Query(name: '…')]` or the argument. Required unless the argument has a default value or is nullable. A scalar or backed-enum default becomes `default`. The schema is the type without its nullability. |
| Argument with `#[Body]` | `requestBody` with an `application/json` schema of the argument's type, read as an input model. `required` is `true` unless the argument is nullable. |
| Any other argument (`Consumer`, `Request`, services…) | Nothing. |

```php
#[Endpoint('GET', '/orders/{id}/lines')]
final readonly class ListOrderLines
{
    /**
     * @return list<OrderLineView>
     */
    public function __invoke(int $id, #[Query] int $page = 1, #[Query('sort')] ?LineSort $order = null): array
    {
        // ...
    }
}
```

gives a required integer path parameter `id`, an optional integer query parameter `page` with `default: 1`, and an optional query parameter `sort` described by the `LineSort` enum (a nullable query parameter is documented as optional; its schema does not add `null`).

### Types

| PHP type | Schema |
| --- | --- |
| `int` | `{"type": "integer"}` |
| `float` | `{"type": "number"}` |
| `string` | `{"type": "string"}` |
| `bool`, `true`, `false` | `{"type": "boolean"}` |
| `array` or `iterable` without a docblock | `{"type": "array", "items": {}}` |
| `list<T>`, `array<int, T>`, `T[]` | `{"type": "array", "items": <T>}` |
| `array<string, T>` | `{"type": "object", "additionalProperties": <T>}` |
| `\DateTimeInterface` and its implementations | `{"type": "string", "format": "date-time"}` |
| Backed enum | Inline `{"title": "<ShortName>", "type": "string" or "integer", "enum": [<case values>]}` (the type follows the first case value) |
| Pure (non-backed) enum | `{"type": "string"}` |
| Class or interface | A `$ref` to a component schema named after the class short name (see [Component names](#component-names)) |
| `A\|B` | `{"anyOf": [<A>, <B>]}` |
| `?T` or `T\|null` | `{"type": [<type>, "null"]}` for a simple type, with `null` added to `enum` for an enum; `{"anyOf": [<T>, {"type": "null"}]}` for a `$ref` |
| `mixed` or an unknown type | `{}` (no constraint) |

Docblock types win over native types when they are more precise: `/** @var list<OrderLineView> */ public array $lines;` or `/** @return list<OrderView> */` on `__invoke()`.

### Object schemas

For each class, every public non-static property becomes a property of the schema:

- **defaults**: a default value declared on the property, or on the promoted constructor parameter, becomes `default` when it is a scalar, a backed enum (its value) or a list of scalars;
- **required, input models** (the `#[Body]` type and what it reaches): properties with no default value and a non-nullable type;
- **required, output models** (return types): every property whose type is not nullable, defaults or not.

```php
final readonly class CreateOrderInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $reference,
        #[Assert\Positive]
        public int $quantity = 1,
        public ?string $note = null,
        public Currency $currency = Currency::Eur,
    ) {
    }
}
```

With `output.case: snake_case`, the component is:

```json
{
    "type": "object",
    "properties": {
        "reference": { "type": "string", "minLength": 1, "maxLength": 64 },
        "quantity": { "type": "integer", "exclusiveMinimum": 0, "default": 1 },
        "note": { "type": ["string", "null"] },
        "currency": { "title": "Currency", "type": "string", "enum": ["EUR", "USD"], "default": "EUR" }
    },
    "required": ["reference"]
}
```

### Validation constraints

Constraint attributes on properties (including promoted constructor parameters) add keywords to the property schema. Others are ignored by the document but still validated at runtime.

| Constraint | Keyword |
| --- | --- |
| `NotBlank` | `minLength: 1` |
| `Length(min, max)` | `minLength`, `maxLength` |
| `Count(min, max)` | `minItems`, `maxItems` |
| `Range(min, max)` | `minimum`, `maximum` (numeric bounds only) |
| `Positive` | `exclusiveMinimum: 0` |
| `PositiveOrZero` | `minimum: 0` |
| `Negative` | `exclusiveMaximum: 0` |
| `NegativeOrZero` | `maximum: 0` |
| `Choice(choices: [...])` | `enum` |
| `Email` | `format: email` |
| `Url` | `format: uri` |
| `Uuid` | `format: uuid` |

Constraints are read from model properties only, not from `#[Query]` arguments.

### Component names

A component is named after the class short name. When two different schemas would take the same name (an input and an output read from the same class, two classes with the same short name in different namespaces), the later one gets `Input` or `Output` appended, then a number (`OrderView2`). The JSON:API attributes of a resource are a separate component, `<ShortName>Attributes`.

### Success responses

| Return type of `__invoke()` | Success response |
| --- | --- |
| `void`, `never` or `null` | `204` with `description: No content.` when the declared status is `200`, otherwise the declared status, without content |
| `BinaryFileResponse` or `StreamedResponse` (or a subclass) | The declared status with an `application/octet-stream` body, schema `{"type": "string", "format": "binary"}` |
| `JsonResponse` (or a subclass) | The declared status with an `application/json` body and an empty (free-form) schema |
| any other `Response` | The declared status, without content |
| anything else | The declared status (`#[Endpoint(status: …)]`, `200` by default), `description: Success.`, with a schema wrapped in the group's envelope |

When `tollbooth.output.etag` is on (the default), a `GET` operation whose declared status is `200` and which does not return a `Response` also lists the `ETag` response header and a `304` response (`Not modified`). Response classes never become schema components or dashboard models.

The content type and the envelope follow the group's `output.envelope` (see [http-contract.md](http-contract.md)):

| Envelope | Content type | Schema |
| --- | --- | --- |
| `plain` | `application/json` | The return type's schema as is |
| `data` | `application/json` | `{"type": "object", "required": ["data"], "properties": {"data": <schema>}}` |
| `json_api` | `application/vnd.api+json` | A resource document when the return type is a class with an `id` property (`data` with `type` as a `const` equal to the type Tollbooth sends, `id` as a string, `attributes` as `<ShortName>Attributes` without `id`), a collection when it is a `list` of such classes, otherwise `{"meta": <schema>}` |

## Errors

Each operation lists only the errors it can answer, all `application/problem+json`, as `$ref`s to `components.responses`:

| Status | Listed when | Response | Schema |
| --- | --- | --- | --- |
| 400 | The endpoint reads a body or has a `#[Query]` argument | `BadRequest` | `Problem` |
| 401 | The endpoint does not accept `anonymous` | `Unauthorized` | `Problem` |
| 403 | The endpoint requires scopes | `Forbidden` | `Problem` |
| 404 | The path has a placeholder | `NotFound` | `Problem` |
| 415 | The endpoint reads a body | `UnsupportedMediaType` | `Problem` |
| 422 | The endpoint reads a body | `ValidationFailed` | `ValidationProblem` (adds `violations[]` with `field` and `message`) |
| 429 | Rate limiting is active (plans declared or stored) or the endpoint has its own limits | `TooManyRequests` (with a `Retry-After` header) | `RateLimitProblem` (adds `limiters[]` with `name`, `limit`, `remaining`, `reset`) |

`Problem` has `type`, `title`, `status` (required) and `detail`. Errors your own code throws (a `ConflictHttpException`, a 500) are not listed. The full problem shape is in [http-contract.md](http-contract.md).

## Security

| Accepted method | Scheme | Definition | Scopes in the requirement |
| --- | --- | --- | --- |
| `api_key` | `ApiKey` | `{"type": "apiKey", "in": "header", "name": <auth.api_key_header>}` | The endpoint's required scopes |
| `jwt` | `Bearer` | `{"type": "http", "scheme": "bearer", "bearerFormat": "JWT"}` | The endpoint's required scopes |
| `user` | `Session` | `{"type": "apiKey", "in": "cookie", "name": <session.name ini value, PHPSESSID by default>}` | none (scopes do not apply to `user` consumers) |
| `anonymous` | none | An empty requirement `{}`, which makes the credentials optional | none |

Each accepted method is one alternative of the operation's `security` list. A `#[PublicEndpoint]` (or an endpoint that only accepts `anonymous`) has no `security` field. The method resolution itself is described in [authentication.md](authentication.md).

```json
"security": [
    { "ApiKey": ["orders:read"] },
    { "Session": [] }
]
```

## Rate limits

When rate limiting is active for the application (`tollbooth.rate_limit` enabled with plans in YAML or `doctrine_plans`), or when the endpoint has limits of its own:

- the success response declares the `RateLimit-Policy` and `RateLimit` headers (`$ref` to `components.headers`);
- the operation lists the 429 response;
- `x-rate-limits` lists the limits counted for this endpoint, each as `{"name", "limit", "window"}` (window in seconds): the group's `rate_limits` (named `<group>.<name>`) and the endpoint's `#[RateLimit]` (named `<endpoint>.<name>`).

The plan limits of each consumer are not in the document: they depend on the consumer, not on the endpoint. See [rate-limiting.md](rate-limiting.md).

```json
"x-rate-limits": [
    { "name": "partner.hour", "limit": 10000, "window": 3600 },
    { "name": "search_products.minute", "limit": 30, "window": 60 }
]
```

## Deprecation

`#[DeprecatedEndpoint(since: '2026-06-01', sunset: '2027-03-01', link: 'https://docs.example.com/migrate')]` adds:

```json
"deprecated": true,
"x-deprecated-since": "2026-06-01",
"x-sunset": "2027-03-01",
"externalDocs": { "description": "Migration guide", "url": "https://docs.example.com/migrate" }
```

`x-sunset` and `externalDocs` only appear when `sunset` and `link` are set. The HTTP headers sent at runtime are described in [contract-changes.md](contract-changes.md#deprecating-an-endpoint).

## Caching

- A document is built at most once per group in each PHP process (memoized in the generator).
- Outside debug mode (`kernel.debug: false`), it is also stored in the `cache.system` pool under `tollbooth_openapi_<hash of the group>`. `cache:clear` empties it, which every deployment already does when the code changes. In debug mode, nothing is stored and each process builds the document again.
- The HTTP response carries `Cache-Control: no-store`; put an HTTP cache in front of it yourself if needed.

Building a document uses reflection; it never runs on the request path of your endpoints, only when a document, the dashboard or a Changes command asks for it.

## Known limits

- **Endpoints that return a Symfony `Response`** are documented by their class only (binary, free-form JSON or no content, see [Success responses](#success-responses)): the shape of a JSON body they build by hand is not described. There is no way to exclude one endpoint or to write its schema by hand. See [guides/migrating-symfony-controllers.md](guides/migrating-symfony-controllers.md).
- Response headers other than the rate limit headers and `ETag` (`Deprecation`, `Sunset`…) are not described.
- Query parameters carry no validation keywords; only model properties do.
- Errors your handler throws on purpose (409, 410, 500…) are not listed.
- `404` is listed for every endpoint with a path placeholder, whether or not the handler can actually answer it.
- A nullable return type is documented as the declared status, although a `null` result answers `204` at runtime.
