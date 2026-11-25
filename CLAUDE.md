# Tollbooth

Symfony bundle that turns attribute-declared endpoints into a complete API: routing, hydration and validation, output conventions, OpenAPI documentation, rate limiting, call monitoring, health checks and an operator dashboard with API diagrams.

Dashboard mockup (reference for every screen): https://claude.ai/artifact/G78sXfUVM7Rj5CMDDp5GQy

## Workflow

- One feature or fix per branch, cut from `master`: `feature/<name>`, `fix/<name>`. Never commit feature work on `master`.
- A branch is merged into `master` with `git merge --no-ff` once the feature is complete and `composer qa` is green.
- The branch fills the `[Unreleased]` section of `CHANGELOG.md` as it goes. At merge time, the release commit on `master` turns `[Unreleased]` into the new version with its date, then the version is tagged `vX.Y.Z`.
- Commit messages in English, imperative mood, one concern per commit.
- Before calling a dashboard screen done, compare it with its board in the mockup, screen by screen, with screenshots.

## Running things

PHP is not installed on the host: everything runs in Docker. Docker Desktop is started by the user.

`vendor/` lives in a Docker volume, not on the Windows disk, and the test kernel, PHPStan, php-cs-fixer and OPcache caches live in the `cache` volume: going through the Windows bind mount made `composer qa` take 75 s instead of 3 s. Dependencies are therefore installed with `docker compose run --rm php composer install`, never from the host.

```
docker compose run --rm php composer install
docker compose run --rm php composer test      # PHPUnit
docker compose run --rm php composer stan      # PHPStan, level max
docker compose run --rm php composer cs        # php-cs-fixer dry run
docker compose run --rm php composer cs-fix
docker compose run --rm php composer deptrac   # module boundaries
docker compose run --rm php composer qa        # all of the above
```

## Architecture

Modules live side by side under `src/`, one namespace each:

| Module | Role |
| --- | --- |
| `Core` | Endpoint attributes (auth and scope declarations included), groups, compiled metadata, routing, request hydration, responses, output conventions, problem details |
| `Auth` | Consumers, API keys, reading the Symfony token for JWT and session users, access checks |
| `Docs` | OpenAPI generation and the documentation UI |
| `RateLimit` | Plans, clock-aligned window counters, rate limit headers, 429 |
| `Monitoring` | Call collection, storage adapters, aggregation |
| `Health` | Runs the checks and findings, probes, report. The contract lives in `Core\Health`, and each module ships the checks of its own parts |
| `Changes` | Contract snapshots per release and their diff |
| `Dashboard` | Server-rendered operator UI |

- `ROADMAP.md` lists what is done, in progress and planned. Update it in the branch that delivers a feature.
- Dependencies point one way: every module may depend on `Core`, `Dashboard` may depend on every module, nothing depends on `Dashboard`, and feature modules do not depend on each other except through `Core` interfaces, or on `Auth` for the `Consumer` that rate limiting and monitoring key on. `Changes` compares the documents `Docs` produces and measures impact with `Monitoring`. `deptrac.yaml` enforces it.
- Inside a module, keep layers apart: public API (attributes, interfaces, value objects), compile time (metadata extraction, compiler passes, cache warmers), runtime (listeners, controllers, services) and infrastructure (storage adapters).
- Reflection happens at compile time only. The request path reads compiled metadata.
- Every module except `Core` can be disabled in `tollbooth.*` config. A disabled module registers no service.
- Heavy dependencies (Doctrine, Redis, OpenTelemetry) stay in `suggest`, never in `require`. Adapters are registered only when their library is installed.
- Prefer Symfony components (HttpKernel argument resolvers, Serializer, Validator, RateLimiter, Messenger) over custom infrastructure.
- Listeners and controllers stay thin. Logic lives in small services named after their intent. Constructor injection, `final` classes, `readonly` wherever possible.

## Public API and versioning

The package follows Semantic Versioning 2.0.0. The public API is listed in the *Public API* section of `README.md`. In short:

- attributes, interfaces and value objects meant for host code;
- `tollbooth.*` configuration keys;
- the HTTP contract produced for API clients (status codes, headers, problem details shape, envelopes);
- console command names and options.

Everything else is internal and marked `@internal`; it may change in any release.

Breaking-change policy:

- Removing or renaming a public attribute, interface, method, config key or command option is MAJOR.
- Adding a method to a public interface that hosts implement is MAJOR, unless it ships with a default implementation.
- Changing the HTTP contract that API clients see (header names, problem shape, envelope layout) is MAJOR.
- New attributes, interfaces, optional config keys, commands and options are MINOR.
- Fixes, performance work and refactors that keep the public API are PATCH.
- While the version is `0.x`, a MINOR may break; the changelog then says so under *Upgrade notes*.

## Changelog

`CHANGELOG.md` follows Keep a Changelog 1.1.0: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`, plus `Upgrade notes` whenever the host application has something to do. Write each entry for the host developer, in full sentences: what changed, what it fixes or allows, and what they must do.

## Code style

- PHP 8.4, `declare(strict_types=1)`, constructor promotion, enums, `readonly`.
- Everything in English: code, config keys, translation keys, commits.
- No comments. PHPDoc only when it carries information PHP types cannot express: generics, array shapes, `@internal`.
- php-cs-fixer with the `@Symfony` rule set, PHPStan at level max.

## Dashboard

- Twig, rendered on the server. Small vanilla JS modules only where a screen needs it (diagram layout, live tail). Assets ship prebuilt in the bundle: the host has no build step.
- Every visible string goes through translations (`en`, `fr`), with stable keys grouped by screen: `dashboard.map.title`.
- Routes live under `/_tollbooth`. Protecting them is the host firewall's job; the README documents it and the Health module checks it.

## HTTP contract

- Errors are RFC 9457 `application/problem+json`.
- Every response of a rate-limited endpoint carries `RateLimit-Policy` and `RateLimit` headers, not only 429s. A 429 adds `Retry-After` and a `limiters` list in its problem body. Legacy `X-RateLimit-*` headers are opt-in.
- Request bodies are never stored by default. Secrets in headers are redacted before anything is stored.
- API keys are stored hashed and shown once.

## Tests

- Unit tests for code that carries logic: metadata extraction, conventions, header formatting, diff computation, aggregation. No tests for getters or trivial wiring.
- Functional tests through a test kernel for the HTTP contract, since it is public API.
- Layout: `tests/Unit`, `tests/Functional`, `tests/Fixtures`.

## Performance

The selling point is a light request path. No reflection, no metadata parsing and no container lookups by string on the hot path. Benchmarks with phpbench will guard an overhead budget once the core is in place.
