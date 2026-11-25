<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class ImportResult
{
    public function __construct(
        public int $imported,
        public int $rejected,
    ) {
    }
}
