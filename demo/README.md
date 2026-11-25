# Tollbooth demo

A shop backend exposing six APIs through Tollbooth, on PostgreSQL, with realistic traffic. It exists to try every feature by hand and to develop the dashboard against real data. It is not part of the Packagist package.

## Run it

```
docker compose up -d postgres
docker compose run --rm php php demo/bin/console demo:setup    # shop tables and fixtures, monitoring tables
docker compose run --rm php php demo/bin/console demo:seed     # 24 h of traffic history, contract snapshots
docker compose up -d demo                                       # http://localhost:8080
docker compose run --rm php php demo/bin/console demo:traffic  # live calls, Ctrl+C to stop
```

`demo:setup` resets the shop database. `demo:seed` replaces the monitoring data.

## The APIs

| Group | Prefix | Matched by | Conventions | Auth | Shows |
| --- | --- | --- | --- | --- | --- |
| shop | `/api/v2` | namespace | JSON:API, snake_case | API key, public catalog | automatic scopes, int-backed enum, enum in path, float and bool queries, slow and failing payment, endpoint limits |
| partner | `/api/partner` | namespace | plain, snake_case | API key | group limit of 10000/hour, nested lists in bodies, 202, `imports` limit |
| mobile | `/api/mobile` | namespace | `data`, camelCase | API key or user | session users, PUT, per-consumer data |
| admin | `/api/admin` | namespace | plain, camelCase | user | session or HTTP Basic, reports with enum and dates |
| legacy | `/api/v1` | namespace | plain | API key | deprecation with a near and a far sunset, and without |
| backoffice | `/api/backoffice` | path | plain, camelCase | JWT | access token with scopes in the claims |

Documentation: http://localhost:8080/_tollbooth/docs/shop (public). Health report: http://localhost:8080/_tollbooth/health.json (admin).

## Credentials

| Who | How |
| --- | --- |
| admin | log in at `/login` with `admin` / `admin`, or HTTP Basic |
| ada | `ada` / `ada`, not an admin |
| mobile-app | `X-Api-Key: tb_live_mobileapp001_mobileappDemo2026mobileappDemo20` |
| web-front | `X-Api-Key: tb_live_webfront0001_webfrontDemo2026webfrontDemo2026` |
| acme-erp | `X-Api-Key: tb_live_acmeerp00001_acmeerpDemo2026acmeerpDemo2026ac` |
| legacy-ios, shopify-sync, zapier-hook, sandbox-test, old-integration | printed by `demo:seed` |
| support desk | `Authorization: Bearer support-desk-token` |
| reporting bot | `Authorization: Bearer reporting-bot-token` (read only) |

## Try

```
curl http://localhost:8080/api/v2/products/p_2
curl -H "X-Api-Key: tb_live_webfront0001_webfrontDemo2026webfrontDemo2026" -X POST http://localhost:8080/api/v2/carts
curl -u admin:admin http://localhost:8080/api/admin/stats
curl -H "Authorization: Bearer support-desk-token" http://localhost:8080/api/backoffice/tickets
docker compose run --rm php php demo/bin/console tollbooth:diff 2.4.0 --fail-on=none
docker compose run --rm php php demo/bin/console tollbooth:health
```
