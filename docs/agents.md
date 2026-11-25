# For coding agents

This page is for AI agents and scripts that add Tollbooth to a Symfony application or write endpoints with it. It lists the rules that keep an integration correct and points to the reference page holding each detail. Read the linked page before relying on a behaviour; do not guess options that are not documented.

## Install

Tollbooth is not on Packagist yet. Declare its GitHub repository, then require it:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/sevj/tollbooth" }
    ]
}
```

```
composer require tollbooth/tollbooth-bundle:^0.23
```

Register `Tollbooth\TollboothBundle` in `config/bundles.php` if Flex did not, and import the routes with the `tollbooth` loader type. Full steps: [getting-started.md](getting-started.md).

## Rules for writing endpoints

1. **One endpoint, one class.** A `final` invokable class with `#[Endpoint(method, path)]` on the class. Several actions in one controller class are not supported: write one class per action. See [endpoints.md](endpoints.md).
2. **Paths are relative.** The declared path is prefixed by `tollbooth.path_prefix` and by the prefix of the endpoint's group. Do not repeat them in `#[Endpoint]`.
3. **Inputs are typed.** Route parameters bind by name; `#[Query]` reads one query parameter into a typed argument (a default or a nullable type makes it optional, otherwise a missing value answers 400); `#[Body]` deserializes and validates the JSON body into a DTO with Symfony Validator constraints. Do not read `$request->toArray()` in new endpoints.
4. **Outputs are typed.** Return an object, a list or an array; Tollbooth serializes it with the group's envelope and field case and documents it in OpenAPI from the PHP types. Use docblock generics such as `list<OrderView>` for lists. Returning a Symfony `Response` bypasses all of this: reserve it for binary downloads and for legacy endpoints that must keep an old format.
5. **Errors are exceptions.** Throw Symfony HTTP exceptions (`NotFoundHttpException`, `AccessDeniedHttpException`, `ConflictHttpException`…); under the API prefix they become RFC 9457 `application/problem+json`. Never build error JSON by hand in new endpoints. The exact shapes are in [http-contract.md](http-contract.md).
6. **Authentication is declared, not coded.** Endpoints require an API key unless the group or `#[Auth(...)]` says otherwise; `#[PublicEndpoint]` opens one endpoint; `#[Scope(...)]` adds required scopes. With the `user` or `jwt` method, the application's Symfony firewall authenticates and Tollbooth reads the consumer from it. Take the caller as a `Tollbooth\Auth\Consumer` argument when the handler needs it. See [authentication.md](authentication.md).
7. **Changing a public contract is a decision.** Renaming a field, removing an endpoint or tightening a rule breaks clients. Deprecate first with `#[DeprecatedEndpoint(since: ..., sunset: ...)]`, ship the replacement, and remove only after the sunset. Run `bin/console tollbooth:diff <snapshot>` before a release. See [contract-changes.md](contract-changes.md) and [guides/deprecating-an-api-version.md](guides/deprecating-an-api-version.md).
8. **Keep handlers thin.** Business logic lives in services injected through the constructor; the endpoint class maps input to a call and the result to a view.

## Rules for configuration

- Every module except Core can be disabled under `tollbooth.*`, and a disabled module registers no service. Optional modules also turn themselves off when their dependency is missing (Twig and Translation for the dashboard, Doctrine DBAL for monitoring and stored keys or plans). Check [configuration.md](configuration.md) before assuming a service exists.
- Never put secrets in endpoint code or logs. API keys are stored hashed and shown once; request bodies are never stored by monitoring.
- `/_tollbooth` (dashboard, health report, docs) is not protected by Tollbooth. Put it behind the application firewall, with a role reserved to operators. See [dashboard.md](dashboard.md).
- With several servers, the rate limit cache pool, the lock factory and the monitoring database must be shared. See [guides/multiple-servers.md](guides/multiple-servers.md).

## Migrating an existing API

Do not rewrite the responses of an API that clients already use. Keep its paths and its format on deprecated endpoints that return the old `Response`, build the new version in its own group with Tollbooth conventions, watch the remaining callers in the dashboard, and remove the old endpoints after the sunset. The full procedure is in [guides/migrating-symfony-controllers.md](guides/migrating-symfony-controllers.md).

## Checks after a change

| Check | Command |
| --- | --- |
| The container builds and endpoints compile | `bin/console cache:clear` |
| Endpoints are registered with the expected paths | `bin/console debug:router \| grep tollbooth_` |
| The contract is what you meant | `bin/console tollbooth:openapi:export <group>` |
| Nothing breaks clients unannounced | `bin/console tollbooth:diff <snapshot> --fail-on=breaking` |
| Storage and probes are healthy | `bin/console tollbooth:health` |

Run the exact commands and options listed in [configuration.md](configuration.md#console-commands); the table above names the usual ones.
