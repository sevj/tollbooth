<?php

declare(strict_types=1);

namespace Tollbooth\Changes;

/**
 * @internal
 */
final class ContractDiffer
{
    private const string REQUEST = 'request';
    private const string RESPONSE = 'response';
    private const array LOWER_BOUNDS = ['minLength', 'minimum', 'exclusiveMinimum', 'minItems'];
    private const array UPPER_BOUNDS = ['maxLength', 'maximum', 'exclusiveMaximum', 'maxItems'];

    /**
     * @var array<string, Change>
     */
    private array $changes = [];

    /**
     * @var array<mixed>
     */
    private array $base = [];

    /**
     * @var array<mixed>
     */
    private array $target = [];

    private string $group = '';

    /**
     * @var array<string, true>
     */
    private array $visiting = [];

    /**
     * @param array<string, array<mixed>> $base
     * @param array<string, array<mixed>> $target
     */
    public function diff(array $base, array $target): ChangeReport
    {
        $this->changes = [];
        foreach (array_unique([...array_keys($base), ...array_keys($target)]) as $group) {
            $this->group = $group;
            $this->base = $base[$group] ?? [];
            $this->target = $target[$group] ?? [];
            $this->diffDocuments();
        }

        $changes = array_values($this->changes);
        usort($changes, static fn (Change $a, Change $b): int => [$a->kind->rank(), $a->group, $a->element, $a->message] <=> [$b->kind->rank(), $b->group, $b->element, $b->message]);

        return new ChangeReport($changes);
    }

    /**
     * @param array<string, array<mixed>> ...$snapshots
     *
     * @return array<string, string>
     */
    public static function operationIds(array ...$snapshots): array
    {
        $ids = [];
        foreach ($snapshots as $groups) {
            foreach ($groups as $document) {
                foreach (self::map($document['paths'] ?? []) ?? [] as $path => $methods) {
                    foreach (self::map($methods) ?? [] as $method => $operation) {
                        $id = \is_array($operation) ? ($operation['operationId'] ?? null) : null;
                        if (\is_string($id)) {
                            $ids[strtoupper((string) $method).' '.$path] = $id;
                        }
                    }
                }
            }
        }

        return $ids;
    }

