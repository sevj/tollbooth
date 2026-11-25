# Authentication

Every Tollbooth endpoint declares which authentication methods it accepts: an API key checked by Tollbooth itself, a JWT or a logged-in user checked by Symfony Security, or nothing at all. Whatever the method, the caller becomes a `Tollbooth\Auth\Consumer` (id, name, method, scopes, plan): the endpoint can take it as an argument, [rate limiting](rate-limiting.md) counts per consumer, and [monitoring](monitoring.md) records it with each call. Scopes add a second check on top: a consumer authenticated by API key or JWT must carry every scope the endpoint requires. This page covers the methods and how they are chosen, how each one resolves a consumer, scopes, API keys (format, configured keys, providers, keys stored in the database) and the error responses.

## Contents

- [Methods](#methods)
- [Choosing the methods of an endpoint](#choosing-the-methods-of-an-endpoint)
- [How a call is authenticated](#how-a-call-is-authenticated)
- [The `Consumer`](#the-consumer)
- [Scopes](#scopes)
- [API keys](#api-keys)
- [Key providers](#key-providers)
- [Stored keys](#stored-keys)
- [JWT and user: the firewall](#jwt-and-user-the-firewall)
- [Error responses](#error-responses)
- [Health checks and findings](#health-checks-and-findings)

## Methods

The methods are the cases of the enum `Tollbooth\Core\Security\AuthMethod`.

| Method | Value | Credentials | Checked by | Carries scopes | Needs |
| --- | --- | --- | --- | --- | --- |
| API key | `api_key` | `X-Api-Key: tb_live_…` (header set by `auth.api_key_header`) | Tollbooth | yes | nothing |
| JWT | `jwt` | `Authorization: Bearer …` | Symfony Security, usually the `access_token` authenticator | yes, from the `scope` or `scp` claim of an OIDC user | `symfony/security-bundle` and a firewall |
| User | `user` | session cookie, HTTP Basic, or any other authenticator of the firewall | Symfony Security | no: use `#[IsGranted]` and voters | `symfony/security-bundle` and a firewall |
| Anonymous | `anonymous` | none | nobody: the consumer is the client IP | no | nothing |

## Choosing the methods of an endpoint

The accepted methods come from the most specific declaration:

1. `#[PublicEndpoint]` on the class: `anonymous` only, and no scope.
2. `#[Auth(...)]` on the class: these methods, replacing the group's.
3. `groups.<name>.auth` of the endpoint's group.
4. `auth.default`, which defaults to `[api_key]`.

```php
use Tollbooth\Core\Attribute\Auth;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Core\Attribute\PublicEndpoint;
use Tollbooth\Core\Attribute\Scope;

#[Endpoint('POST', '/orders', status: 201)]
#[Auth('api_key', 'jwt')]
#[Scope('orders:write')]
final class CreateOrder
{
    public function __invoke(): array
    {
        return ['created' => true];
    }
}

#[Endpoint('GET', '/status')]
#[PublicEndpoint]
final class Status
{
    public function __invoke(): array
    {
        return ['status' => 'ok'];
    }
}
```

```yaml
tollbooth:
    auth:
        default: [api_key]
    groups:
        partner:
            namespace: App\Api\Partner
            auth: [api_key]
            scopes: auto
        mobile:
            namespace: App\Api\Mobile
            auth: [api_key, user]
        backoffice:
            path: /backoffice
            auth: [jwt]
            scopes: auto
```

Rules checked when the container is built:

- An unknown method name fails the build: `Endpoint "App\Api\CreateOrder" uses the unknown authentication method "oauth". Known methods: "api_key", "jwt", "user", "anonymous".`
- `#[Auth]` without any method fails the build: use `#[PublicEndpoint]` for an open endpoint.
- An endpoint accepting `jwt` or `user` while `symfony/security-bundle` is not installed fails the build.
- `#[PublicEndpoint]` wins over `#[Auth]` when both are present.

An empty list (`auth.default: []` or `auth: []` on a group) stops the build: use `[anonymous]` for an open group.

## How a call is authenticated

For a request routed to an endpoint, Tollbooth's access listener runs on `kernel.request` at priority 7, after the router and the Symfony firewall (priority 8), and tries in this order:

1. **API key.** If `api_key` is accepted and the request carries the API key header, the key is checked. A key that is malformed, unknown, wrong, expired or used from a refused address answers **401 at once**: Tollbooth does not fall back to the other methods, even to `anonymous`.
2. **Symfony token.** Otherwise, if the token storage holds a user, Tollbooth turns it into a consumer (see below). The consumer is used only if its method (`jwt` or `user`) is accepted.
3. **Anonymous.** Otherwise, if `anonymous` is accepted, the consumer is `ip:<client IP>`.
4. Otherwise the call answers **401** with a `WWW-Authenticate` challenge.

Then the scopes are checked: a consumer missing a required scope answers **403**.

The consumer is resolved before rate limiting (priority 6) and before the endpoint's arguments are resolved. Calls rejected with 401 are not counted against any rate limit; they are recorded by monitoring with the auth method `none`.

### Consumer of each method

| Method | `id` | `name` | `scopes` | `plan` |
| --- | --- | --- | --- | --- |
| `api_key` | the key id (12 characters) | the key's name (`api_keys` key, or the name given when generating) | the key's scopes | the key's plan |
| `jwt`, with an `OidcUser` | claim `client_id`, else `azp`, else the user identifier | `OidcUser::getName()` (claim `name`), else the id | claim `scope` (space-separated string) or `scp` (string or list of strings) | `null` |
| `jwt`, other user classes | the user identifier | the user identifier | none | `null` |
| `user` | the user identifier | the user identifier | none (not checked) | `null` |
| `anonymous` | `ip:<client IP>`, or `ip:unknown` | same as id | none (not checked) | `null` |

How the method of a Symfony user is decided:

- an `Symfony\Component\Security\Core\User\OidcUser` is always a `jwt` consumer;
- any other `UserInterface` is a `jwt` consumer when the request has an `Authorization` header starting with `Bearer ` **and** the endpoint accepts `jwt`; otherwise it is a `user` consumer. A personal token read from `Authorization: Bearer` by your own authenticator is therefore accepted by an endpoint that allows `user` (before 0.23.0 it was taken for a JWT and refused with 401).

The client IP comes from `Request::getClientIp()`: behind a load balancer or reverse proxy, configure `framework.trusted_proxies`, otherwise every anonymous consumer is the proxy's address and shares one set of counters.

## The `Consumer`

`Tollbooth\Auth\Consumer` is a final readonly value object, part of the public API.

| Property | Type | Content |
| --- | --- | --- |
| `id` | `string` | Stable identifier, the key of rate limit counters and monitoring. |
| `name` | `string` | Display name. |
| `method` | `Tollbooth\Core\Security\AuthMethod` | How the consumer authenticated. |
| `scopes` | `list<string>` | Scopes granted (API key and OIDC JWT only). |
| `plan` | `?string` | Rate limit plan, from the API key; `null` for other methods. |

| Method | Returns |
| --- | --- |
| `Consumer::anonymous(?string $ip): Consumer` | The consumer `ip:<ip>` (`ip:unknown` for `null`) with the `anonymous` method. |
| `missingScopes(list<string> $required): list<string>` | The required scopes the consumer lacks. Always `[]` for `user` and `anonymous` consumers; the access check itself refuses anonymous calls to an endpoint with scopes (401). |

An endpoint receives it by type:

```php
use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;

#[Endpoint('GET', '/me')]
final class WhoAmI
{
    public function __invoke(Consumer $consumer): array
    {
        return [
            'id' => $consumer->id,
            'name' => $consumer->name,
            'method' => $consumer->method->value,
            'plan' => $consumer->plan,
        ];
    }
}
```

The resolver works in any controller, but the consumer is only known for requests routed to an endpoint. Elsewhere a nullable `?Consumer` argument receives `null` and a non-nullable one throws a `LogicException`.

## Scopes

Scopes are required strings such as `orders:read`. They are compiled per endpoint:

1. `#[Scope('a', 'b')]` on the class, repeatable: the union of every scope listed. It **replaces** the group's scopes.
2. Otherwise the group's `scopes` list.
3. Otherwise, with `scopes: auto` on the group, `<resource>:read` for `GET`, `HEAD` and `OPTIONS`, `<resource>:write` for any other method. The resource is the first segment of the endpoint's declared path, after removing the group's `path` when the group matched by path. A path that starts with a placeholder (`/{id}`) or is `/` gets no automatic scope.
4. Otherwise none.

Examples with `scopes: auto` on a group matched by namespace:

| Declaration | Required scope |
| --- | --- |
| `#[Endpoint('GET', '/orders/{id}')]` | `orders:read` |
| `#[Endpoint('POST', '/orders')]` | `orders:write` |
| `#[Endpoint('DELETE', '/orders/{id}/lines/{line}')]` | `orders:write` |
| `#[Endpoint('GET', '/{slug}')]` | none |
| `#[Endpoint('GET', '/orders')]` with `#[Scope('reports')]` | `reports` only |

For a group declared with `path: /backoffice`, `#[Endpoint('GET', '/backoffice/tickets')]` requires `tickets:read`.

How scopes are enforced:

- `api_key` and `jwt` consumers must carry **every** required scope, otherwise the call answers 403.
- `user` consumers are never refused for scopes: authorize users with Symfony's `#[IsGranted]` or voters.
- An anonymous call to an endpoint that requires scopes answers 401 with the challenges of the other accepted methods (`Authentication is required: this endpoint requires the scope "…".`): an endpoint accepting `[api_key, anonymous]` with a scope is open to nobody without a key holding it. Before 0.24.0, such calls went through.
- An endpoint whose only method is `anonymous` (including `#[PublicEndpoint]`) has no scopes.

## API keys

### Format

```
tb_live_qi9ywjy0lp8x_Xk3vP9wQz2LmN8rT5yB1cD4fG7hJ0kLs
└─┬───┘ └────┬─────┘ └──────────────┬──────────────┘
 prefix     id (12)            secret (32)
```

| Part | Content |
| --- | --- |
| prefix | `tb_live_` or `tb_test_` |
| id | 12 characters from `a-z0-9`, public, used to look the key up |
| secret | 32 characters from `A-Za-z0-9`, generated with `random_int()` |

Only `hash('sha256', <secret>)` is kept, as 64 hexadecimal characters, and compared with `hash_equals()`. The full key is shown once, when it is generated or rotated, and cannot be recovered. The `tb_test_` prefix is a label for the operator (the dashboard shows it); both prefixes authenticate the same way. The format is part of the public API.

### Generating a key

```
bin/console tollbooth:api-key:generate acme-erp --scope=orders:read --scope=orders:write --plan=pro
```

| Argument or option | Meaning |
| --- | --- |
| `name` | Consumer name, for instance `acme-erp`. |
| `--scope=<scope>` | Scope granted, repeatable. |
| `--plan=<plan>` | Rate limit plan. Not checked against the existing plans. |
| `--test` | Generate a `tb_test_` key instead of a `tb_live_` one. |
| `--store` | Save the key with the key provider that manages keys (the `doctrine_keys` table, or your own `ManageableApiKeyProviderInterface` provider) instead of printing its configuration. Fails with exit code 1 when no provider manages keys, when the name is taken (compared without case) or when `--plan` names no existing plan. |

Without `--store`, the command prints the key and the configuration to paste:

```yaml
tollbooth:
    auth:
        api_keys:
            acme-erp:
                id: qi9ywjy0lp8x
                hash: 8395c743d4b6c0a1b4a2d8a6e3c5f0e9a7b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5
                scopes: [orders:read, orders:write]
                plan: pro
```

The command has no option for the expiry or the allowed addresses: add `expires_at` and `allowed_ips` by hand, or set them from the dashboard for stored keys.

### Configured keys

```yaml
tollbooth:
    auth:
        api_key_header: X-Api-Key
        api_keys:
            acme-erp:
                id: qi9ywjy0lp8x
                hash: 8395c743d4b6c0a1b4a2d8a6e3c5f0e9a7b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5
                scopes: [orders:read, orders:write]
                plan: pro
                expires_at: '2027-03-02'
                allowed_ips: [203.0.113.0/24, '2001:db8::/32']
                test: false
```

| Key | Meaning |
| --- | --- |
| `<name>` | Consumer name, shown in monitoring and the dashboard. Dashes are kept. |
| `id`, `hash` | Required, non-empty. |
| `scopes` | Scopes granted. |
| `plan` | Rate limit plan; unknown or `null` falls back to `rate_limit.default_plan`. |
| `expires_at` | Parsed with `new \DateTimeImmutable()`. A date alone means midnight at the start of that day, in PHP's default timezone. The key is refused from that instant (`expires_at <= now`). An unparsable value makes every key lookup throw, and API key calls then fail with a 500. |
| `allowed_ips` | Addresses or CIDR ranges, IPv4 or IPv6, checked with `IpUtils::checkIp()` against `Request::getClientIp()`. Empty allows any address. |

Configured keys are read-only: the dashboard lists them but cannot rotate, re-plan or revoke them. Keep secrets out of the repository by putting the hashes in environment variables if you prefer (`hash: '%env(ACME_KEY_HASH)%'`); the hash alone does not allow calling the API.

### Expiry and allowed addresses

Checks run in this order, each answering 401 with its own detail:

| Check | Detail |
| --- | --- |
| The header value does not match `tb_(live|test)_<id>_<secret>`, the id is unknown, the secret does not match the hash, or the prefix does not match the key (`tb_live_` for a key declared with `test: true`, or the reverse) | `The API key is not valid.` |
| `expires_at` is in the past | `The API key has expired.` |
| `allowed_ips` is not empty and does not contain the client IP | `The API key is not allowed from this address.` |

Time comes from the `clock` service when one is registered (Symfony's `ClockInterface`), so tests can freeze it.

## Key providers

Tollbooth looks keys up through interfaces that the application can implement.

| Interface | Methods | Used for |
| --- | --- | --- |
| `Tollbooth\Auth\ApiKey\ApiKeyProviderInterface` | `find(string $id): ?ApiKey` | Authentication. The only method a custom provider must have. |
| `Tollbooth\Auth\ApiKey\ListableApiKeyProviderInterface` | adds `all(): iterable<ApiKey>` | The expiry findings of the health report, the plans' key counts and the consumer list on the dashboard. A provider that is not listable gets none of these. |
| `Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface` | adds `save(ApiKey $key): void`, `remove(string $id): void`, `manages(string $id): bool` | Creating, rotating, re-planning and revoking keys from the dashboard and with `generate --store`. `manages()` says whether this provider can change a given key. |

`Tollbooth\Auth\ApiKey\ApiKey` is the value object they exchange:

| Property | Type | Meaning |
| --- | --- | --- |
| `id` | `string` | Key id, the consumer id. |
| `name` | `string` | Consumer name. |
| `hash` | `string` | SHA-256 hex digest of the secret. |
| `scopes` | `list<string>` | Scopes granted. Default `[]`. |
| `plan` | `?string` | Rate limit plan. Default `null`. |
| `expiresAt` | `?\DateTimeImmutable` | Expiry. Default `null`. |
| `allowedIps` | `list<string>` | Allowed addresses or ranges. Default `[]`. |
| `createdAt` | `?\DateTimeImmutable` | Creation time, shown on the dashboard. Default `null`. |
| `test` | `bool` | Whether it was generated as a `tb_test_` key. Default `false`. |

`isExpired(\DateTimeImmutable $now): bool` returns true when `expiresAt` is set and not after `$now`.

### A custom provider

Set `auth.api_key_provider` to the service id of your implementation. The secret check, expiry and address checks stay with Tollbooth; your provider only finds keys by id.

```php
namespace App\Security;

use Doctrine\DBAL\Connection;
use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Auth\ApiKey\ListableApiKeyProviderInterface;

final readonly class PartnerKeyProvider implements ListableApiKeyProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $id): ?ApiKey
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM partner_key WHERE key_id = ?', [$id]);

        return false === $row ? null : $this->toKey($row);
    }

    public function all(): iterable
    {
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM partner_key') as $row) {
            yield $this->toKey($row);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toKey(array $row): ApiKey
    {
        return new ApiKey(
            id: (string) $row['key_id'],
            name: (string) $row['partner_name'],
            hash: (string) $row['secret_sha256'],
            scopes: explode(' ', (string) $row['scopes']),
            plan: null === $row['plan'] ? null : (string) $row['plan'],
            expiresAt: null === $row['expires_at'] ? null : new \DateTimeImmutable((string) $row['expires_at']),
        );
    }
}
```

```yaml
tollbooth:
    auth:
        api_key_provider: App\Security\PartnerKeyProvider
```

`find()` runs on every call carrying an API key, so keep it to one indexed lookup. To store keys you generate yourself, use `Tollbooth\Auth\ApiKey\ApiKeyFormat::generate()`, which returns `['key' => …, 'id' => …, 'hash' => …]`, or hash an existing secret with `ApiKeyFormat::hash()`. `ApiKeyFormat` is not listed in the public API; only the key format itself is.

When `api_key_provider` is set, it replaces the `api_keys` lookup entirely: keys under `api_keys` are no longer accepted unless your provider returns them.

### Which provider answers

| Configuration | Authentication uses | Dashboard and `generate --store` use |
| --- | --- | --- |
| default | the `api_keys` keys | nothing: keys cannot be managed |
| `doctrine_keys` on | a chain: `api_keys` first, then the table | the same chain |
| `api_key_provider` set | your provider | your provider when it implements `ManageableApiKeyProviderInterface`, nothing otherwise |

Setting both `api_key_provider` and `doctrine_keys` stops the build: keys saved in the table would never authenticate. Read the table from your provider instead.

## Stored keys

With Doctrine DBAL, keys can also live in a table, where the dashboard creates, rotates, re-plans and revokes them without a deployment.

```yaml
tollbooth:
    auth:
        doctrine_keys:
            enabled: true
            connection: default      # DBAL connection name, or a service id containing a dot
            table_prefix: tollbooth_
```

Create or update the table once (and after upgrades that change it):

```
bin/console tollbooth:api-key:setup              # runs the SQL
bin/console tollbooth:api-key:setup --dump-sql   # prints it, to put in a migration
```

The table `<prefix>api_key`:

| Column | Type | Content |
| --- | --- | --- |
| `id` | string(32), primary key | key id |
| `name` | string(190), unique | consumer name |
| `hash` | string(128) | SHA-256 of the secret |
| `scopes` | text | JSON list |
| `plan` | string(64), nullable | plan |
| `expires_at` | bigint, nullable | Unix timestamp |
| `allowed_ips` | text | JSON list |
| `created_at` | bigint | Unix timestamp |
| `test` | smallint | 1 for a `tb_test_` key |

Then generate keys straight into it:

```
bin/console tollbooth:api-key:generate acme-erp --scope=orders:read --plan=pro --store
```

Behaviour:

- The configured `api_keys` are looked up first and stay read-only: saving or removing a key whose id is declared in configuration throws a `LogicException`.
- Names are unique, compared without case: `generate --store` and the dashboard form refuse a taken name, and the command refuses a plan that does not exist.
- Revoking deletes the row: the key stops working on the next lookup.
- Rotating keeps the id and replaces the hash: the old secret stops working at once.
- Key lookups are memoized within a request and forgotten between requests (the store is reset with the kernel), so a long-running worker (FrankenPHP worker mode, RoadRunner) sees a revocation made by another process from its next request.
- If the table cannot be read, calls with a key that is only in the table fail (the lookup throws), and the health check `api_key_storage` turns critical.

Managing keys from the dashboard needs `monitoring` enabled too, because the key screens are part of the consumers screen. See [dashboard.md](dashboard.md).

## JWT and user: the firewall

`jwt` and `user` delegate authentication to Symfony Security. Tollbooth only reads the token the firewall stored, so:

- install `symfony/security-bundle` (the build fails without it when an endpoint accepts these methods);
- put a firewall whose `pattern` covers the endpoints' paths (`path_prefix` and group prefixes included). Tollbooth does not check this: without a firewall, no user is ever found and those calls answer 401;
- do not add `access_control` rules requiring a role on API paths that accept `api_key` or `anonymous`: the firewall would refuse those calls before Tollbooth sees them;
- invalid credentials are refused by the firewall itself, with its own response rather than a Tollbooth problem document. Missing credentials reach Tollbooth, which answers 401 with problem details.

### JWT

Use Symfony's `access_token` authenticator. For tokens issued by an OpenID Connect provider, its built-in `oidc` token handler validates the signature and creates an `OidcUser` carrying the claims, from which Tollbooth reads `client_id`/`azp` and `scope`/`scp` (see the [Symfony documentation on access tokens](https://symfony.com/doc/current/security/access_token.html)). A token handler of your own works the same way as long as it returns an `OidcUser`:

```php
namespace App\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\OidcUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final readonly class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private TokenRegistry $tokens)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $claims = $this->tokens->claims($accessToken) ?? throw new BadCredentialsException('Unknown token.');

        return new UserBadge($claims['client_id'], static fn (): OidcUser => new OidcUser(
            userIdentifier: $claims['client_id'],
            sub: $claims['client_id'],
            name: $claims['name'],
            client_id: $claims['client_id'],
            scope: $claims['scope'],
        ));
    }
}
```

`TokenRegistry` stands for your own token validation. The security configuration, where `app_users` is any user provider your application declares:

```yaml
security:
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            provider: app_users
            access_token:
                token_handler: App\Security\ApiTokenHandler
```

If the handler returns another user class, the consumer is `jwt` on endpoints that accept `jwt` (the request carries `Authorization: Bearer`), but it has no scopes, so every endpoint requiring a scope answers 403 to it.

### User

Any authenticator of the firewall works: form login with a session, HTTP Basic, remember-me. For a session shared with the site's login, give both firewalls the same `context`:

```yaml
security:
    firewalls:
        api_session:
            pattern: ^/api/admin
            context: shop
            provider: app_users
        main:
            context: shop
            lazy: true
            provider: app_users
            form_login:
                login_path: /login
                check_path: /login
```

```yaml
tollbooth:
    groups:
        admin:
            namespace: App\Api\Admin
            prefix: /admin
            auth: [user]
```

The consumer id and name are the user identifier, and the consumer has no plan, so `rate_limit.default_plan` applies. Scopes are not checked for users.

## Error responses

Every error is `application/problem+json` (see [http-contract.md](http-contract.md)).

| Status | When | `detail` | Headers |
| --- | --- | --- | --- |
| 401 | No accepted credentials | `Authentication is required to access this endpoint.` | `WWW-Authenticate` |
| 401 | API key malformed, unknown or wrong | `The API key is not valid.` | `WWW-Authenticate: ApiKey header="X-Api-Key"` |
| 401 | API key expired | `The API key has expired.` | same |
| 401 | API key used from an address outside `allowed_ips` | `The API key is not allowed from this address.` | same |
| 403 | Scopes missing | `This endpoint requires the scope "orders:write".` or `This endpoint requires the scopes "orders:read", "orders:write".` | none |

The `WWW-Authenticate` challenge of the first row lists one challenge per accepted method, comma-separated, in the order the methods are declared: `ApiKey header="<api_key_header>"` for `api_key` and `Bearer` for `jwt`. When neither is accepted, the challenge is `Session` for an endpoint that accepts `user`, `Bearer` otherwise.

```
HTTP/1.1 401 Unauthorized
Content-Type: application/problem+json
WWW-Authenticate: ApiKey header="X-Api-Key", Bearer

{
    "type": "about:blank",
    "title": "Unauthorized",
    "status": 401,
    "detail": "Authentication is required to access this endpoint."
}
```

## Health checks and findings

| Name | Kind | Registered when | Result |
| --- | --- | --- | --- |
| `api_key_storage` | check | `auth.doctrine_keys` on | Critical when the table cannot be read; warning when it is out of date (run `tollbooth:api-key:setup`); ok with the number of stored keys. |
| API key expiry | finding | always, if the provider is listable | Info for each expired key (`The API key of "acme-erp" expired on 2026-10-01 and is refused.`); warning for each key expiring within `health.key_expiry_days` days. |

See [health.md](health.md).
