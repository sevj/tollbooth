<?php

declare(strict_types=1);

namespace Tollbooth\Core\Problem;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Tollbooth\Core\Output\NameCase;

/**
 * @internal
 */
final readonly class ProblemFactory
{
    public function __construct(
        private bool $debug = false,
    ) {
    }

    public function fromThrowable(\Throwable $throwable, NameCase $case = NameCase::Preserve): Problem
    {
        $status = match (true) {
            $throwable instanceof HttpExceptionInterface => $throwable->getStatusCode(),
            $throwable instanceof ValidationFailedException => 422,
            $throwable instanceof AccessDeniedException => 403,
            $throwable instanceof AuthenticationException => 401,
            default => 500,
        };
        $headers = match (true) {
            $throwable instanceof HttpExceptionInterface => $this->headers($throwable->getHeaders()),
            $throwable instanceof AuthenticationException => ['WWW-Authenticate' => 'Session'],
            default => [],
        };
        $violations = $this->violations($throwable, $case);

        return new Problem(
            status: $status,
            title: [] !== $violations ? 'Validation Failed' : (Response::$statusTexts[$status] ?? 'Error'),
            detail: $this->detail($throwable, $status, [] !== $violations),
            violations: $violations,
            headers: $headers,
            members: $throwable instanceof ProblemMembersInterface ? $throwable->problemMembers() : [],
        );
    }

    private function detail(\Throwable $throwable, int $status, bool $hasViolations): ?string
    {
        if ($hasViolations || '' === $throwable->getMessage()) {
            return null;
        }

        if ($status >= 500 && !$this->debug) {
            return null;
        }

        return $throwable->getMessage();
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function violations(\Throwable $throwable, NameCase $case): array
    {
        $validation = $throwable instanceof ValidationFailedException ? $throwable : $throwable->getPrevious();
        if (!$validation instanceof ValidationFailedException) {
            return [];
        }

        $violations = [];
        foreach ($validation->getViolations() as $violation) {
            $violations[] = [
                'field' => $case->applyToPath($violation->getPropertyPath()),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $violations;
    }

    /**
     * @param array<mixed> $headers
     *
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $normalized[$name] = (string) $value;
            }
        }

        return $normalized;
    }
}
