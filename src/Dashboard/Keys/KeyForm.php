<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Keys;

use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
final readonly class KeyForm
{
    /**
     * @param list<string>          $scopes
     * @param list<string>          $allowedIps
     * @param array<string, string> $errors
     */
    private function __construct(
        public string $name,
        public ?string $plan,
        public array $scopes,
        public ?\DateTimeImmutable $expiresAt,
        public array $allowedIps,
        public bool $test,
        public array $errors,
        public string $rawScopes = '',
        public string $rawExpires = '',
        public string $rawIps = '',
    ) {
    }

    public static function empty(?string $plan = null): self
    {
        return new self('', $plan, [], null, [], false, []);
    }

    /**
     * @param list<string> $plans
     * @param list<string> $takenNames
     */
    public static function submit(Request $request, array $plans, array $takenNames, \DateTimeImmutable $now): self
    {
        $errors = [];
        $name = trim($request->request->getString('name'));
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name)) {
            $errors['name'] = 'name_format';
        } elseif (\in_array(strtolower($name), array_map('strtolower', $takenNames), true)) {
            $errors['name'] = 'name_taken';
        }

        $plan = trim($request->request->getString('plan'));
        if ('' !== $plan && !\in_array($plan, $plans, true)) {
            $errors['plan'] = 'plan_unknown';
        }

        $rawScopes = trim($request->request->getString('scopes'));
        $scopes = self::split($rawScopes);
        foreach ($scopes as $scope) {
            if (1 !== preg_match('/^[A-Za-z0-9._:*-]{1,100}$/', $scope)) {
                $errors['scopes'] = 'scope_format';
            }
        }

        $rawExpires = trim($request->request->getString('expires'));
        $expires = null;
        if ('' !== $rawExpires) {
            $expires = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawExpires, new \DateTimeZone('UTC')) ?: null;
            if (null === $expires) {
                $errors['expires'] = 'expires_format';
            } elseif ($expires <= $now) {
                $errors['expires'] = 'expires_past';
            }
        }

        $rawIps = trim($request->request->getString('allowed_ips'));
        $ips = self::split($rawIps);
        foreach ($ips as $ip) {
            if (!self::isIpOrRange($ip)) {
                $errors['allowed_ips'] = 'ip_format';
            }
        }

        return new self($name, '' === $plan ? null : $plan, $scopes, $expires, $ips, $request->request->getBoolean('test'), $errors, $rawScopes, $rawExpires, $rawIps);
    }

    public function valid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        return array_values(array_unique(array_filter(preg_split('/[\s,]+/', $value) ?: [], static fn (string $item): bool => '' !== $item)));
    }

    private static function isIpOrRange(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, null);
        if (false === filter_var($address, \FILTER_VALIDATE_IP)) {
            return false;
        }
        if (null === $bits) {
            return true;
        }

        $max = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }
}
