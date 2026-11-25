<?php

declare(strict_types=1);

namespace Tollbooth\Changes;

enum ChangeKind: string
{
    case Breaking = 'breaking';
    case Risky = 'risky';
    case Added = 'added';
    case Changed = 'changed';
    case Deprecated = 'deprecated';

    public function rank(): int
    {
        return match ($this) {
            self::Breaking => 0,
            self::Risky => 1,
            self::Added => 2,
            self::Changed => 3,
            self::Deprecated => 4,
        };
    }

    public function title(): string
    {
        return ucfirst($this->value);
    }
}