    private function diffDocuments(): void
    {
        $before = $this->operations($this->base);
        $after = $this->operations($this->target);

        foreach ($before as $key => $operation) {
            if (!isset($after[$key])) {
                $this->add(ChangeKind::Breaking, $key, 'The endpoint is removed.', $key);
            }
        }

        foreach ($after as $key => $operation) {
            if (!isset($before[$key])) {
                $this->add(ChangeKind::Added, $key, 'New endpoint.', $key);
                continue;
            }

            $this->diffOperation($key, $before[$key], $operation);
        }
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     */
    private function diffOperation(string $key, array $before, array $after): void
    {
        if (true !== ($before['deprecated'] ?? false) && true === ($after['deprecated'] ?? false)) {
            $sunset = $after['x-sunset'] ?? null;
            $this->add(ChangeKind::Deprecated, $key, \is_string($sunset) ? \sprintf('Deprecated, sunset on %s.', $sunset) : 'Deprecated.', $key);
        }

        $this->diffParameters($key, self::list($before['parameters'] ?? []), self::list($after['parameters'] ?? []));
        $this->diffRequestBody($key, self::map($before['requestBody'] ?? null), self::map($after['requestBody'] ?? null));
        $this->diffResponses($key, self::map($before['responses'] ?? []) ?? [], self::map($after['responses'] ?? []) ?? []);
        $this->diffSecurity($key, $before['security'] ?? null, $after['security'] ?? null);
        $this->diffLimits($key, self::list($before['x-rate-limits'] ?? []), self::list($after['x-rate-limits'] ?? []));
    }

    /**
     * @param list<mixed> $before
     * @param list<mixed> $after
     */
    private function diffParameters(string $key, array $before, array $after): void
    {
        $index = static function (array $parameters): array {
            $indexed = [];
            foreach ($parameters as $parameter) {
                if (\is_array($parameter) && \is_string($parameter['in'] ?? null) && \is_string($parameter['name'] ?? null)) {
                    $indexed[$parameter['in'].':'.$parameter['name']] = $parameter;
                }
            }

            return $indexed;
        };
        $old = $index($before);
        $new = $index($after);

        foreach ($old as $id => $parameter) {
            if (!isset($new[$id])) {
                $this->add(ChangeKind::Changed, $key.' ?'.$parameter['name'], 'The parameter is no longer read.', $key);
            }
        }

        foreach ($new as $id => $parameter) {
            $element = $key.' ?'.$parameter['name'];
            $required = true === ($parameter['required'] ?? false);
            if (!isset($old[$id])) {
                $this->add($required ? ChangeKind::Breaking : ChangeKind::Added, $element, $required ? 'New required parameter.' : 'New optional parameter.', $key);
                continue;
            }

            if ($required && true !== ($old[$id]['required'] ?? false)) {
                $this->add(ChangeKind::Breaking, $element, 'The parameter becomes required.', $key);
            }

            $this->diffSchema($old[$id]['schema'] ?? [], $parameter['schema'] ?? [], $element, self::REQUEST, $key);
        }
    }

    /**
     * @param array<mixed>|null $before
     * @param array<mixed>|null $after
     */
    private function diffRequestBody(string $key, ?array $before, ?array $after): void
    {
        if (null === $before && null === $after) {
            return;
        }

        if (null === $after) {
            $this->add(ChangeKind::Changed, $key.' body', 'The body is no longer read.', $key);

            return;
        }

        if (null === $before) {
            $required = true === ($after['required'] ?? false);
            $this->add($required ? ChangeKind::Breaking : ChangeKind::Added, $key.' body', $required ? 'A body is now required.' : 'A body is now accepted.', $key);

            return;
        }

        $this->diffSchema(self::contentSchema($before), self::contentSchema($after), $key.' body', self::REQUEST, $key);
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     */
    private function diffResponses(string $key, array $before, array $after): void
    {
        $success = static fn (array $responses): ?string => array_values(array_filter(array_map(strval(...), array_keys($responses)), static fn (string $status): bool => str_starts_with($status, '2')))[0] ?? null;
        $old = $success($before);
        $new = $success($after);

        if (null !== $old && null !== $new && $old !== $new) {
            $this->add(ChangeKind::Breaking, $key.' response', \sprintf('The success status changes from %s to %s.', $old, $new), $key);
        } elseif (null !== $old && null !== $new) {
            $this->diffSchema(self::contentSchema(self::map($before[$old]) ?? []), self::contentSchema(self::map($after[$new]) ?? []), $key.' response', self::RESPONSE, $key);
        }

        $errors = static fn (array $responses): array => array_values(array_filter(array_map(strval(...), array_keys($responses)), static fn (string $status): bool => !str_starts_with($status, '2')));
        foreach (array_diff($errors($after), $errors($before)) as $status) {
            $this->add(ChangeKind::Changed, $key, \sprintf('Can now answer %s.', $status), $key);
        }
        foreach (array_diff($errors($before), $errors($after)) as $status) {
            $this->add(ChangeKind::Changed, $key, \sprintf('No longer answers %s.', $status), $key);
        }
    }

    private function diffSecurity(string $key, mixed $before, mixed $after): void
    {
        [$oldSchemes, $oldScopes, $oldPublic] = self::security($before);
        [$newSchemes, $newScopes, $newPublic] = self::security($after);

        if ($oldPublic && !$newPublic) {
            $this->add(ChangeKind::Breaking, $key, 'Credentials are now required.', $key);
        } elseif (!$oldPublic && $newPublic) {
            $this->add(ChangeKind::Changed, $key, 'No credentials are required any more.', $key);
        }

        foreach (array_diff($oldSchemes, $newSchemes) as $scheme) {
            if (!$newPublic) {
                $this->add(ChangeKind::Breaking, $key, \sprintf('%s credentials are no longer accepted.', $scheme), $key);
            }
        }
        foreach (array_diff($newSchemes, $oldSchemes) as $scheme) {
            if (!$oldPublic) {
                $this->add(ChangeKind::Added, $key, \sprintf('%s credentials are now accepted.', $scheme), $key);
            }
        }
        foreach (array_diff($newScopes, $oldScopes) as $scope) {
            $this->add(ChangeKind::Breaking, $key, \sprintf('The scope "%s" is now required.', $scope), $key);
        }
        foreach (array_diff($oldScopes, $newScopes) as $scope) {
            $this->add(ChangeKind::Changed, $key, \sprintf('The scope "%s" is no longer required.', $scope), $key);
        }
    }

    /**
     * @param list<mixed> $before
     * @param list<mixed> $after
     */
    private function diffLimits(string $key, array $before, array $after): void
    {
        $index = static function (array $limits): array {
            $indexed = [];
            foreach ($limits as $limit) {
                if (\is_array($limit) && \is_string($limit['name'] ?? null) && \is_int($limit['limit'] ?? null) && \is_int($limit['window'] ?? null)) {
                    $indexed[$limit['name']] = $limit['limit'] / max(1, $limit['window']);
                }
            }

            return $indexed;
        };
        $old = $index($before);
        $new = $index($after);

        foreach ($new as $name => $rate) {
            if (!isset($old[$name])) {
                $this->add(ChangeKind::Risky, $key, \sprintf('New rate limit "%s".', $name), $key);
            } elseif ($rate < $old[$name]) {
                $this->add(ChangeKind::Risky, $key, \sprintf('The rate limit "%s" is tightened.', $name), $key);
            } elseif ($rate > $old[$name]) {
                $this->add(ChangeKind::Changed, $key, \sprintf('The rate limit "%s" is relaxed.', $name), $key);
            }
        }
        foreach (array_diff_key($old, $new) as $name => $rate) {
            $this->add(ChangeKind::Changed, $key, \sprintf('The rate limit "%s" is lifted.', $name), $key);
        }
    }

    private function diffSchema(mixed $before, mixed $after, string $element, string $direction, string $operation): void
    {
        [$before, $element, $beforeRef] = $this->resolve($before, $this->base, $element);
        [$after, $afterElement, $afterRef] = $this->resolve($after, $this->target, $element);
        if (null !== $afterRef && $afterRef === $beforeRef) {
            $element = $afterElement;
        }

        $visit = $beforeRef.'|'.$afterRef.'|'.$direction;
        if ('||'.$direction !== $visit && isset($this->visiting[$visit])) {
            return;
        }
        $this->visiting[$visit] = true;

        try {
            $this->compare($before, $after, $element, $direction, $operation);
        } finally {
            unset($this->visiting[$visit]);
        }
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     */
    private function compare(array $before, array $after, string $element, string $direction, string $operation): void
    {
        [$before, $wasNullable] = self::nullable($before);
        [$after, $isNullable] = self::nullable($after);

        $oldType = $before['type'] ?? null;
        $newType = $after['type'] ?? null;
        if (\is_string($oldType) && \is_string($newType) && $oldType !== $newType) {
            $this->add(ChangeKind::Breaking, $element, \sprintf('The type changes from %s to %s.', $oldType, $newType), $operation);

            return;
        }

        if ($wasNullable !== $isNullable) {
            $this->add(match (true) {
                self::RESPONSE === $direction && $isNullable => ChangeKind::Risky,
                self::REQUEST === $direction && !$isNullable => ChangeKind::Breaking,
                default => ChangeKind::Changed,
            }, $element, $isNullable ? 'Can now be null.' : 'Can no longer be null.', $operation);
        }

        $this->compareEnums(self::list($before['enum'] ?? []), self::list($after['enum'] ?? []), $element, $direction, $operation);
        if (self::REQUEST === $direction) {
            $this->compareBounds($before, $after, $element, $operation);
        }

        if ('array' === $newType) {
            $this->diffSchema($before['items'] ?? [], $after['items'] ?? [], $element.'[]', $direction, $operation);
        }

        if ('object' === $newType || isset($before['properties']) || isset($after['properties'])) {
            $this->compareProperties($before, $after, $element, $direction, $operation);
        }
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     */
    private function compareProperties(array $before, array $after, string $element, string $direction, string $operation): void
    {
        $old = self::map($before['properties'] ?? []) ?? [];
        $new = self::map($after['properties'] ?? []) ?? [];
        $oldRequired = array_values(array_filter(self::list($before['required'] ?? []), \is_string(...)));
        $newRequired = array_values(array_filter(self::list($after['required'] ?? []), \is_string(...)));

        foreach (array_keys($old) as $name) {
            if (!\array_key_exists($name, $new)) {
                $this->add(self::RESPONSE === $direction ? ChangeKind::Breaking : ChangeKind::Changed, $element.'.'.$name, self::RESPONSE === $direction ? 'Removed.' : 'No longer read.', $operation);
            }
        }

        foreach ($new as $name => $schema) {
            $child = $element.'.'.$name;
            $required = \in_array((string) $name, $newRequired, true);
            if (!\array_key_exists($name, $old)) {
                $this->add(self::REQUEST === $direction && $required ? ChangeKind::Breaking : ChangeKind::Added, $child, self::REQUEST === $direction && $required ? 'New required field.' : 'New field.', $operation);
                continue;
            }

            if (self::REQUEST === $direction && $required && !\in_array((string) $name, $oldRequired, true)) {
                $this->add(ChangeKind::Breaking, $child, 'Becomes required.', $operation);
            }

            $this->diffSchema($old[$name], $schema, $child, $direction, $operation);
        }
    }

    /**
     * @param list<mixed> $before
     * @param list<mixed> $after
     */
    private function compareEnums(array $before, array $after, string $element, string $direction, string $operation): void
    {
        if ([] === $before || [] === $after) {
            return;
        }

        $old = self::encoded($before);
        $new = self::encoded($after);
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        $format = static fn (array $values): string => implode(', ', array_filter($values, \is_string(...)));

        if ([] !== $added) {
            $this->add(self::RESPONSE === $direction ? ChangeKind::Risky : ChangeKind::Changed, $element, \sprintf('New value%s %s.', \count($added) > 1 ? 's' : '', $format($added)), $operation);
        }
        if ([] !== $removed) {
            $this->add(self::REQUEST === $direction ? ChangeKind::Breaking : ChangeKind::Changed, $element, \sprintf('Value%s %s no longer %s.', \count($removed) > 1 ? 's' : '', $format($removed), self::REQUEST === $direction ? 'accepted' : 'sent'), $operation);
        }
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     */
    private function compareBounds(array $before, array $after, string $element, string $operation): void
    {
        foreach ([...self::LOWER_BOUNDS, ...self::UPPER_BOUNDS] as $bound) {
            $old = $before[$bound] ?? null;
            $new = $after[$bound] ?? null;
            if ($old === $new || (null !== $old && !is_numeric($old)) || (null !== $new && !is_numeric($new))) {
                continue;
            }

            $lower = \in_array($bound, self::LOWER_BOUNDS, true);
            $tightened = null === $old || (null !== $new && ($lower ? $new > $old : $new < $old));
            $this->add(
                $tightened ? ChangeKind::Breaking : ChangeKind::Changed,
                $element,
                \sprintf('%s %s → %s.', $bound, null === $old ? 'none' : (string) $old, null === $new ? 'none' : (string) $new),
                $operation,
            );
        }
    }

    /**
     * @param array<mixed> $document
     *
     * @return array{array<mixed>, string, ?string}
     */
    private function resolve(mixed $schema, array $document, string $element): array
    {
        $schema = \is_array($schema) ? $schema : [];
        $ref = $schema['$ref'] ?? null;
        if (!\is_string($ref) || !str_starts_with($ref, '#/components/schemas/')) {
            return [$schema, $element, null];
        }

        $name = substr($ref, \strlen('#/components/schemas/'));
        $components = self::map(self::map($document['components'] ?? [])['schemas'] ?? []) ?? [];

        return [self::map($components[$name] ?? []) ?? [], $name, $name];
    }

    private function add(ChangeKind $kind, string $element, string $message, string $operation): void
    {
        $id = $kind->value.'|'.$this->group.'|'.$element.'|'.$message;
        $this->changes[$id] = ($this->changes[$id] ?? new Change($kind, $this->group, $element, $message))->withOperation($operation);
    }

    /**
     * @param array<mixed> $document
     *
     * @return array<string, array<mixed>>
     */
    private function operations(array $document): array
    {
        $operations = [];
        foreach (self::map($document['paths'] ?? []) ?? [] as $path => $methods) {
            foreach (self::map($methods) ?? [] as $method => $operation) {
                if (\is_array($operation)) {
                    $operations[strtoupper((string) $method).' '.$path] = $operation;
                }
            }
        }

        return $operations;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array{array<mixed>, bool}
     */
    private static function nullable(array $schema): array
    {
        $anyOf = self::list($schema['anyOf'] ?? []);
        if (2 === \count($anyOf)) {
            $others = array_values(array_filter($anyOf, static fn (mixed $member): bool => !\is_array($member) || 'null' !== ($member['type'] ?? null)));
            if (1 === \count($others) && \is_array($others[0])) {
                return [$others[0], true];
            }
        }

        $type = $schema['type'] ?? null;
        if (\is_array($type) && \in_array('null', $type, true)) {
            $remaining = array_values(array_filter($type, static fn (mixed $member): bool => \is_string($member) && 'null' !== $member));
            $schema['type'] = 1 === \count($remaining) ? $remaining[0] : $remaining;

            return [$schema, true];
        }

        return [$schema, false];
    }

    /**
     * @return array{list<string>, list<string>, bool}
     */
    private static function security(mixed $requirements): array
    {
        if (!\is_array($requirements) || [] === $requirements) {
            return [[], [], true];
        }

        $schemes = [];
        $scopeSets = [];
        $public = false;
        foreach ($requirements as $requirement) {
            $requirement = self::map($requirement) ?? [];
            if ([] === $requirement) {
                $public = true;
                continue;
            }
            foreach ($requirement as $scheme => $required) {
                $schemes[] = (string) $scheme;
                $scopeSets[] = array_values(array_filter(self::list($required), \is_string(...)));
            }
        }

        $scopes = [] === $scopeSets ? [] : array_values(array_intersect(...[...$scopeSets, $scopeSets[0]]));

        return [array_values(array_unique($schemes)), $public ? [] : $scopes, $public];
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function encoded(array $values): array
    {
        return array_map(static fn (mixed $value): string => (string) json_encode($value), $values);
    }

    /**
     * @param array<mixed> $container
     *
     * @return array<mixed>
     */
    private static function contentSchema(array $container): array
    {
        $content = self::map($container['content'] ?? []) ?? [];
        $first = reset($content);

        return \is_array($first) ? (self::map($first['schema'] ?? []) ?? []) : [];
    }

    /**
     * @return array<mixed>|null
     */
    private static function map(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }
}
