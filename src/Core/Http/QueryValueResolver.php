<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tollbooth\Core\Attribute\Query;

/**
 * @internal
 */
final class QueryValueResolver implements ValueResolverInterface
{
    /**
     * @return list<mixed>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $attribute = $argument->getAttributesOfType(Query::class)[0] ?? null;
        if (!$attribute instanceof Query) {
            return [];
        }

        $name = $attribute->name ?? $argument->getName();
        $query = $request->query->all();
        if (!\array_key_exists($name, $query) || '' === $query[$name]) {
            return [$this->fallback($argument, $name)];
        }

        return [$this->cast($query[$name], (string) $argument->getType(), $name)];
    }

    private function fallback(ArgumentMetadata $argument, string $name): mixed
    {
        if ($argument->hasDefaultValue()) {
            return $argument->getDefaultValue();
        }

        if ($argument->isNullable()) {
            return null;
        }

        throw new BadRequestHttpException(\sprintf('The query parameter "%s" is required.', $name));
    }

    private function cast(mixed $value, string $type, string $name): mixed
    {
        if ('array' === $type) {
            return (array) $value;
        }

        if (!\is_string($value)) {
            throw new BadRequestHttpException(\sprintf('The query parameter "%s" must be a single value.', $name));
        }

        $cast = match (true) {
            'string' === $type, 'mixed' === $type, '' === $type => $value,
            'int' === $type => filter_var($value, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE),
            'float' === $type => filter_var($value, \FILTER_VALIDATE_FLOAT, \FILTER_NULL_ON_FAILURE),
            'bool' === $type => filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE),
            is_subclass_of($type, \BackedEnum::class) => $this->enum($type, $value),
            default => throw new \LogicException(\sprintf('The query parameter "%s" has the unsupported type "%s".', $name, $type)),
        };

        if (null === $cast) {
            throw new BadRequestHttpException(\sprintf('The query parameter "%s" must be of type %s.', $name, $this->describe($type)));
        }

        return $cast;
    }

    /**
     * @param class-string<\BackedEnum> $type
     */
    private function enum(string $type, string $value): ?\BackedEnum
    {
        $reflection = new \ReflectionEnum($type);
        if ('int' === (string) $reflection->getBackingType()) {
            $int = filter_var($value, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE);

            return null === $int ? null : $type::tryFrom($int);
        }

        return $type::tryFrom($value);
    }

    private function describe(string $type): string
    {
        if (!is_subclass_of($type, \BackedEnum::class)) {
            return $type;
        }

        return 'one of '.implode(', ', array_map(
            static fn (\BackedEnum $case): string => '"'.$case->value.'"',
            $type::cases(),
        ));
    }
}
