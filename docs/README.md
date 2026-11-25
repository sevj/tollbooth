# Tollbooth documentation

Tollbooth is a Symfony bundle that turns attribute-declared endpoints into a complete API: routing, hydration and validation, output conventions, OpenAPI documentation, rate limiting, call monitoring, health checks and an operator dashboard. This folder documents every part in detail. The [project README](../README.md) is the short overview.

If you are a coding agent integrating Tollbooth into an application, start with [agents.md](agents.md): it lists the rules to follow and where each detail lives.

## Start here

| Document | Read it to |
| --- | --- |
| [Getting started](getting-started.md) | Install the bundle, declare a first endpoint, call it, see it documented and monitored |
| [For coding agents](agents.md) | Follow the conventions and checks that keep an integration correct |

## Reference

| Document | Covers |
| --- | --- |
| [Endpoints](endpoints.md) | Endpoint classes, every attribute and its parameters, inputs, validation, return values, envelopes, field cases, groups |
| [HTTP contract](http-contract.md) | What API clients receive: status codes, problem details, envelopes, rate limit and deprecation headers, content types |
| [Configuration](configuration.md) | Every `tollbooth.*` key with its type and default, build-time validation, console commands |
| [Authentication](authentication.md) | Authentication methods, consumers, scopes, API keys and their providers, stored keys |
| [Rate limiting](rate-limiting.md) | Plans and limits, windows, headers, 429, plans stored in the database |
| [Monitoring](monitoring.md) | What each call records, storage, retention and rollups, reading the data |
| [Health](health.md) | Probes, the report, built-in checks and findings, writing your own |
| [OpenAPI](openapi.md) | The generated documents and UI, how schemas are derived, export |
| [Contract changes](contract-changes.md) | Snapshots, the diff and its classification, impact, deprecation |
| [Dashboard](dashboard.md) | Every screen of the operator dashboard, what each needs, securing it |

## Guides

| Guide | For |
| --- | --- |
| [Migrating Symfony controllers](guides/migrating-symfony-controllers.md) | Moving an existing JSON API onto Tollbooth without breaking its clients |
| [Deprecating an API version](guides/deprecating-an-api-version.md) | Announcing, following and removing old endpoints |
| [Several servers](guides/multiple-servers.md) | What must be shared when more than one server answers |
| [Extending Tollbooth](guides/extending.md) | Key providers, health checks, findings, problem members, monitoring readers |

## Versions

Tollbooth follows Semantic Versioning. What counts as public API is listed in the [README](../README.md#public-api); changes are recorded in the [CHANGELOG](../CHANGELOG.md). While the version is `0.x`, a minor release may break, and its changelog then has *Upgrade notes*.
