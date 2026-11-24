# Tollbooth

Tollbooth is a Symfony bundle for building HTTP APIs from plain PHP classes. Declare an endpoint with one attribute and typed arguments; Tollbooth routes it, hydrates and validates the input, serializes the output and reports errors as RFC 9457 problem details.

Planned on top of that core: OpenAPI documentation, rate limiting with quotas, call monitoring, health checks, contract diffs between releases and an operator dashboard.

Requires PHP 8.4 and Symfony 7.4 or 8.x.

## Installation

```
composer require tollbooth/tollbooth-bundle
```

Enable the bundle in `config/bundles.php` if Flex did not:

```php
Tollbooth\TollboothBundle::class => ['all' => true],
```

Import the endpoint routes in `config/routes/tollbooth.yaml`:

```yaml
tollbooth:
    resource: .
    type: tollbooth
```

## Declaring an endpoint

An endpoint is an invokable service with the `#[Endpoint]` attribute. Route parameters are bound by name, `#[Query]` reads one query parameter and `#[Body]` deserializes the JSON request body and validates it (`#[Body(groups: ['create'])]` picks validation groups).

```php
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;

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

The returned object is serialized to JSON with the status declared on the attribute. An invalid body answers `422` with the violations:

```json
{
    "type": "about:blank",
    "title": "Validation Failed",
    "status": 422,
    "violations": [
        { "field": "quantity", "message": "This value should be positive." }
    ]
}
```

## Configuration

```yaml
tollbooth:
    path_prefix: /api
    output:
        envelope: plain    # plain, data or json_api
        case: preserve     # preserve, snake_case or camel_case
```

`path_prefix` is prepended to every endpoint path. Any error under it, including a 404 or a 405 from the router, answers problem details.

### Output conventions

`output.envelope` decides how a result is wrapped:

| Envelope | `GET /things/7` answers |
| --- | --- |
| `plain` | `{"id": 7, "name": "Gate"}` |
| `data` | `{"data": {"id": 7, "name": "Gate"}}` |
| `json_api` | `{"data": {"type": "things", "id": "7", "attributes": {"name": "Gate"}}}` |

With `json_api`, an object that has an `id` becomes a resource object, and a list of them a collection. The type is the plural of the class name without its `View`, `Output`, `Resource`, `Response` or `Dto` suffix (`OrderLineView` gives `order_lines`). Any other result goes under `meta`. Responses are sent as `application/vnd.api+json`.

`output.case` converts field names both ways: request bodies are read with it, response bodies are written with it, and validation errors name their fields with it. Tollbooth uses its own named serializers, so the case of the application's `serializer` service is left untouched.

Errors are never wrapped in an envelope: they always answer `application/problem+json`.

## Versioning

Tollbooth follows [Semantic Versioning 2.0.0](https://semver.org/). Notable changes for each release are recorded in [`CHANGELOG.md`](CHANGELOG.md), and each version is a git tag.

### Public API

The public API is the contract host applications and API clients rely on:

- the attributes `Tollbooth\Core\Attribute\Endpoint`, `Body` and `Query`;
- the `tollbooth` route loader type;
- the `tollbooth.*` configuration keys;
- the HTTP contract: status codes, envelopes, field name cases and the problem details shape.

Anything else is internal and may change in any release.

### Breaking-change policy

- Removing or renaming a public attribute, interface, method or configuration key is breaking (MAJOR).
- Adding a method to a public interface that hosts implement is breaking, unless it has a default implementation.
- Changing what API clients receive (headers, problem details shape, envelopes) is breaking.
- New attributes, interfaces and optional configuration keys are MINOR.
- Fixes, performance work and refactors that keep the public API are PATCH.
- Before 1.0, a MINOR release may break; its changelog says so under *Upgrade notes*.

## Development

PHP runs in Docker:

```
docker compose run --rm php composer install
docker compose run --rm php composer qa
```

## License

MIT
