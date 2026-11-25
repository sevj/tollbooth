<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Controller;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Dashboard\GroupCatalog;
use Tollbooth\Dashboard\Requests\CallDetail;
use Tollbooth\Dashboard\Requests\RequestFilter;
use Tollbooth\Dashboard\Shell;
use Tollbooth\Monitoring\CallBreakdownInterface;
use Tollbooth\Monitoring\CallQuery;
use Tollbooth\Monitoring\CallReaderInterface;
use Tollbooth\Monitoring\CallRecord;
use Twig\Environment;

/**
 * @internal
 */
final readonly class RequestsController
{
    private const int PAGE = 50;
    private const int EXPORT_LIMIT = 10000;

    public function __construct(
        private CallReaderInterface $calls,
        private CallBreakdownInterface $breakdown,
        private GroupCatalog $groups,
        private Shell $shell,
        private Environment $twig,
        private string $apiKeyHeader = 'X-Api-Key',
        private ?ClockInterface $clock = null,
    ) {
    }

    public function page(Request $request, string $group): Response
    {
        $this->assertGroup($group);
        $filter = RequestFilter::parse($request->query->getString('q'));
        $query = $this->query($group, $filter, self::PAGE, before: self::id($request->query->get('before')));
        $rows = $this->rows($query, $filter);

        $selectedId = self::id($request->query->get('call')) ?? ($rows[0]->id ?? null);
        $selected = null === $selectedId ? null : ($this->calls->recent(new CallQuery(group: $group, limit: 1, id: $selectedId))[0] ?? null);
        $now = $this->now();
        $count = array_sum($this->breakdown->statuses($now - $filter->seconds(), $now + 60, $this->query($group, $filter, 1)));

        return new Response($this->twig->render('@TollboothDashboard/requests.html.twig', [
            ...$this->shell->context($group, 'requests'),
            'filter' => $filter,
            'rows' => $rows,
            'count' => $count,
            'detail' => null === $selected ? null : new CallDetail($selected, $this->endpoint($group, $selected->endpoint)),
            'endpoints' => $this->groups->endpoints($group),
            'names' => $this->names($group),
            'prefix' => $this->groups->pathPrefix(),
            'curl' => null === $selected ? null : $this->curl($request, $selected, $this->endpoint($group, $selected->endpoint)),
            'older' => \count($rows) >= self::PAGE ? end($rows)->id : null,
        ]), headers: ['Cache-Control' => 'no-store']);
    }

    public function feed(Request $request, string $group): JsonResponse
    {
        $this->assertGroup($group);
        $filter = RequestFilter::parse($request->query->getString('q'));
        $after = self::id($request->query->get('after'));
        $rows = null === $after ? [] : $this->rows($this->query($group, $filter, self::PAGE, after: $after), $filter);

        return new JsonResponse([
            'last' => $rows[0]->id ?? $after,
            'html' => [] === $rows ? '' : $this->twig->render('@TollboothDashboard/_request_rows.html.twig', [
                'rows' => $rows,
                'filter' => $filter,
                'group' => $group,
                'names' => $this->names($group),
                'prefix' => $this->groups->pathPrefix(),
                'selected' => null,
            ]),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function export(Request $request, string $group): StreamedResponse
    {
        $this->assertGroup($group);
        $filter = RequestFilter::parse($request->query->getString('q'));

        return new StreamedResponse(function () use ($group, $filter): void {
            $before = null;
            $sent = 0;
            do {
                $rows = $this->rows($this->query($group, $filter, 500, before: $before), $filter);
                foreach ($rows as $row) {
                    echo json_encode(self::exportRow($row), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION), "\n";
                    ++$sent;
                }
                $before = [] === $rows ? null : end($rows)->id;
            } while (null !== $before && $sent < self::EXPORT_LIMIT);
        }, headers: [
            'Content-Type' => 'application/x-ndjson',
            'Content-Disposition' => \sprintf('attachment; filename="%s-requests.ndjson"', $group),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function exportRow(CallRecord $call): array
    {
        return [
            'id' => $call->id,
            'at' => gmdate('Y-m-d\TH:i:s', intdiv($call->occurredAt, 1000)).\sprintf('.%03dZ', $call->occurredAt % 1000),
            'endpoint' => $call->endpoint,
            'method' => $call->method,
            'path' => $call->path,
            'status' => $call->status,
            'duration_ms' => $call->duration,
            'consumer' => $call->consumerName,
            'consumer_id' => $call->consumerId,
            'auth' => $call->authMethod,
            'ip' => $call->ip,
            'size' => $call->responseSize,
            'error' => $call->error,
            'steps' => $call->steps,
            'limiters' => $call->limiters,
        ];
    }

    private function query(string $group, RequestFilter $filter, int $limit, ?string $before = null, ?string $after = null): CallQuery
    {
        $endpoint = $filter->get('endpoint');

        return new CallQuery(
            endpoint: null === $endpoint ? null : ($this->endpoint($group, $endpoint, true)?->routeName() ?? $endpoint),
            group: $group,
            consumerId: $this->consumerId($group, $filter->get('consumer')),
            statusClass: $filter->statusClass(),
            since: ($this->now() - $filter->seconds()) * 1000,
            before: $before,
            limit: $limit,
            status: $filter->status(),
            minDuration: $filter->minDuration(),
            after: $after,
        );
    }

    /**
     * @return list<CallRecord>
     */
    private function rows(CallQuery $query, RequestFilter $filter): array
    {
        $rows = $this->calls->recent($query);
        if ('' === $filter->text) {
            return $rows;
        }

        $needle = strtolower($filter->text);

        return array_values(array_filter($rows, static fn (CallRecord $call): bool => str_contains(strtolower($call->method.' '.$call->path.' '.($call->error ?? '')), $needle)));
    }

    private function consumerId(string $group, ?string $consumer): ?string
    {
        if (null === $consumer) {
            return null;
        }
        $now = $this->now();
        foreach ($this->calls->totals($now - 30 * 86400, $now + 60, 'consumer', new CallQuery(group: $group)) as $stats) {
            if ($stats->label === $consumer || $stats->key === $consumer) {
                return $stats->key;
            }
        }

        return $consumer;
    }

    private function endpoint(string $group, string $key, bool $byName = false): ?EndpointMetadata
    {
        foreach ($this->groups->endpoints($group) as $endpoint) {
            if (($byName ? $endpoint->name : $endpoint->routeName()) === $key) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function names(string $group): array
    {
        $names = [];
        foreach ($this->groups->endpoints($group) as $endpoint) {
            $names[$endpoint->routeName()] = $endpoint->name;
        }

        return $names;
    }

    private function curl(Request $request, CallRecord $call, ?EndpointMetadata $endpoint): string
    {
        $lines = ['curl -X '.$call->method." '".$request->getSchemeAndHttpHost().$call->path."'"];
        $auth = $endpoint->auth[0] ?? $call->authMethod;
        if ('api_key' === $auth) {
            $lines[] = "  -H '".$this->apiKeyHeader.": tb_live_…'";
        } elseif ('jwt' === $auth) {
            $lines[] = "  -H 'Authorization: Bearer <token>'";
        } elseif ('user' === $auth) {
            $lines[] = "  -u 'user:password'";
        }

        return implode(" \\\n", $lines);
    }

    private function assertGroup(string $group): void
    {
        if (!$this->groups->has($group)) {
            throw new NotFoundHttpException(\sprintf('No endpoint belongs to the group "%s".', $group));
        }
    }

    private function now(): int
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
    }

    private static function id(mixed $value): ?string
    {
        return \is_string($value) && 1 === preg_match('/^[0-9a-f]{12,40}$/', $value) ? $value : null;
    }
}
