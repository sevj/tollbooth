<?php

declare(strict_types=1);

namespace Tollbooth\Changes;

use Tollbooth\Docs\OpenApiGenerator;

/**
 * @internal
 */
final readonly class CurrentContract
{
    public function __construct(
        private OpenApiGenerator $generator,
    ) {
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function documents(): array
    {
        $documents = [];
        foreach ($this->generator->groups() as $group) {
            $document = $this->generator->generate($group);
            $decoded = null === $document ? null : json_decode(json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($decoded)) {
                $documents[$group] = $decoded;
            }
        }

        return $documents;
    }
}
