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

### 0.5 · Rate limiting
- [x] Plans made of any number of limits (`10 seconds`, `minute`, `day`…), keyed on the consumer
- [x] Group `rate_limits` and endpoint `#[RateLimit]` on top of the plan
- [x] Clock-aligned fixed windows with an exact reset time, refused calls not counted elsewhere
- [x] `RateLimit-Policy` and `RateLimit` headers on every limited response, not only 429s
- [x] 429 with `Retry-After` and a `limiters` list in the problem body
- [x] Optional legacy `X-RateLimit-*` headers

### 0.6 · Documentation
- [x] OpenAPI 3.1 generated from the compiled metadata, one document per group
- [x] Schemas from PHP types, docblocks and validation constraints, in the group's case and envelope
- [x] Errors per operation, `securitySchemes` from the accepted auth methods, scopes listed
- [x] Rate limit headers and endpoint limits in the document
- [x] Documentation page (Scalar) and `tollbooth:openapi:export`

### 0.7 · Monitoring
- [x] Call collection at `kernel.terminate`: endpoint, status, duration, consumer, IP, size, error
- [x] Time spent in each pipeline step
- [x] Doctrine DBAL storage, the application's connection by default or a dedicated one
- [x] Append-only writes, per-minute rollup with a latency histogram, raw reads for the current minutes
- [x] Retention and purge, automatic or through `tollbooth:monitoring:maintain`
- [x] No bodies, headers or query strings stored; server errors keep their class only
- [x] `CallReaderInterface` for the dashboard

### 0.8 · Health
- [x] Tollbooth checks: compiled metadata, limiter storage, monitoring tables, aggregation and purge lag
- [x] `HealthCheckInterface` and `FindingProviderInterface` for the application, autoconfigured
- [x] Findings: error rates, unused endpoints, keys expired or expiring, pages outside any firewall
- [x] `/live` and `/ready` probes, details behind a token, full report and `tollbooth:health`

### 0.9 · Changes
- [x] Contract snapshot per release (the OpenAPI documents of every group)
- [x] Diff aware of direction: breaking, risky, added, changed, deprecated, with impact from real traffic
- [x] `tollbooth:diff --fail-on=breaking` for CI, next version, consumer changelog in Markdown
- [x] `#[DeprecatedEndpoint]` with `Deprecation`, `Sunset` and `Link` headers, optional 410 after sunset
- [x] Health finding: deprecated endpoints still called, and by whom

### Demo application
- [x] Shop backend with six APIs and sixty endpoints on PostgreSQL, login, 24 h of seeded traffic, live traffic command

## Next

### 1.0 · Dashboard (in progress)
Twig, rendered on the server, assets shipped prebuilt, every string translated (`en`, `fr`).
- [x] Map: resources, traffic overlay, model references, "needs attention", Mermaid export
- [x] Endpoint: pipeline, declaration, body, responses, examples, latency, status codes, consumers, errors
- [x] Models: diagram, usage, serialized preview per convention, SVG export
- [x] Requests: live tail, filters, call detail with limiter state, NDJSON export
- [x] Consumers and a consumer page: quotas, minute limiter, endpoints used, upcoming changes, key
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
- [ ] Token bucket and sliding window policies, `#[NoRateLimit]` to exempt an endpoint
- [ ] Test helper asserting that a response matches its declared schema
- [ ] Descriptions and examples on endpoints and fields, OpenAPI linting in CI
- [ ] Documentation page assets shipped in the bundle instead of loaded from a CDN
- [ ] Monitoring adapters: ClickHouse or TimescaleDB, OpenTelemetry export; sampling of raw calls
- [ ] Opt-in storage of request headers (secrets redacted) and bodies per endpoint
