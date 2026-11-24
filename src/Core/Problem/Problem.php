<?php

declare(strict_types=1);

namespace Tollbooth\Core\Problem;

/**
 * @internal
 */
final readonly class Problem
{
    private const array RESERVED = ['type', 'title', 'status', 'detail', 'instance', 'violations'];

    /**
     * @param list<array{field: string, message: string}> $violations
     * @param array<string, string>                       $headers
     * @param array<string, mixed>                        $members
     */
    public function __construct(
        public int $status,
        public string $title,
        public ?string $detail = null,
        public array $violations = [],
        public array $headers = [],
        public string $type = 'about:blank',
        public array $members = [],
    ) {
    }

    public function withoutDetail(): self
    {
        return new self($this->status, $this->title, null, $this->violations, $this->headers, $this->type, $this->members);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $problem = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
        ];

        if (null !== $this->detail) {
            $problem['detail'] = $this->detail;
        }

        if ([] !== $this->violations) {
            $problem['violations'] = $this->violations;
        }

        return $problem + array_diff_key($this->members, array_flip(self::RESERVED));
    }
}
