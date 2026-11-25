<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

/**
 * @internal
 */
final readonly class Operation
{
    /**
     * @param list<Field>       $parameters
     * @param list<Field>       $body
     * @param list<ResponseRow> $responses
     */
    public function __construct(
        public array $parameters,
        public ?string $bodyModel,
        public array $body,
        public array $responses,
        public ?string $bodyExample,
        public ?string $responseContentType,
        public ?string $responseExample,
        public ?int $successStatus,
        public ?string $responseModel,
    ) {
    }

    public function constraints(): int
    {
        return \count(array_filter($this->body, static fn (Field $field): bool => [] !== $field->rules));
    }

    public function nestedFields(): int
    {
        return \count(array_filter($this->body, static fn (Field $field): bool => '' !== $field->prefix));
    }
}
