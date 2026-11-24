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
     * @var array<class-string, string>
     */
    private array $types = [];

    private readonly EnglishInflector $inflector;

    public function __construct(
        private readonly NameCase $case,
    ) {
        $this->inflector = new EnglishInflector();
    }

    public function typeOf(object $resource): string
    {
        return $this->types[$resource::class] ??= $this->resolve($resource::class);
    }

    /**
     * @param class-string $class
     */
    private function resolve(string $class): string
    {
        $short = substr($class, (int) strrpos('\\'.$class, '\\'));
        $base = (string) preg_replace(self::SUFFIXES, '', $short);
        $words = explode('_', u('' === $base ? $short : $base)->snake()->toString());
        $last = (string) array_pop($words);
        $words[] = $this->inflector->pluralize($last)[0];
        $snake = implode('_', $words);

        return NameCase::CamelCase === $this->case || NameCase::Preserve === $this->case
            ? u($snake)->camel()->toString()
            : $snake;
    }
}
