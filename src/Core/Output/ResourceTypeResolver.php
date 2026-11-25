<?php

declare(strict_types=1);

namespace Tollbooth\Core\Output;

use Symfony\Component\String\Inflector\EnglishInflector;

use function Symfony\Component\String\u;

/**
 * @internal
 */
final class ResourceTypeResolver
{
    private const string SUFFIXES = '/(View|Output|Resource|Response|Dto)$/';

    /**
     * @var array<string, string>
     */
    private array $types = [];

    private readonly EnglishInflector $inflector;

    public function __construct()
    {
        $this->inflector = new EnglishInflector();
    }

    public function typeOf(object $resource, NameCase $case): string
    {
        return $this->typeOfClass($resource::class, $case);
    }

    /**
     * @param class-string $class
     */
    public function typeOfClass(string $class, NameCase $case): string
    {
        return $this->types[$class.'|'.$case->value] ??= $this->resolve($class, $case);
    }

    /**
     * @param class-string $class
     */
    private function resolve(string $class, NameCase $case): string
    {
        $short = substr($class, (int) strrpos('\\'.$class, '\\'));
        $base = (string) preg_replace(self::SUFFIXES, '', $short);
        $words = explode('_', u('' === $base ? $short : $base)->snake()->toString());
        $last = (string) array_pop($words);
        $words[] = $this->inflector->pluralize($last)[0];
        $snake = implode('_', $words);

        return NameCase::SnakeCase === $case ? $snake : u($snake)->camel()->toString();
    }
}
