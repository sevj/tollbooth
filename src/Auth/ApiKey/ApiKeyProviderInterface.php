<?php

declare(strict_types=1);

namespace Tollbooth\Auth\ApiKey;

interface ApiKeyProviderInterface
{
    public function find(string $id): ?ApiKey;
}
