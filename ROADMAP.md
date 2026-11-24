# Roadmap

What Tollbooth does today, what is being built and what comes next. The dashboard follows the
[mockup](https://claude.ai/artifact/G78sXfUVM7Rj5CMDDp5GQy), screen by screen.

## Done

### 0.1 · Core
- [x] `#[Endpoint]` on invokable services, metadata compiled into the container
- [x] `tollbooth` route loader, `path_prefix`
- [x] Route parameters bound by name, `int` and backed enum requirements
- [x] `#[Query]` typed query parameters
- [x] `#[Body]` JSON body hydration and validation
- [x] JSON responses with the declared status, `204` for `null`
- [x] RFC 9457 problem details, violations on 422, server errors hidden outside debug

### 0.2 · Output conventions
- [x] Envelopes: `plain`, `data`, `json_api` (resource objects, collections, `meta` fallback)
- [x] Field name case both ways (`preserve`, `snake_case`, `camel_case`) through Tollbooth's named serializers
- [x] Type errors in bodies as 422 violations, 415 for non-JSON bodies
- [x] Router 404 and 405 under the prefix as problem details

### 0.3 · Groups
- [x] Groups matched by namespace, by path or by name on the attribute
- [x] Mount prefix and output conventions per group
- [x] Endpoint names unique per group

### 0.4 · Consumers and authentication
- [x] `Consumer` resolved for every call, injectable into endpoints
- [x] Methods: `api_key`, `jwt`, `user`, `anonymous`
- [x] `#[Auth]`, `#[Scope]`, `#[PublicEndpoint]`, group `auth` and `scopes`, `scopes: auto`
- [x] API keys: `tb_live_`/`tb_test_` format, hashed, expiry, allowed IPs, in-memory provider,
      `ApiKeyProviderInterface`, `tollbooth:api-key:generate`
- [x] 401 with challenges, 403 naming the missing scope

## Next

### 0.5 · Rate limiting
- [ ] Plans (`free`, `pro`, …) with per-minute and per-day limits, on symfony/rate-limiter
- [ ] Limits keyed on the consumer, per endpoint, per group and per plan
- [ ] `RateLimit-Policy` and `RateLimit` headers on every limited response, not only 429s
- [ ] 429 with `Retry-After` and a `limiters` list in the problem body
- [ ] Optional legacy `X-RateLimit-*` headers

### 0.6 · Documentation
- [ ] OpenAPI 3.1 generated from the compiled metadata, one document per group
- [ ] `securitySchemes` from the accepted auth methods, scopes listed
- [ ] Documentation UI and `tollbooth:openapi:export`
- [ ] Test helper asserting that a response matches its declared schema

### 0.7 · Monitoring
- [ ] Call collection at `kernel.terminate`: endpoint, status, duration, consumer, size, error
- [ ] Time spent in each pipeline step
- [ ] Storage adapters: Doctrine first, then Redis, null and OpenTelemetry export
- [ ] Retention and purge
- [ ] Request bodies never stored by default, secrets redacted

### 0.8 · Health
- [ ] Tollbooth checks: metadata cache, OpenAPI validity, limiter storage, collector lag, purge, dashboard access
- [ ] `HealthCheckInterface` for the host application's own checks
- [ ] Findings: deprecated endpoints still called, unused endpoints, keys about to expire
- [ ] `/_tollbooth/health/live` and `/ready` probes

### 0.9 · Changes
- [ ] Contract snapshot per release
- [ ] Diff between two snapshots: breaking, risky, added, changed, deprecated, with impact from real traffic
- [ ] `tollbooth:diff --fail-on=breaking` for CI and a consumer-facing changelog
- [ ] `Deprecation` and `Sunset` headers

### 1.0 · Dashboard
Twig, rendered on the server, assets shipped prebuilt, every string translated (`en`, `fr`).
- [ ] Map: resources, traffic overlay, model references, "needs attention"
- [ ] Endpoint: pipeline, declaration, body, responses, latency, status codes, consumers, errors
- [ ] Models: diagram, usage, serialized preview per convention
- [ ] Requests: live tail, filters, call detail with limiter state
- [ ] Consumers and a consumer page: quotas, minute limiter, endpoints used, upcoming changes, key
- [ ] Changes and Health screens
- [ ] API key management from the UI, with a Doctrine key provider

## Later
- [ ] HMAC request signatures and mTLS consumers
- [ ] JSON:API relationships and `included`
- [ ] Pagination conventions (offset, cursor, `Link` header)
- [ ] Idempotency keys, ETag and 304
- [ ] Public documentation portal where each consumer follows its own usage and quota
- [ ] Alerts through Symfony Notifier
- [ ] Benchmarks with phpbench and an overhead budget
