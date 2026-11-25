<?php

declare(strict_types=1);

namespace Tollbooth\Health;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Tollbooth\Core\Health\HealthStatus;

/**
 * @internal
 */
final readonly class HealthController
{
    public function __construct(
        private HealthRunner $runner,
        private ?string $token = null,
    ) {
    }

    public function live(): JsonResponse
    {
        return $this->json(['status' => 'ok'], 200);
    }

    public function ready(Request $request): JsonResponse
    {
        $report = $this->runner->run(withFindings: false);

        return $this->json($report->toArray($this->authorized($request)), HealthStatus::Critical === $report->status ? 503 : 200);
    }

    public function report(): JsonResponse
    {
        return $this->json($this->runner->run()->toArray(), 200);
    }

    private function authorized(Request $request): bool
    {
        if (null === $this->token || '' === $this->token) {
            return false;
        }

        $given = $request->headers->get('Authorization');
        $given = null !== $given && str_starts_with($given, 'Bearer ') ? substr($given, 7) : $request->query->getString('token');

        return hash_equals($this->token, $given);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status): JsonResponse
    {
        return (new JsonResponse(null, $status, ['Cache-Control' => 'no-store']))
            ->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION)
            ->setData($data);
    }
}
