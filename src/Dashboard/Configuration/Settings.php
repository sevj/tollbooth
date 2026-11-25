<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Configuration;

/**
 * @internal
 */
final readonly class Settings
{
    private const array HIDDEN = ['rate_limit.plans', 'auth.api_keys'];

    /**
     * @param array<string, mixed> $config the bundle configuration, without secrets
     */
    public function __construct(
        private array $config,
    ) {
    }

    /**
     * @return list<array{section: string, rows: list<array{key: string, value: string}>}>
     */
    public function sections(string $group): array
    {
        $sections = ['general' => []];
        foreach ($this->config as $key => $value) {
            if ('groups' === $key) {
                continue;
            }
            if (\is_array($value) && !array_is_list($value)) {
                $sections[$key] = self::rows($value, 'tollbooth.'.$key, $key);
            } else {
                $sections['general'][] = ['key' => 'tollbooth.'.$key, 'value' => self::format($value)];
            }
        }
        $groups = \is_array($this->config['groups'] ?? null) ? $this->config['groups'] : [];
        if (\is_array($groups[$group] ?? null)) {
            $sections['group'] = self::rows($groups[$group], 'tollbooth.groups.'.$group, 'groups.'.$group);
        }

        $ordered = [];
        foreach (['general', 'group', 'output', 'auth', 'rate_limit', 'monitoring', 'docs', 'health', 'changes', 'dashboard'] as $name) {
            if ([] !== ($sections[$name] ?? [])) {
                $ordered[] = ['section' => $name, 'rows' => $sections[$name]];
            }
            unset($sections[$name]);
        }
        foreach ($sections as $name => $rows) {
            if ([] !== $rows) {
                $ordered[] = ['section' => (string) $name, 'rows' => $rows];
            }
        }

        return $ordered;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<array{key: string, value: string}>
     */
    private static function rows(array $values, string $prefix, string $path): array
    {
        $rows = [];
        foreach ($values as $key => $value) {
            if (\in_array($path.'.'.$key, self::HIDDEN, true)) {
                continue;
            }
            if (\is_array($value) && [] !== $value && !array_is_list($value)) {
                $rows = [...$rows, ...self::rows($value, $prefix.'.'.$key, $path.'.'.$key)];
                continue;
            }
            $rows[] = ['key' => $prefix.'.'.$key, 'value' => self::format($value)];
        }

        return $rows;
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            null === $value => '~',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_array($value) => [] === $value ? '[]' : implode(', ', array_map(self::format(...), $value)),
            \is_scalar($value) => (string) $value,
            default => '…',
        };
    }
}
