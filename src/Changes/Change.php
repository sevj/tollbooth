<?php

declare(strict_types=1);

namespace Tollbooth\Changes;

final readonly class Change
{
    /**
     * @param list<string> $operations
     */
    public function __construct(
        public ChangeKind $kind,
        public string $group,
        public string $element,
        public string $message,
        public array $operations = [],
    ) {
    }

    public function withOperation(string $operation): self
    {
        return \in_array($operation, $this->operations, true)
            ? $this
            : new self($this->kind, $this->group, $this->element, $this->message, [...$this->operations, $operation]);
    }

    /**
     * @return array{kind: string, group: string, element: string, message: string, operations: list<string>}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'group' => $this->group, 'element' => $this->element, 'message' => $this->message, 'operations' => $this->operations];
    }
}
