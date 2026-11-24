<?php

declare(strict_types=1);

namespace Tollbooth\Core\Output;

use function Symfony\Component\String\u;

enum NameCase: string
{
    case Preserve = 'preserve';
    case SnakeCase = 'snake_case';
    case CamelCase = 'camel_case';

    public function nameConverterId(): ?string
    {
        return match ($this) {
            self::Preserve => null,
            self::SnakeCase => 'serializer.name_converter.camel_case_to_snake_case',
            self::CamelCase => 'serializer.name_converter.snake_case_to_camel_case',
        };
    }

    public function apply(string $name): string
    {
        return match ($this) {
            self::Preserve => $name,
            self::SnakeCase => u($name)->snake()->toString(),
            self::CamelCase => u($name)->camel()->toString(),
        };
    }

    public function applyToPath(string $path): string
    {
        if (self::Preserve === $this) {
            return $path;
        }

        return (string) preg_replace_callback(
            '/[A-Za-z_][A-Za-z0-9_]*/',
            fn (array $match): string => $this->apply($match[0]),
            $path,
        );
    }
}
