<?php

declare(strict_types=1);

namespace Tollbooth\Auth\ApiKey;

final class ApiKeyFormat
{
    private const string PATTERN = '/^tb_(live|test)_([a-z0-9]{12})_([A-Za-z0-9]{32})$/';
    private const string ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const string SECRET_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * @return array{key: string, id: string, hash: string}
     */
    public static function generate(bool $live = true): array
    {
        $id = self::random(self::ALPHABET, 12);
        $secret = self::random(self::SECRET_ALPHABET, 32);

        return [
            'key' => \sprintf('tb_%s_%s_%s', $live ? 'live' : 'test', $id, $secret),
            'id' => $id,
            'hash' => self::hash($secret),
        ];
    }

    /**
     * @return array{id: string, secret: string, test: bool}|null
     */
    public static function parse(string $key): ?array
    {
        if (1 !== preg_match(self::PATTERN, $key, $matches)) {
            return null;
        }

        return ['id' => $matches[2], 'secret' => $matches[3], 'test' => 'test' === $matches[1]];
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function verify(string $secret, string $hash): bool
    {
        return hash_equals($hash, self::hash($secret));
    }

    private static function random(string $alphabet, int $length): string
    {
        $max = \strlen($alphabet) - 1;
        $value = '';
        for ($i = 0; $i < $length; ++$i) {
            $value .= $alphabet[random_int(0, $max)];
        }

        return $value;
    }
}
