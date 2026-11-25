<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures;

use Tollbooth\Auth\ApiKey\ApiKeyFormat;

final class TestKeys
{
    public const string READER = 'tb_test_reader000001_RRRRRRRRRRRRRRRRRRRRRRRRRRRRRRRR';
    public const string ACME = 'tb_test_acmeerp00001_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    public const string EXPIRED = 'tb_test_expired00001_EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE';
    public const string RESTRICTED = 'tb_test_restricted01_IIIIIIIIIIIIIIIIIIIIIIIIIIIIIIII';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function config(): array
    {
        return [
            'reader' => ['id' => 'reader000001', 'hash' => ApiKeyFormat::hash(str_repeat('R', 32)), 'scopes' => ['things:read', 'me:read'], 'plan' => 'pro'],
            'acme-erp' => ['id' => 'acmeerp00001', 'hash' => ApiKeyFormat::hash(str_repeat('A', 32)), 'scopes' => ['me:read']],
            'expired' => ['id' => 'expired00001', 'hash' => ApiKeyFormat::hash(str_repeat('E', 32)), 'expires_at' => '2020-01-01'],
            'restricted' => ['id' => 'restricted01', 'hash' => ApiKeyFormat::hash(str_repeat('I', 32)), 'allowed_ips' => ['10.0.0.0/8']],
        ];
    }
}
