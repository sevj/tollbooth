<?php

declare(strict_types=1);

namespace Tollbooth\Core\Metadata;

use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Security\AuthMethod;

/**
 * @internal
 *
 * @phpstan-type GroupConfig array{namespace: ?string, path: ?string, prefix: string, output: array{envelope: ?string, case: ?string}, auth?: list<string>|null, scopes?: list<string>|string|null, rate_limits?: list<string>|array<string, string>}
 */
final readonly class GroupResolver
{
    /**
     * @var array<string, Group>
     */
    private array $groups;

    /**
     * @param array<string, GroupConfig> $groups
     */
    public function __construct(
        private Group $default,
        array $groups = [],
    ) {
        $resolved = [];
        foreach ($groups as $name => $config) {
            $auth = $config['auth'] ?? null;
            $scopes = $config['scopes'] ?? null;
            $resolved[$name] = new Group(
                name: $name,
                envelope: null === $config['output']['envelope'] ? $default->envelope : Envelope::from($config['output']['envelope']),
                case: null === $config['output']['case'] ? $default->case : NameCase::from($config['output']['case']),
                prefix: self::normalizePath($config['prefix']),
                namespace: null === $config['namespace'] ? null : trim($config['namespace'], '\\').'\\',
                path: null === $config['path'] ? null : self::normalizePath($config['path']),
                auth: null === $auth ? $default->auth : AuthMethod::list($auth, \sprintf('Group "%s"', $name)),
                scopes: \is_array($scopes) ? $scopes : $default->scopes,
                autoScopes: null === $scopes ? $default->autoScopes : 'auto' === $scopes,
                limits: Limit::parseAll($config['rate_limits'] ?? [], 'group:'.$name, $name.'.'),
            );
        }
        $this->groups = $resolved;
    }

    public function resolve(string $class, string $path, ?string $explicit): Group
    {
        if (null !== $explicit) {
            return Group::DEFAULT === $explicit ? $this->default : ($this->groups[$explicit] ?? throw InvalidEndpointException::unknownGroup($class, $explicit, array_keys($this->groups)));
        }

        return $this->longest(array_filter($this->groups, static fn (Group $group): bool => $group->matchesNamespace($class)), static fn (Group $group): int => \strlen((string) $group->namespace))
            ?? $this->longest(array_filter($this->groups, static fn (Group $group): bool => $group->matchesPath($path)), static fn (Group $group): int => \strlen((string) $group->path))
            ?? $this->default;
    }

    /**
     * @param array<string, Group> $candidates
     * @param callable(Group): int $length
     */
    private function longest(array $candidates, callable $length): ?Group
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if (null === $best || $length($candidate) > $length($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private static function normalizePath(string $path): string
    {
        $trimmed = trim($path, '/');

        return '' === $trimmed ? '' : '/'.$trimmed;
    }
}
