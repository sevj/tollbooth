<?php

declare(strict_types=1);

namespace Tollbooth\Auth\ApiKey;

interface ManageableApiKeyProviderInterface extends ListableApiKeyProviderInterface
{
    public function save(ApiKey $key): void;

    public function remove(string $id): void;

    public function manages(string $id): bool;
}
