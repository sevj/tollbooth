<?php

declare(strict_types=1);

namespace Tollbooth\Core\Metadata;

use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;

/**
 * @internal
 */
final readonly class Group
{
    public const string DEFAULT = 'default';

    public function __construct(
        public string $name,
        public Envelope $envelope,
        public NameCase $case,
        public string $prefix = '',
        public ?string $namespace = null,
        public ?string $path = null,
    ) {
    }

    public function matchesNamespace(string $class): bool
    {
        return null !== $this->namespace && str_starts_with($class, $this->namespace);
    }

    public function matchesPath(string $path): bool
    {
        return null !== $this->path && ($path === $this->path || str_starts_with($path, $this->path.'/'));
    }
}
