# Getting started

This guide installs Tollbooth in an existing Symfony application, declares a first endpoint that reads a JSON body into a validated DTO and returns a view, calls it with curl, and shows where the generated OpenAPI document and the recorded call appear. It assumes a standard Symfony Flex application (`config/`, `src/`, `App\` namespace autowired and autoconfigured by `config/services.yaml`). Every option used here is described in [configuration.md](configuration.md); endpoint declaration is covered in full in [endpoints.md](endpoints.md).

## Requirements

- PHP 8.3 or later (8.4 or later for Symfony 8).
- Symfony 7.4 or 8.x with FrameworkBundle.
- Optional, per feature:

| Feature | Needs |
| --- | --- |
| `jwt` and `user` authentication methods | `symfony/security-bundle` and a firewall in front of the endpoints |
| Monitoring, API keys or plans stored in a database | `doctrine/dbal` (usually through `doctrine/doctrine-bundle`) |
| Operator dashboard | `symfony/twig-bundle` and `symfony/translation` |

Tollbooth requires the Serializer, Validator, PropertyInfo, Lock and String components itself; Composer installs them and FrameworkBundle enables them, so no `framework.serializer` or `framework.validation` configuration is needed.

## 1. Install the package

Tollbooth is not on Packagist yet. Declare its repository in the application's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/sevj/tollbooth" }
    ]
}
```

Then require it:

```
composer require sevj/tollbooth:^0.26
```

## 2. Register the bundle

If Flex did not add it, add the bundle to `config/bundles.php`:

```php
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ...
    Tollbooth\TollboothBundle::class => ['all' => true],
];
```

## 3. Import the routes

Create `config/routes/tollbooth.yaml`:

```yaml
tollbooth:
    resource: .
    type: tollbooth
```

The `tollbooth` route loader type (public API) adds, in one import:

- a route per endpoint, named `tollbooth_<name>` (see [endpoints.md](endpoints.md#route-names));
- the documentation routes `/_tollbooth/docs/{group}` and `/_tollbooth/openapi/{group}.json` (unless `tollbooth.docs` is disabled);
- the health routes `/_tollbooth/health/live`, `/_tollbooth/health/ready` and `/_tollbooth/health.json` (unless `tollbooth.health` is disabled);
- the dashboard routes under `/_tollbooth` (when the dashboard is enabled).

Import it exactly once: a second import fails with `The "tollbooth" routes are already imported. Import them only once.`

Do not add a `prefix:` to this import. Put the API prefix in `tollbooth.path_prefix` instead (next step): Tollbooth uses that setting to decide which errors to answer as problem details, and a routing prefix would also move the `/_tollbooth` routes.

## 4. Configure the API prefix

Create `config/packages/tollbooth.yaml`:

```yaml
tollbooth:
    path_prefix: /api
```

Every endpoint path is mounted under `/api`, and any error under `/api` (including a 404 for an unknown URL or a 405 for a wrong method) answers `application/problem+json`. Everything else keeps its defaults: plain JSON output with field names as declared, and an API key required on every endpoint.

## 5. Protect `/_tollbooth`

Tollbooth does not protect its own pages. The documentation, the health report and the dashboard live under `/_tollbooth` and must be put behind the application's firewall. With the application's existing firewall (whatever authenticator it uses) covering `/_tollbooth`, restrict the path to operators in `config/packages/security.yaml`:

```yaml
security:
    firewalls:
        tollbooth_probes:
            pattern: ^/_tollbooth/health/(live|ready)$
            security: false
        # ... the application's own firewalls, for instance "main", come after
    access_control:
        - { path: ^/_tollbooth, roles: ROLE_ADMIN }
```

The `tollbooth_probes` firewall keeps the liveness and readiness probes reachable by orchestrators and uptime tools; it must be declared before any firewall that would otherwise match those paths. The health report lists a finding for each of the health report, documentation and dashboard that is outside any firewall. See [dashboard.md](dashboard.md) and [health.md](health.md).

API endpoints themselves need no firewall for the default `api_key` method: Tollbooth checks the `X-Api-Key` header itself. See [authentication.md](authentication.md).

## 6. Declare a first endpoint

An endpoint is one invokable class with the `#[Endpoint]` attribute. With the default `config/services.yaml` (`App\` loaded from `src/`, autowired and autoconfigured), nothing else needs registering.

The input DTO, validated with Symfony Validator constraints:

```php
<?php

declare(strict_types=1);

namespace App\Api\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateNoteInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $title = '',
        #[Assert\Length(max: 2000)]
        public string $body = '',
    ) {
    }
}
```

The view returned to the client:

```php
<?php

declare(strict_types=1);

namespace App\Api\Model;

final readonly class NoteView
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
```

The endpoint:

```php
<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Model\CreateNoteInput;
use App\Api\Model\NoteView;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('POST', '/notes', status: 201)]
final readonly class CreateNote
{
    public function __invoke(#[Body] CreateNoteInput $input): NoteView
    {
        return new NoteView(bin2hex(random_bytes(6)), $input->title, $input->body, new \DateTimeImmutable());
    }
}
```

In a real application the endpoint receives its services through the constructor and delegates to them; see [endpoints.md](endpoints.md#services).

Clear the cache and check the route:

```
bin/console cache:clear
bin/console debug:router | grep tollbooth_
```

The list contains `tollbooth_create_note` with method `POST` and path `/api/notes`. The name comes from the class name; see [endpoints.md](endpoints.md#route-names).

If the container fails to build, the message names the endpoint class and the problem (unsupported method, missing `__invoke()`, duplicate route…). The full list is in [endpoints.md](endpoints.md#build-errors).

## 7. Create an API key

Endpoints require an API key by default. Generate one for yourself:

```
bin/console tollbooth:api-key:generate my-laptop
```

The command prints the key once (`tb_live_` followed by a 12 character id, `_` and a 32 character secret) and the configuration to add. Add that configuration to `config/packages/tollbooth.yaml`:

```yaml
tollbooth:
    path_prefix: /api
    auth:
        api_keys:
            my-laptop:
                id: <id printed by the command>
                hash: <hash printed by the command>
```

Only a SHA-256 hash of the secret is kept; the key cannot be shown again. Keep the printed key for the next step. Scopes, plans, expiry, allowed addresses and keys stored in a database are covered in [authentication.md](authentication.md).

To try an endpoint without a key instead, mark it `#[PublicEndpoint]`, or set `tollbooth.auth.default: [anonymous]` while developing.

## 8. Call it

With the application served on `http://localhost:8000` (for instance with `symfony serve` or `php -S localhost:8000 -t public`):

```
curl -i -X POST http://localhost:8000/api/notes \
    -H "X-Api-Key: tb_live_..." \
    -H "Content-Type: application/json" \
    -d '{"title": "First note", "body": "Hello"}'
```

```
HTTP/1.1 201 Created
Content-Type: application/json

{"id":"3f9a0c1b7d2e","title":"First note","body":"Hello","createdAt":"2026-11-25T14:02:05+00:00"}
```

An invalid body answers 422 with one entry per violation:

```
curl -i -X POST http://localhost:8000/api/notes \
    -H "X-Api-Key: tb_live_..." \
    -H "Content-Type: application/json" \
    -d '{"title": ""}'
```

```
HTTP/1.1 422 Unprocessable Content
Content-Type: application/problem+json

{"type":"about:blank","title":"Validation Failed","status":422,"violations":[{"field":"title","message":"This value should not be blank."}]}
```

Without the header, the call answers 401 with a challenge naming the header:

```
HTTP/1.1 401 Unauthorized
Content-Type: application/problem+json
WWW-Authenticate: ApiKey header="X-Api-Key"

{"type":"about:blank","title":"Unauthorized","status":401,"detail":"Authentication is required to access this endpoint."}
```

Every status code and header clients can receive is listed in [http-contract.md](http-contract.md).

## 9. See the OpenAPI document

The documentation module is on by default. An endpoint that matches no configured group belongs to the group `default`:

| URL | Content |
| --- | --- |
| `http://localhost:8000/_tollbooth/docs/default` (or `/_tollbooth/docs`) | Documentation page (Scalar, loaded from jsDelivr), with a link per group |
| `http://localhost:8000/_tollbooth/openapi/default.json` | OpenAPI 3.1 document |

The document describes `POST /api/notes` with the `CreateNoteInput` request schema (including `NotBlank` and `Length` constraints), the 201 response with `NoteView`, the API key security scheme and the error responses the endpoint can answer. To write it to a file:

```
bin/console tollbooth:openapi:export default --yaml --output=public/openapi.yaml
```

See [openapi.md](openapi.md).

## 10. See the call in the dashboard

Monitoring is off by default. It stores calls with Doctrine DBAL, and the dashboard needs Twig and the Translation component:

```
composer require doctrine/doctrine-bundle symfony/twig-bundle symfony/translation
```

Enable monitoring in `config/packages/tollbooth.yaml`:

```yaml
tollbooth:
    path_prefix: /api
    monitoring:
        enabled: true
        connection: default
    auth:
        api_keys:
            my-laptop:
                id: <id printed by the command>
                hash: <hash printed by the command>
```

`connection` is a DBAL connection name (`default` is the one DoctrineBundle configures from `DATABASE_URL`). Create the tables once, or print the SQL for a migration with `--dump-sql`:

```
bin/console tollbooth:monitoring:setup
```

Call the endpoint again (step 8), then open, logged in with a user that has `ROLE_ADMIN`:

| URL | Shows |
| --- | --- |
| `http://localhost:8000/_tollbooth` | The map of the first group, with its traffic |
| `http://localhost:8000/_tollbooth/endpoints/default/create_note` | The endpoint: pipeline, declaration, fields, examples, latency and status codes |
| `http://localhost:8000/_tollbooth/requests/default` | Recorded calls, newest first; a call opens its detail |

A call is recorded after its response has been sent. Request bodies, request headers and query strings are never stored. Without monitoring, the dashboard still shows the map, endpoints and models, but no traffic and no requests screen. See [monitoring.md](monitoring.md) and [dashboard.md](dashboard.md).

## Next steps

- [endpoints.md](endpoints.md): path and query parameters, groups, scopes, rate limits, envelopes and field cases.
- [http-contract.md](http-contract.md): what clients receive in every situation.
- [authentication.md](authentication.md): JWT and session users, scopes, keys in a database.
- [rate-limiting.md](rate-limiting.md): plans and per-endpoint limits.
- [health.md](health.md): probes for orchestrators and your own checks.
- [contract-changes.md](contract-changes.md): snapshots and the diff to run in CI.
- [guides/migrating-symfony-controllers.md](guides/migrating-symfony-controllers.md): moving an existing API onto Tollbooth.
