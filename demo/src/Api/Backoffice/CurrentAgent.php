<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Backoffice;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\AgentView;

#[Endpoint('GET', '/backoffice/agents/me')]
final readonly class CurrentAgent
{
    public function __invoke(Consumer $consumer): AgentView
    {
        return new AgentView($consumer->id, $consumer->name, $consumer->scopes);
    }
}
