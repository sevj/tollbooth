<?php

declare(strict_types=1);

namespace Tollbooth\Core\Problem;

/**
 * @internal
 */
final readonly class Problem
{
    /**
     * @param list<array{field: string, message: string}> $violations
     * @param array<string, string>                       $headers
     */
    public function __construct(
        public int $status,
        public string $title,
        public ?string $detail = null,
        public array $violations = [],
        public array $headers = [],
        public string $type = 'about:blank',
    ) {
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

        return $problem;
    }
}
