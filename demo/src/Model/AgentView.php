<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class AgentView
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $clientId,
        public string $name,
        public array $scopes,
    ) {
    }
}
