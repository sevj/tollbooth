# Migrating Symfony controllers to Tollbooth

This guide moves an existing Symfony JSON API onto Tollbooth without breaking its current clients. The starting point is a typical application: controllers with several `#[Route]` actions, a custom authenticator on a stateless firewall, responses shaped like `{"success": true, ...}`, ETag/304 handling and binary downloads. The end point is two groups: a `legacy` group that answers on the old paths in the old format, marked deprecated with a sunset date, and a `v2` group built with DTOs and Tollbooth's conventions, both calling the same services. Monitoring then shows who still calls the old routes, and they are removed after the sunset.

- [Before you start](#before-you-start)
- [Step 1: install and choose the groups](#step-1-install-and-choose-the-groups)
- [Step 2: move the logic into services](#step-2-move-the-logic-into-services)
- [Step 3: one endpoint class per legacy action](#step-3-one-endpoint-class-per-legacy-action)
- [Step 4: keep the custom authenticator](#step-4-keep-the-custom-authenticator)
- [Step 5: deprecate the legacy endpoints](#step-5-deprecate-the-legacy-endpoints)
- [Step 6: build v2 with Tollbooth conventions](#step-6-build-v2-with-tollbooth-conventions)
- [Step 7: watch who still calls the old routes](#step-7-watch-who-still-calls-the-old-routes)
- [Step 8: remove the legacy endpoints after the sunset](#step-8-remove-the-legacy-endpoints-after-the-sunset)
- [Current limits](#current-limits)

## Before you start

The application this guide starts from:

```php
// src/Controller/ProductController.php
namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/products')]
final class ProductController extends AbstractController
{
    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id, Request $request, ProductRepository $products): JsonResponse
    {
        $product = $products->find($id);
        if (null === $product) {
            return $this->json(['success' => false, 'error' => 'Product not found'], 404);
        }

        $response = $this->json(['success' => true, 'product' => ['id' => $product->getId(), 'name' => $product->getName(), 'price' => $product->getPrice()]]);
        $response->setEtag(md5((string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // decodes the body, validates by hand, persists, answers {"success": true, "id": 42}
    }

    #[Route('/{id}/manual', methods: ['GET'])]
    public function manual(int $id, ProductRepository $products): BinaryFileResponse
    {
        // returns the PDF manual of the product
    }
}
```

```yaml
# config/packages/security.yaml (before)
security:
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            custom_authenticators: [App\Security\ApiTokenAuthenticator]   # reads X-Auth-Token
    access_control:
        - { path: ^/api, roles: IS_AUTHENTICATED_FULLY }
```

Read [getting-started.md](../getting-started.md) and [endpoints.md](../endpoints.md) first if you have never declared a Tollbooth endpoint.

## Step 1: install and choose the groups

```
composer require tollbooth/tollbooth-bundle
```

Import the endpoint routes next to your existing ones:

```yaml
# config/routes/tollbooth.yaml
tollbooth:
    resource: .
    type: tollbooth
```

Declare two groups, one per API version, matched by namespace:

```yaml
# config/packages/tollbooth.yaml
tollbooth:
    path_prefix: /api
    groups:
        legacy:
            namespace: App\Api\Legacy      # mounted on /api, like the old routes
            auth: [user]
        v2:
            namespace: App\Api\V2
            prefix: /v2                    # mounted on /api/v2
            output: { envelope: data, case: snake_case }
            auth: [user, api_key]
```

- `path_prefix: /api` is prepended to every endpoint path, so a legacy endpoint declared on `/products/{id}` answers `/api/products/{id}`, exactly like the old route.
- Any error under `/api` now answers RFC 9457 `application/problem+json`, including a 404 or 405 from the router and errors of the old controllers that are still there. Plan for it (see [Current limits](#current-limits)).
- Groups and their settings are described in [configuration.md](../configuration.md); the output conventions in [http-contract.md](../http-contract.md).

## Step 2: move the logic into services

A Tollbooth endpoint is one invokable class per route. The legacy and the v2 endpoint of the same action must share the business logic, so move it out of the controller into a service first. Keep formatting out of it: each version formats its own response.

```php
// src/Catalog/ProductCatalog.php
namespace App\Catalog;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProductCatalog
{
    public function __construct(
        private ProductRepository $products,
        private EntityManagerInterface $em,
        private string $manualDirectory,
    ) {
    }

    public function find(int $id): ?Product
    {
        return $this->products->find($id);
    }

    public function create(string $name, int $price): Product
    {
        $product = new Product($name, $price);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    public function manualPath(Product $product): ?string
    {
        $path = $this->manualDirectory.'/'.$product->getId().'.pdf';

        return is_file($path) ? $path : null;
    }
}
```

```yaml
# config/services.yaml
services:
    App\Catalog\ProductCatalog:
        arguments:
            $manualDirectory: '%kernel.project_dir%/var/manuals'
```

## Step 3: one endpoint class per legacy action

Each old action becomes a class in `App\Api\Legacy` that answers the same path in the same format. **An endpoint that returns a Symfony `Response` is sent untouched**: Tollbooth only serializes results that are not a `Response`, so the old JSON shape, the old status codes, the ETag and the file downloads are kept. Tollbooth still adds its own headers around it (deprecation headers, rate limit headers when the endpoint is rate limited) and records the call when monitoring is on.

Delete each old action as soon as its endpoint exists: two routes on the same path and method would compete, and the first one loaded wins.

Endpoints are autoconfigured from the `#[Endpoint]` attribute; with the default `config/services.yaml` (`App\` loaded with `autoconfigure: true`), nothing else needs registering.

### JSON with the old shape and ETag/304

```php
// src/Api/Legacy/GetProduct.php
namespace App\Api\Legacy;

use App\Catalog\ProductCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/products/{id}')]
final readonly class GetProduct
{
    public function __construct(private ProductCatalog $catalog)
    {
    }

    public function __invoke(int $id, Request $request): Response
    {
        $product = $this->catalog->find($id);
        if (null === $product) {
            return new JsonResponse(['success' => false, 'error' => 'Product not found'], 404);
        }

        $response = new JsonResponse(['success' => true, 'product' => [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
        ]]);
        $response->setEtag(md5((string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}
```

- `int $id` is bound from the path by name. An `int` placeholder only matches digits, so `/api/products/abc` now answers a problem+json 404 from the router instead of reaching your code.
- `Request $request` is resolved by Symfony like in any controller. Other usual controller arguments work too.
- `isNotModified()` turns the response into a `304 Not Modified` with no body when the client's `If-None-Match` matches; it passes through Tollbooth like any other response.

### Request bodies in the old format

Keep reading the body yourself in legacy endpoints. `#[Body]` would answer malformed or invalid bodies with problem details (`400`, `415`, `422`), not with the old `{"success": false}` format.

```php
// src/Api/Legacy/CreateProduct.php
namespace App\Api\Legacy;

use App\Catalog\ProductCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('POST', '/products')]
final readonly class CreateProduct
{
    public function __construct(private ProductCatalog $catalog)
    {
    }

    public function __invoke(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\is_string($data['name'] ?? null) || '' === $data['name'] || !\is_int($data['price'] ?? null) || $data['price'] <= 0) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid product'], 400);
        }

        $product = $this->catalog->create($data['name'], $data['price']);

        return new JsonResponse(['success' => true, 'id' => $product->getId()], 201);
    }
}
```

Catch the exceptions your old clients must never see as problem details and turn them into old-format responses here: any exception that leaves an endpoint becomes `application/problem+json`.

### Binary downloads

```php
// src/Api/Legacy/DownloadManual.php
namespace App\Api\Legacy;

use App\Catalog\ProductCatalog;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/products/{id}/manual')]
final readonly class DownloadManual
{
    public function __construct(private ProductCatalog $catalog)
    {
    }

    public function __invoke(int $id): Response
    {
        $product = $this->catalog->find($id);
        $path = null === $product ? null : $this->catalog->manualPath($product);
        if (null === $path) {
            return new JsonResponse(['success' => false, 'error' => 'Manual not found'], 404);
        }

        return (new BinaryFileResponse($path))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'manual-'.$id.'.pdf');
    }
}
```

### Names and routes

The endpoint name defaults to the class short name in snake_case, and must only be unique inside its group, so `App\Api\Legacy\GetProduct` and `App\Api\V2\GetProduct` can coexist: their routes are `tollbooth_legacy_get_product` and `tollbooth_v2_get_product`. Check the result with:

```
bin/console debug:router | grep tollbooth_
```

## Step 4: keep the custom authenticator

The `user` method lets the firewall authenticate the call with its existing authenticators; Tollbooth then turns the authenticated Symfony user into the consumer (id and name: `getUserIdentifier()`), which rate limiting and monitoring key on. See [authentication.md](../authentication.md).

```yaml
# config/packages/security.yaml (after)
security:
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            custom_authenticators: [App\Security\ApiTokenAuthenticator]
    # no access_control rule on ^/api: Tollbooth decides who may call each endpoint
```

- The firewall must cover every endpoint that accepts `user` (here `^/api`). Without `symfony/security-bundle`, the container build fails.
- **Remove the `access_control` rule on `^/api`** (or make it `PUBLIC_ACCESS`). Tollbooth answers 401 itself when no accepted credentials are present; an `IS_AUTHENTICATED_FULLY` rule would refuse calls that Tollbooth authenticates on its own, such as API keys in v2 or public endpoints. Fine-grained authorization stays in your code: `#[IsGranted('ROLE_CATALOG_ADMIN')]` on an endpoint class or its `__invoke()` works as on any controller.
- Your authenticator's `supports()` should return `false` when its header is absent, so that requests with an `X-Api-Key` (v2) or without credentials reach Tollbooth.
- When the authenticator rejects a token, the firewall sends whatever `onAuthenticationFailure()` returns, so the old error format is kept for bad tokens. A call without any credentials gets Tollbooth's `401` problem details instead.

**If your authenticator reads `Authorization: Bearer …`** (a personal token, say), declare the groups with the `user` method: since 0.23.0 a `Bearer` header counts as a JWT only on endpoints that accept `jwt`, so the firewall's user becomes a `user` consumer. Do not require scopes on these groups: a consumer built from a firewall user has no scopes, so any required scope would answer 403.

```yaml
tollbooth:
    groups:
        legacy:
            namespace: App\Api\Legacy
            auth: [user]
        v2:
            namespace: App\Api\V2
            prefix: /v2
            output: { envelope: data, case: snake_case }
            auth: [user, api_key]
```

Rate limiting applies per consumer: every user gets `tollbooth.rate_limit.default_plan`, since plans are attached to API keys (see [rate-limiting.md](../rate-limiting.md)).

## Step 5: deprecate the legacy endpoints

Mark every legacy endpoint deprecated, with a sunset date and a link to the migration guide you publish for your clients. There is no group-level setting: the attribute goes on each class.

```php
use Tollbooth\Core\Attribute\DeprecatedEndpoint;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/products/{id}')]
#[DeprecatedEndpoint(since: '2026-12-01', sunset: '2027-06-01', link: 'https://docs.example.com/api/v2-migration')]
final readonly class GetProduct
{
    // ...
}
```

Every response of these endpoints, including the old-format ones, now carries:

```http
Deprecation: @1796083200
Sunset: Tue, 01 Jun 2027 00:00:00 GMT
Link: <https://docs.example.com/api/v2-migration>; rel="deprecation"
```

The details (headers, OpenAPI flags, health findings) are in [contract-changes.md](../contract-changes.md#deprecating-an-endpoint) and the whole procedure in [deprecating-an-api-version.md](deprecating-an-api-version.md).

## Step 6: build v2 with Tollbooth conventions

The new version takes typed input, returns DTOs and lets Tollbooth handle hydration, validation, serialization, errors, documentation and rate limits. It calls the same `ProductCatalog`.

```php
// src/Api/V2/Model/ProductView.php
namespace App\Api\V2\Model;

use App\Entity\Product;

final readonly class ProductView
{
    public function __construct(
        public int $id,
        public string $name,
        public int $priceCents,
    ) {
    }

    public static function from(Product $product): self
    {
        return new self($product->getId(), $product->getName(), $product->getPrice());
    }
}
```

```php
// src/Api/V2/Model/CreateProductInput.php
namespace App\Api\V2\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateProductInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $name,
        #[Assert\Positive]
        public int $priceCents,
    ) {
    }
}
```

```php
// src/Api/V2/GetProduct.php
namespace App\Api\V2;

use App\Api\V2\Model\ProductView;
use App\Catalog\ProductCatalog;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/products/{id}')]
final readonly class GetProduct
{
    public function __construct(private ProductCatalog $catalog)
    {
    }

    public function __invoke(int $id): ProductView
    {
        $product = $this->catalog->find($id) ?? throw new NotFoundHttpException(\sprintf('No product %d.', $id));

        return ProductView::from($product);
    }
}
```

```php
// src/Api/V2/CreateProduct.php
namespace App\Api\V2;

use App\Api\V2\Model\CreateProductInput;
use App\Api\V2\Model\ProductView;
use App\Catalog\ProductCatalog;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('POST', '/products', status: 201)]
final readonly class CreateProduct
{
    public function __construct(private ProductCatalog $catalog)
    {
    }

    public function __invoke(#[Body] CreateProductInput $input): ProductView
    {
        return ProductView::from($this->catalog->create($input->name, $input->priceCents));
    }
}
```

With the `v2` group settings above, `GET /api/v2/products/42` answers `{"data": {"id": 42, "name": "Gate", "price_cents": 4990}}`, the body of `POST /api/v2/products` is read in snake_case and validated (`422` with `violations` when invalid), and errors are problem details. The whole contract is in [http-contract.md](../http-contract.md), the declaration options in [endpoints.md](../endpoints.md).

### ETag and 304 in v2

Nothing to write: since 0.23.0, every successful `GET` answer that Tollbooth renders carries an `ETag` computed from its body, and a client that sends it back in `If-None-Match` gets `304 Not Modified` without a body (`tollbooth.output.etag`, on by default; see [http-contract.md](../http-contract.md)). The handler still runs and the result is still serialized on every call: it saves bandwidth, not work. Monitoring records such calls with status 304.

If a v2 answer must stay cacheable across requests on the server side as well (the same body for every member of a group, say), keep that cache in the service the endpoint calls.

### Downloads in v2

Return a `BinaryFileResponse` (or a `StreamedResponse`) as in the legacy endpoint: it passes through untouched, without an ETag, and the OpenAPI document describes it as an `application/octet-stream` binary body.

### API keys for new clients

`v2` also accepts `api_key`: new integrations can get a key (`bin/console tollbooth:api-key:generate acme-erp --plan=pro`) with its own plan and scopes, while existing users keep their tokens. See [authentication.md](../authentication.md).

## Step 7: watch who still calls the old routes

Enable monitoring and create its tables (see [monitoring.md](../monitoring.md)):

```yaml
tollbooth:
    monitoring:
        enabled: true
        connection: default
```

```
bin/console tollbooth:monitoring:setup
```

Then, during the deprecation period:

| Where | What it tells you |
| --- | --- |
| Dashboard map of `legacy`, Live traffic | "Needs attention" lists each deprecated endpoint still called, how often and by whom |
| Dashboard endpoints list, filter "Deprecated" | Calls, errors and p95 of each legacy endpoint |
| Endpoint page, Statistics tab | Top consumers of one legacy endpoint |
| Requests screen, `endpoint:get_product` (with the legacy group selected) | Each call, with consumer and IP |
| Consumer page | "Upcoming changes": the deprecated endpoints that consumer still calls |
| `bin/console tollbooth:health` | A finding per deprecated endpoint called in the last 7 days, naming the consumers; a warning within 30 days of the sunset |

The consumers are your users (identified by `getUserIdentifier()`), so you know whom to contact. See [dashboard.md](../dashboard.md).

Save a snapshot when you release v2, so that later changes are compared with it (see [contract-changes.md](../contract-changes.md)):

```
bin/console tollbooth:contract:snapshot 2.0.0
```

## Step 8: remove the legacy endpoints after the sunset

1. Optionally, let the endpoints answer `410 Gone` from the sunset date onwards, before you delete them:

   ```yaml
   tollbooth:
       changes:
           gone_after_sunset: true
   ```

   From then on, monitoring records the remaining calls under the client IP, since authentication no longer runs on these endpoints.

2. Delete the `App\Api\Legacy` classes and the `legacy` group from the configuration.
3. Run `bin/console tollbooth:diff`: every removed endpoint is reported `BREAKING … The endpoint is removed.` with its recent traffic. That is the expected outcome of the sunset; save the snapshot of the new version in the same change (`bin/console tollbooth:contract:snapshot 3.0.0`) so that the CI diff is clean afterwards.
4. Once no endpoint needs the `user` method any more, the custom authenticator and the `api` firewall can go too.

## Current limits

Tollbooth does not hide these; plan around them.

| Limit | Consequence | What to do |
| --- | --- | --- |
| Errors under the API prefix become problem details | Any exception leaving an endpoint, and any error of a non-Tollbooth route under `/api` (404, 405, an old controller's exception), answers `application/problem+json`. For non-Tollbooth routes the `detail` is dropped. | Catch exceptions inside legacy endpoints and return the old format. Migrate the remaining old controllers before clients rely on their error bodies. |
| Tollbooth's own refusals are problem details | `401` (no credentials), `403` (scope), `429` (rate limit), `410` (after sunset), router `404`/`405` and `#[Body]` errors use problem details, also on legacy endpoints. | Do not use `#[Body]` in legacy endpoints. Tell clients about the 401 and 429 shapes in the migration guide. |
| Firewall users have no scopes | A user authenticated by your own authenticator, with a session or an `Authorization: Bearer` token, is a `user` consumer without scopes. | Do not require scopes on those groups. |
| No plan per user | `user` and `jwt` consumers get `default_plan`. | Use API keys for clients that need their own quota. |
| OpenAPI of `Response` endpoints | An endpoint declared to return a `Response` is documented without the shape of its body: a file or stream as a binary body, a `JsonResponse` as free-form JSON. The old JSON shape is not described, and there is no way to exclude an endpoint from the document. | Accept it for deprecated legacy endpoints, or keep the documentation UI pointed at the v2 group only. See [openapi.md](../openapi.md#known-limits). |
| Response size of file downloads | Monitoring records the size of a `BinaryFileResponse` as 0 bytes, and of a `StreamedResponse` as unknown. | None needed; status, duration and consumer are recorded. |
| No group-level deprecation | `#[DeprecatedEndpoint]` goes on each class. | Add it to every class of the legacy namespace. |
