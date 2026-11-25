<?php

declare(strict_types=1);

namespace Tollbooth\Auth\ApiKey;

interface ListableApiKeyProviderInterface extends ApiKeyProviderInterface
{
    /**
     * @return iterable<ApiKey>
     */
    public function all(): iterable;
}
