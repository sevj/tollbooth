# Endpoints

An endpoint is one invokable PHP class carrying the `#[Endpoint]` attribute. Tollbooth reads its attributes and the signature of its `__invoke()` method when the container is built, and from them routes the request, authenticates the caller, applies rate limits, hydrates and validates the arguments, serializes the return value with the output conventions of the endpoint's group, and documents all of it in OpenAPI. This page is the reference for declaring endpoints: every attribute and parameter, what `__invoke()` can take and return, envelopes, field name cases, groups, route names, and the errors raised for a wrong declaration. What clients receive is described in [http-contract.md](http-contract.md); configuration keys in [configuration.md](configuration.md).

## Anatomy of an endpoint

```php
<?php

declare(strict_types=1);

namespace App\Api\Shop;

use App\Api\Model\OrderView;
use App\Shop\Orders;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\Query;

#[Endpoint('GET', '/orders/{id}')]
final readonly class GetOrder
{
    public function __construct(private Orders $orders)
    {
    }

    public function __invoke(int $id, #[Query] bool $withLines = false): OrderView
    {
        return OrderView::from($this->orders->get($id), $withLines);
    }
}
```

Rules:

- **One class per endpoint.** The class must have a public `__invoke()` method. Other public methods are not routed.
- **The class must be an autoconfigured service.** Tollbooth registers endpoints through Symfony autoconfiguration of the `#[Endpoint]` attribute. The default `config/services.yaml` of a Flex application (`App\` loaded from `src/` with `autoconfigure: true`) is enough. A class registered without autoconfiguration is silently ignored: no route is created.
- **Declare it `final`**, and `readonly` when its dependencies allow it. Business logic belongs in services injected through the constructor; the endpoint maps input to a call and the result to a view.
- **Reflection happens only at build time.** Changing an attribute requires the container to be rebuilt (automatic in debug mode, `bin/console cache:clear` otherwise).

## `#[Endpoint]`

`Tollbooth\Core\Attribute\Endpoint`, target: class, not repeatable. Required on every endpoint.

```php
#[Endpoint(method: 'POST', path: '/orders', status: 201, name: 'place_order', group: 'shop')]
```

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `method` | `string` | required | HTTP method. One of `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`, case-insensitive (`'get'` is stored as `GET`). As with any Symfony route, a `GET` endpoint also answers `HEAD`. |
| `path` | `string` | required | Path relative to the API, with `{name}` placeholders. Leading and trailing slashes are normalized: `'orders'`, `'/orders'` and `'/orders/'` are the same path. Do not repeat `tollbooth.path_prefix` or the group prefix: both are added (see [Final path](#final-path)). |
| `status` | `int` | `200` | Status code of a successful response. Must be between 100 and 599. When `__invoke()` returns `null` (or is `void`), a `200` becomes `204 No Content`; any other declared status is kept, with an empty body. |
| `name` | `?string` | `null` | Endpoint name, used in the route name, the dashboard URLs, rate limit names and monitoring. `null` derives it from the short class name in snake_case: `GetOrderLines` gives `get_order_lines`, `ImportHTTPLogs` gives `import_http_logs`. Must be unique inside its group. |
| `group` | `?string` | `null` | Group the endpoint belongs to, overriding namespace and path matching. `'default'` puts it in the default group even when a configured group would match it. An undeclared group name fails the build. See [Groups](#groups). |

### Final path

The route path is built from three parts:

```
tollbooth.path_prefix + group prefix + #[Endpoint] path
```

With `path_prefix: /api` and a group `partner` with `prefix: /partner`, `#[Endpoint('GET', '/orders/{id}')]` in that group answers `/api/partner/orders/{id}`. In the default group, it answers `/api/orders/{id}`.

The group's `path` setting (used to match endpoints to a group) is not removed from the path: an endpoint declared `/admin/users` in a group matched by `path: /admin` answers `<path_prefix><group prefix>/admin/users`.

Routes are registered sorted by final path, then by method, not in declaration order. Because `{` sorts after letters and digits, a static segment such as `/products/search` is matched before `/products/{id}`.

### Path parameters

A placeholder `{name}` is bound to the `__invoke()` argument with the same name. The argument's type adds a route requirement:

| Argument type | Requirement | Value received |
| --- | --- | --- |
| `int` | `\d+` | the integer (a non-numeric or negative value does not match the route: 404) |
| a backed enum | its case values, alternated (`red\|amber`) | the enum case (any other value does not match the route: 404) |
| `string` | none (`[^/]+`, Symfony's default) | the raw segment |

```php
#[Endpoint('GET', '/orders/{id}/lines/{status}')]
final readonly class ListOrderLines
{
    /**
     * @return list<OrderLineView>
     */
    public function __invoke(int $id, LineStatus $status): array
    {
        // ...
    }
}
```

Other types get no requirement. There is no way to declare a custom requirement (a UUID pattern, for instance): type the argument `string`, check it in the handler and throw `NotFoundHttpException` when it does not match. An argument carrying `#[Body]` or `#[Query]` never adds a requirement, even when its name matches a placeholder.

## Arguments of `__invoke()`

| Argument | How it is resolved |
| --- | --- |
| Named like a path placeholder | The path segment, see [Path parameters](#path-parameters). |
| `#[Query]` | One query string parameter, cast to the argument type. See [`#[Query]`](#query). |
| `#[Body]` | The JSON request body, deserialized into the class and validated. See [`#[Body]`](#body). |
| `Tollbooth\Auth\Consumer` | The authenticated caller: `id`, `name`, `method` (`Tollbooth\Core\Security\AuthMethod`), `scopes`, `plan`. On an endpoint that accepts `anonymous`, an unauthenticated caller is `ip:<client address>` with method `anonymous`. See [authentication.md](authentication.md). |
| `Symfony\Component\HttpFoundation\Request` | The current request, through Symfony's own resolver. |
| Anything else Symfony's argument resolvers support | Resolved by Symfony as for any controller. |

A `Consumer` argument is only available inside an endpoint; asking for it elsewhere throws a `LogicException` unless the argument is nullable.

### Services

Inject services through the constructor. The endpoint is an ordinary autowired service:

```php
#[Endpoint('POST', '/orders/{id}/cancel')]
final readonly class CancelOrder
{
    public function __construct(
        private Orders $orders,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id): OrderView
    {
        return OrderView::from($this->orders->cancel($id, $this->clock->now()));
    }
}
```

## `#[Query]`

`Tollbooth\Core\Attribute\Query`, target: parameter. Reads one query string parameter into a typed argument.

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `name` | `?string` | `null` | Name of the query parameter. `null` uses the argument name, verbatim. Query parameter names are never converted by the group's field case. |

```php
#[Endpoint('GET', '/products')]
final readonly class ListProducts
{
    /**
     * @return list<ProductView>
     */
    public function __invoke(
        #[Query] ?Category $category = null,
        #[Query] int $page = 1,
        #[Query('per-page')] int $perPage = 20,
        #[Query] array $tag = [],
    ): array {
        // ...
    }
}
```

Supported types and casting:

| Argument type | Accepted values | On an invalid value |
| --- | --- | --- |
| `string`, `mixed` or untyped | any | — |
| `int` | an integer (`42`, `-3`) | 400 `The query parameter "page" must be of type int.` |
| `float` | a number (`1.5`) | 400 `... must be of type float.` |
| `bool` | `1`, `0`, `true`, `false`, `on`, `off`, `yes`, `no` (PHP `FILTER_VALIDATE_BOOL`) | 400 `... must be of type bool.` |
| a backed enum | a case value; an int-backed enum parses the value as an integer first | 400 `The query parameter "color" must be of type one of "red", "amber".` |
| `array` | `?tag=a` gives `['a']`, `?tag[]=a&tag[]=b` gives `['a', 'b']` | — |

Missing values:

- A parameter that is absent, or present with an empty value (`?page=`), takes the argument's default value.
- Without a default, a nullable argument receives `null`.
- Otherwise the call answers 400 `The query parameter "page" is required.`

A non-array argument given an array (`?page[]=1`) answers 400 `The query parameter "page" must be a single value.` Any other argument type (a class, a union type) throws a `LogicException` when the endpoint is called, which answers 500: it is not detected at build time.

## `#[Body]`

`Tollbooth\Core\Attribute\Body`, target: parameter. Deserializes the JSON request body into the argument's class with Symfony Serializer, then validates it with Symfony Validator.

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `groups` | `?list<string>` | `null` | Validation groups. `null` validates the `Default` group. |

```php
<?php

declare(strict_types=1);

namespace App\Api\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateOrderInput
{
    /**
     * @param list<OrderItemInput> $items
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $customerId = '',
        #[Assert\Count(min: 1, max: 50)]
        #[Assert\Valid]
        public array $items = [],
        #[Assert\Valid]
        public AddressInput $shippingAddress = new AddressInput(),
        #[Assert\Regex('/^[A-Z0-9]{6,12}$/')]
        public ?string $coupon = null,
    ) {
    }
}
```

```php
#[Endpoint('POST', '/orders', status: 201)]
final readonly class CreateOrder
{
    public function __construct(private Orders $orders)
    {
    }

    public function __invoke(#[Body] CreateOrderInput $input): OrderView
    {
        return OrderView::from($this->orders->place($input));
    }
}
```

```php
#[Endpoint('PATCH', '/coupons/{code}')]
final readonly class UpdateCoupon
{
    public function __invoke(string $code, #[Body(groups: ['update'])] CouponInput $input): CouponView
    {
        // ...
    }
}
```

Behaviour, in order:

1. The argument must be typed with a class. Another type (`array`, a scalar) throws a `LogicException` when the endpoint is called (500).
2. An empty body with a nullable argument (`#[Body] ?PatchInput $input = null`) gives `null`, without validation.
3. A `Content-Type` header that does not contain `json` answers 415 `The request body must be JSON, "text/plain" given.` A missing `Content-Type` is accepted, and so is `application/vnd.api+json` or any `+json` type.
4. An empty body with a non-nullable argument is read as `{}`.
5. Malformed JSON answers 400 `The request body is not valid JSON.`
6. Values of the wrong type, and required constructor arguments that are missing, answer 422 with one violation per field: `This value should be of type int.` JSON values are not coerced: `"2"` for an `int` property is a type error.
7. The object is validated; any violation answers 422 with the `violations` list (see [Validation errors](#validation-errors)).

Hydration details:

- Field names follow the group's [field case](#field-name-cases): with `snake_case`, the body field `customer_id` fills the property `$customerId`, and `customerId` in the body is ignored.
- Fields that the class does not declare are ignored.
- Nested objects are hydrated from their property types; lists of objects need a docblock type (`@param list<OrderItemInput> $items` on the promoted parameter, or `@var` on the property). Validation of nested objects and lists needs `#[Assert\Valid]`.
- Give every constructor parameter a default value. A missing field then gets its default and the constraints decide whether it is required (`#[Assert\NotBlank]`); without a default, a missing field answers 422 `This value should be of type ...`, which reads less clearly.
- The [envelope](#output-envelopes) applies to responses only. A request body is always the bare object, also in `data` and `json_api` groups: clients do not send `{"data": ...}` or a JSON:API document.

### Validation errors

A body that fails validation answers 422 `application/problem+json`, without `detail`, with one entry per violation:

```json
{
    "type": "about:blank",
    "title": "Validation Failed",
    "status": 422,
    "violations": [
        { "field": "customerId", "message": "This value should not be blank." },
        { "field": "items[0].quantity", "message": "This value should be positive." }
    ]
}
```

`field` is the Symfony property path with each name converted to the group's field case (`items[0].unit_price` under `snake_case`). A constraint on the class itself has an empty `field`. `message` is the validator's message, in the request locale if the application translates validator messages.

To answer the same 422 from inside a handler (a rule the DTO cannot express), throw an `UnprocessableEntityHttpException` whose previous exception is a `ValidationFailedException`:

```php
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

$violations = new ConstraintViolationList([
    new ConstraintViolation('This coupon has expired.', null, [], $input, 'coupon', $input->coupon),
]);

throw new UnprocessableEntityHttpException('', new ValidationFailedException($input, $violations));
```

Throwing a bare `ValidationFailedException` is not enough: it is not an HTTP exception, so it answers status 500 (with the violations listed).

## Return values

The return value of `__invoke()` is normalized with Symfony Serializer, wrapped in the group's envelope and encoded as JSON with the status declared on `#[Endpoint]`.

| Returned | Response |
| --- | --- |
| An object | Its public properties and getters, with field names in the group's case. Backed enums become their value, `DateTimeInterface` an RFC 3339 string (`2026-11-25T14:02:05+00:00`), nested objects nested JSON objects. |
| A list or an array | A JSON array or object. Keys of PHP arrays are written as they are: the field case converts object property names only. |
| A scalar | The JSON scalar (`"hi"`, `3`, `true`). |
| `null`, or a `void` method | No body. A declared status of `200` becomes `204 No Content`; another declared status (`201`, `202`) is sent as is, with an empty body. |
| A `Symfony\Component\HttpFoundation\Response` | Sent untouched: no serialization, no envelope, no ETag, its own status and `Content-Type`. Rate limit and deprecation headers are still added. OpenAPI documents a file or stream as a binary body and a `JsonResponse` as free-form JSON. Reserve it for binary downloads and legacy formats that must not change. |

JSON is encoded with unescaped slashes and Unicode, and floats keep their decimal part (`1.0` stays `1.0`).

Declare list return types with a docblock (`@return list<OrderView>`): the OpenAPI schema is derived from it. See [openapi.md](openapi.md).

### Custom normalizers

Tollbooth serializes with its own named serializers, one per field case, so the application's `serializer` service and its name converter are left untouched. Symfony registers an application normalizer on the default serializer only. To use one in Tollbooth responses and request bodies as well, tag it for every serializer:

```yaml
# config/services.yaml
services:
    App\Serializer\MoneyNormalizer:
        tags:
            - { name: serializer.normalizer, serializer: '*' }
```

### Errors thrown by a handler

Throw Symfony HTTP exceptions to answer an error; Tollbooth turns them into problem details:

```php
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$order = $this->orders->find($id) ?? throw new NotFoundHttpException(sprintf('Order %s does not exist.', $id));

if ($order->isShipped()) {
    throw new ConflictHttpException('A shipped order cannot be cancelled.');
}
```

- An exception implementing `HttpExceptionInterface` keeps its status code and headers, and its message becomes the `detail` member: never put internal information in it.
- Any other exception answers 500, without `detail` unless `kernel.debug` is on. This includes Symfony Security's `AccessDeniedException` (thrown by `#[IsGranted]` or `denyAccessUnlessGranted()`): to answer 403 from an endpoint, throw `Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException`.
- An exception implementing `Tollbooth\Core\Problem\ProblemMembersInterface` adds members to the problem body. See [guides/extending.md](guides/extending.md).

The exact shapes are in [http-contract.md](http-contract.md#problem-details).

## Output envelopes

`tollbooth.output.envelope`, overridable per group with `groups.<name>.output.envelope`. Errors are never wrapped.

| Envelope | Object | List | `Content-Type` |
| --- | --- | --- | --- |
| `plain` (default) | `{"id": 7, "name": "Gate"}` | `[{...}, {...}]` | `application/json` |
| `data` | `{"data": {"id": 7, "name": "Gate"}}` | `{"data": [{...}]}` | `application/json` |
| `json_api` | `{"data": {"type": "things", "id": "7", "attributes": {"name": "Gate"}}}` | `{"data": [{"type": ..., "id": ..., "attributes": {...}}]}` | `application/vnd.api+json` |

With `data`, every non-null result is wrapped, scalars included (`{"data": 3}`).

### JSON:API rules

With `json_api`, the normalized result is turned into a JSON:API document:

- **Resource**: an object whose normalized form has an `id` field holding a non-null scalar becomes `{"type", "id", "attributes"}`. `id` is converted to a string and removed from `attributes`; every other field, nested objects included, stays in `attributes`. No `relationships` or `links` are produced.
- **Collection**: a PHP list whose items are all resources becomes `{"data": [resource, ...]}`. An empty list gives `{"data": []}`.
- **Anything else** (an object without `id`, a list mixing other values, an associative array, a scalar) goes under `meta`: `{"meta": {"total": 3}}`.
- The field must be named `id` after the case conversion; a property `$uuid` or `$orderId` does not make a resource.

The `type` member is derived from the PHP class of the returned object:

1. take the short class name;
2. remove one trailing `View`, `Output`, `Resource`, `Response` or `Dto` (a class named exactly `View` keeps its name);
3. split it into words and pluralize the last one with Symfony's English inflector;
4. write it in snake_case when the group's case is `snake_case`, and in camelCase for `camel_case` and `preserve`.

| Class | `snake_case` | `camel_case` or `preserve` |
| --- | --- | --- |
| `ThingView` | `things` | `things` |
| `OrderLineView` | `order_lines` | `orderLines` |
| `Category` | `categories` | `categories` |
| `ShipmentDto` | `shipments` | `shipments` |

The OpenAPI document declares the same `type` values. Renaming a returned class changes the `type` clients receive, which is a breaking change of the HTTP contract.

## Field name cases

`tollbooth.output.case`, overridable per group with `groups.<name>.output.case`.

| Case | PHP property `unitCount` is written | PHP property `unit_count` is written |
| --- | --- | --- |
| `preserve` (default) | `unitCount` | `unit_count` |
| `snake_case` | `unit_count` | `unit_count` |
| `camel_case` | `unitCount` | `unitCount` |

The case applies, in both directions, to:

- property names of objects in response bodies;
- property names of `#[Body]` objects read from request bodies;
- the `field` of validation violations (`items[0].unit_price`).

It does not apply to keys of PHP arrays returned by a handler, query parameter names, path parameters, problem detail members such as `limiters`, or the JSON:API `type` (which has its own rule above).

## Authentication and scopes

The attributes below decide which callers an endpoint accepts. How each method authenticates, the `Consumer` it produces and API keys are described in [authentication.md](authentication.md).

The accepted methods are resolved from the most specific declaration:

1. `#[PublicEndpoint]`: `anonymous` only, no scope;
2. `#[Auth(...)]`;
3. the group's `auth`;
4. `tollbooth.auth.default` (default `[api_key]`).

### `#[Auth]`

`Tollbooth\Core\Attribute\Auth`, target: class, not repeatable.

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `...$methods` | `string` (variadic) | required | Accepted methods, any of `api_key`, `jwt`, `user`, `anonymous`. Replaces the group's and the default methods. Duplicates are removed. |

```php
#[Endpoint('GET', '/me')]
#[Auth('api_key', 'user')]
final readonly class WhoAmI
{
    public function __invoke(Consumer $consumer): ProfileView
    {
        return new ProfileView($consumer->id, $consumer->name, $consumer->method->value, $consumer->plan);
    }
}
```

- `#[Auth]` with no method fails the build: `Endpoint "App\Api\X" declares #[Auth] without any method. Use #[PublicEndpoint] for an open endpoint.`
- An unknown method fails the build: `Endpoint "App\Api\X" uses the unknown authentication method "oauth". Known methods: "api_key", "jwt", "user", "anonymous".`
- `jwt` or `user` without `symfony/security-bundle` fails the build: `Endpoint "App\Api\X" accepts the "jwt" authentication method, which needs symfony/security-bundle and a firewall in front of it.`
- Adding `anonymous` to other methods lets unauthenticated callers through as `ip:<address>` consumers, while still identifying callers that do send credentials.

### `#[PublicEndpoint]`

`Tollbooth\Core\Attribute\PublicEndpoint`, target: class, no parameter. The endpoint accepts anyone, as an anonymous consumer keyed by client IP, and requires no scope. It wins over `#[Auth]`, `#[Scope]` and the group's `auth` and `scopes`. Public endpoints are still rate limited, with the anonymous plan (see [rate-limiting.md](rate-limiting.md)).

```php
#[Endpoint('GET', '/products/{id}')]
#[PublicEndpoint]
final readonly class GetProduct
{
    public function __invoke(string $id): ProductView
    {
        // ...
    }
}
```

### `#[Scope]`

`Tollbooth\Core\Attribute\Scope`, target: class, repeatable.

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `...$scopes` | `string` (variadic) | required | Scopes the caller must all hold. |

```php
#[Endpoint('GET', '/billing/export')]
#[Auth('api_key', 'jwt')]
#[Scope('billing:read')]
#[Scope('billing:export')]
final readonly class ExportBilling
{
    // ...
}
```

- Every scope of every `#[Scope]` is required; duplicates are removed.
- Declared scopes replace the group's scopes entirely, including automatic ones.
- Scopes are checked for `api_key` and `jwt` consumers only. `user` consumers are left to the application's own authorization, and anonymous consumers carry no scope.
- An endpoint whose only accepted method is `anonymous` requires no scope, whatever is declared.
- A consumer lacking a scope answers 403 `This endpoint requires the scope "billing:export".` (see [http-contract.md](http-contract.md#401-and-403)).

#### Automatic scopes

A group configured with `scopes: auto` gives each endpoint without `#[Scope]` the scope `<resource>:read` for `GET`, `HEAD` and `OPTIONS`, and `<resource>:write` for any other method. The resource is the first segment of the declared path, after removing the group's `path` when the group matched the endpoint by path:

| Group | Declared | Scope |
| --- | --- | --- |
| `scopes: auto` | `GET /orders/{id}` | `orders:read` |
| `scopes: auto` | `POST /orders/{id}/cancel` | `orders:write` |
| `path: /backoffice`, `scopes: auto` | `POST /backoffice/tickets/{id}/replies` | `tickets:write` |
| `scopes: auto` | `GET /{slug}` | none (the first segment is a placeholder) |

## `#[RateLimit]`

`Tollbooth\Core\Attribute\RateLimit`, target: class, repeatable. Adds limits counted per consumer for this endpoint only, on top of the consumer's plan and the group's `rate_limits`. Plans, windows and headers are described in [rate-limiting.md](rate-limiting.md).

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `...$limits` | `string` (variadic, positional or named) | required | Limits written `<count>/<interval>`. A named argument names the limit. |

```php
#[Endpoint('GET', '/products/search')]
#[RateLimit('30/minute')]                               // named "search_products.minute"
final readonly class SearchProducts
{
    // ...
}

#[Endpoint('POST', '/orders/import', status: 202)]
#[RateLimit(burst: '5/10 seconds', imports: '20/hour')] // "import_orders.burst" and "import_orders.imports"
final readonly class ImportOrders
{
    // ...
}
```

Limit syntax:

- `<count>/<interval>`, where `<interval>` is an optional number followed by a unit: `s`, `sec`, `second`, `seconds`, `m`, `min`, `minute`, `minutes`, `h`, `hour`, `hours`, `d`, `day`, `days`. Case and spaces are free: `100/10 seconds`, `50/5m`, `200000/day`.
- `<count>` and the interval number must be at least 1.
- An unnamed limit is named after its window: `second`, `minute`, `hour` or `day` for one unit, `<n><s|m|h|d>` otherwise (`10s`, `5m`).
- The endpoint name and a dot prefix every endpoint limit name: `search_products.minute`.
- Windows are fixed and aligned on the clock (a day window resets at 00:00 UTC).

Errors at build time:

- An invalid spec: `Endpoint "App\Api\X": "30 per minute" is not a rate limit. Write it as "<count>/<interval>", for instance "600/minute", "100/10 seconds" or "200000/day".`
- A zero interval: `Endpoint "App\Api\X": The interval of the rate limit "5/0m" must be at least one minute.`
- Two limits with the same name, such as `#[RateLimit('10/minute', '20/minute')]`: `Endpoint "App\Api\X": Two rate limits of "endpoint:default.x" are named "x.minute". Name them explicitly, for instance {burst: "20/minute"}.`

When the same name is used in two separate `#[RateLimit]` attributes, the last one silently replaces the first: give each limit a distinct name. With `tollbooth.rate_limit.enabled: false`, `#[RateLimit]` is ignored.

## `#[DeprecatedEndpoint]`

`Tollbooth\Core\Attribute\DeprecatedEndpoint`, target: class, not repeatable. Marks the endpoint deprecated: every response carries `Deprecation`, and `Sunset` and `Link` when given; the OpenAPI operation is marked `deprecated`; the health report and the dashboard name the consumers still calling it. See [contract-changes.md](contract-changes.md) and [guides/deprecating-an-api-version.md](guides/deprecating-an-api-version.md).

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `since` | `string` | required | Date the endpoint was deprecated, `YYYY-MM-DD`, read as 00:00 UTC. Sent as `Deprecation: @<unix timestamp>` (RFC 9745). |
| `sunset` | `?string` | `null` | Date the endpoint will stop answering, `YYYY-MM-DD`, 00:00 UTC. Sent as `Sunset: <HTTP date>` (RFC 8594). Must not be before `since`. |
| `link` | `?string` | `null` | URL of the migration notes. Sent as `Link: <url>; rel="deprecation"`, added next to any `Link` header the response already has. |

```php
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

```
Deprecation: @1780272000
Sunset: Mon, 01 Mar 2027 00:00:00 GMT
Link: <https://docs.example.com/migrate-to-v2>; rel="deprecation"
```

- A date that is not a valid `YYYY-MM-DD` fails the build: `Endpoint "App\Api\X" declares the sunset date "2027-3-1"; write it as YYYY-MM-DD.`
- A sunset before `since` fails the build: `Endpoint "App\Api\X" has a sunset date before its deprecation date.`
- With `tollbooth.changes.gone_after_sunset: true`, the endpoint answers `410 Gone` (`This endpoint was retired on 2027-03-01.`) from its sunset date on, before authentication. By default it keeps answering normally after the sunset.

## Groups

A group is a set of endpoints forming one API: they share a mount prefix, output conventions, authentication methods, scopes, rate limits, an OpenAPI document and a set of dashboard screens. Groups are declared under `tollbooth.groups`; every key is described in [configuration.md](configuration.md).

```yaml
tollbooth:
    path_prefix: /api
    groups:
        shop:
            namespace: App\Api\Shop
            prefix: /v2
            output: { envelope: json_api, case: snake_case }
            scopes: auto
        partner:
            namespace: App\Api\Partner
            prefix: /partner
            output: { case: snake_case }
            auth: [api_key]
            scopes: auto
            rate_limits: ['10000/hour']
        backoffice:
            path: /backoffice
            output: { case: camel_case }
            auth: [jwt]
            scopes: auto
```

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `namespace` | `?string` | `null` | Endpoints whose class lives in this namespace or below belong to the group. Matches whole namespace segments: `App\Api\Shop` matches `App\Api\Shop\GetOrder` and `App\Api\Shop\Admin\X`, not `App\Api\ShopFront\X`. |
| `path` | `?string` | `null` | Endpoints whose declared path is this path or starts with it followed by `/` belong to the group. Matches whole segments. |
| `prefix` | `string` | `''` | Added to the paths of the group's endpoints, after `path_prefix`. |
| `output.envelope` | `plain`, `data`, `json_api` or `null` | `null` | Envelope of the group; `null` inherits `tollbooth.output.envelope`. |
| `output.case` | `preserve`, `snake_case`, `camel_case` or `null` | `null` | Field case of the group; `null` inherits `tollbooth.output.case`. |
| `auth` | list of methods or `null` | `null` | Accepted methods; `null` inherits `tollbooth.auth.default`. |
| `scopes` | list of scopes, `auto` or `null` | `null` | Scopes every endpoint of the group requires, or [automatic scopes](#automatic-scopes). `null` requires none. |
| `rate_limits` | list of limits, or map of names to limits | `[]` | Limits shared by all the calls a consumer makes to the group, named `<group>.<name>` (`partner.hour`). |

### Which group an endpoint belongs to

The first that applies:

1. the group named by `#[Endpoint(group: ...)]` (`'default'` forces the default group);
2. the group with the longest `namespace` containing the class;
3. the group with the longest `path` the declared path starts with;
4. the `default` group, which uses the top-level `output`, `auth.default`, no scopes and no group limits.

A namespace match always wins over a path match. The group is resolved when the container is built; nothing is matched per request.

- `group: 'billing'` when no such group is declared fails the build: `Endpoint "App\Api\X" belongs to the unknown group "billing". Declared groups: "default", "shop", "partner".`
- Declaring a group named `default` under `tollbooth.groups` fails the configuration: `"default" is the group of endpoints that match no other group and cannot be declared.`

## Route names

Each endpoint gets a Symfony route named:

| Group | Route name |
| --- | --- |
| `default` | `tollbooth_<name>`, for instance `tollbooth_get_order` |
| any other | `tollbooth_<group>_<name>`, for instance `tollbooth_partner_get_order` |

Endpoint names only need to be unique inside a group: `get_order` can exist in `default` and in `partner`. Use the route name to generate URLs:

```php
$url = $this->urlGenerator->generate('tollbooth_partner_get_order', ['id' => 7]);
```

Tollbooth's own routes are named `tollbooth_docs`, `tollbooth_docs_openapi`, `tollbooth_health`, `tollbooth_health_live`, `tollbooth_health_ready` and `tollbooth_dashboard_*`, and are added after the endpoints. Avoid endpoint and group names that would produce one of these names (an endpoint named `docs` in the default group, or `live` in a group named `health`): the later route replaces the endpoint's.

## Build errors

Endpoint declarations are checked when the container is built (`cache:clear`, `cache:warmup`, or the first request in debug mode). A failure stops the build with a `Tollbooth\Core\Metadata\InvalidEndpointException` (or an `InvalidArgumentException` for an unknown authentication method), whose message names the class:

| Message | Cause |
| --- | --- |
| `Endpoint "X" declares the unsupported HTTP method "FETCH".` | `method` is not one of `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`. |
| `Endpoint "X" must declare a public __invoke() method.` | No `__invoke()`, or not public. |
| `Endpoint "X" declares the invalid status code 42.` | `status` outside 100–599. |
| `Endpoints "X" and "Y" share the name "get_order". Set a distinct name on one of their #[Endpoint] attributes.` | Two endpoints of the same group with the same name. |
| `Endpoints "X" and "Y" both answer "GET /api/orders/{id}".` | Same method and same final path. |
| `Endpoint "X" belongs to the unknown group "billing". Declared groups: ...` | `group` names an undeclared group. |
| `Endpoint "X" declares #[Auth] without any method. Use #[PublicEndpoint] for an open endpoint.` | Empty `#[Auth]`. |
| `Endpoint "X" uses the unknown authentication method "oauth". Known methods: ...` | Unknown method in `#[Auth]`. |
| `Endpoint "X" accepts the "jwt" authentication method, which needs symfony/security-bundle and a firewall in front of it.` | `jwt` or `user` accepted without SecurityBundle. |
| `Endpoint "X" declares the since date "..."; write it as YYYY-MM-DD.` | Invalid date in `#[DeprecatedEndpoint]`. |
| `Endpoint "X" has a sunset date before its deprecation date.` | `sunset` before `since`. |
| `Endpoint "X": "..." is not a rate limit. ...` | Invalid `#[RateLimit]` spec. |
| `Endpoint "X": Two rate limits of "..." are named "...". ...` | Duplicate limit name in one `#[RateLimit]`. |
| `The class of endpoint service "x" cannot be found.` | The service's class does not exist. |

At request time, these mistakes throw a `LogicException` (answering 500):

- a `#[Body]` argument not typed with a class;
- a `#[Query]` argument of an unsupported type;
- a non-nullable `Consumer` argument outside an endpoint.

## See also

- [http-contract.md](http-contract.md): status codes, problem details and headers clients receive.
- [authentication.md](authentication.md): methods, consumers, API keys.
- [rate-limiting.md](rate-limiting.md): plans, limits and their headers.
- [openapi.md](openapi.md): how the declaration becomes the OpenAPI document.
- [guides/migrating-symfony-controllers.md](guides/migrating-symfony-controllers.md): turning existing controllers into endpoints.
