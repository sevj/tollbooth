<?php

declare(strict_types=1);

namespace Tollbooth\Docs;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BackedEnumType;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\EnumType;
use Symfony\Component\TypeInfo\Type\GenericType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolverInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Tollbooth\Core\Output\NameCase;

/**
 * @internal
 */
final class SchemaGenerator
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $components = [];

    /**
     * @var array<string, string>
     */
    private array $names = [];

    private readonly TypeResolverInterface $types;

    public function __construct(
        private readonly NameCase $case,
    ) {
        $this->types = TypeResolver::create();
    }

    public function resolve(\ReflectionParameter|\ReflectionProperty|\ReflectionFunctionAbstract $subject): Type
    {
        return $this->types->resolve($subject);
    }

    /**
     * @return array<string, mixed>
     */
    public function forType(Type $type, bool $input = false): array
    {
        if ($type instanceof NullableType) {
            return $this->nullable($this->forType($type->getWrappedType(), $input));
        }

        if ($type instanceof CollectionType) {
            $items = $this->forType($type->getCollectionValueType(), $input);
            $key = $type->getCollectionKeyType();

            return $type->isList() || $key->isIdentifiedBy(TypeIdentifier::INT)
                ? ['type' => 'array', 'items' => $items]
                : ['type' => 'object', 'additionalProperties' => $items];
        }

        if ($type instanceof GenericType) {
            return $this->forType($type->getWrappedType(), $input);
        }

        if ($type instanceof UnionType) {
            $schemas = [];
            foreach ($type->getTypes() as $member) {
                $schemas[] = $this->forType($member, $input);
            }

            return ['anyOf' => $schemas];
        }

        if ($type instanceof BackedEnumType && is_subclass_of($type->getClassName(), \BackedEnum::class)) {
            return $this->enum($type->getClassName());
        }

        if ($type instanceof EnumType) {
            return ['type' => 'string'];
        }

        if ($type instanceof ObjectType) {
            $class = $type->getClassName();

            return class_exists($class) || interface_exists($class) ? $this->forClass($class, $input) : [];
        }

        if ($type instanceof BuiltinType) {
            return match ($type->getTypeIdentifier()) {
                TypeIdentifier::INT => ['type' => 'integer'],
                TypeIdentifier::FLOAT => ['type' => 'number'],
                TypeIdentifier::STRING => ['type' => 'string'],
                TypeIdentifier::BOOL, TypeIdentifier::TRUE, TypeIdentifier::FALSE => ['type' => 'boolean'],
                TypeIdentifier::ARRAY, TypeIdentifier::ITERABLE => ['type' => 'array', 'items' => new \ArrayObject()],
                TypeIdentifier::NULL => ['type' => 'null'],
                default => [],
            };
        }

        return [];
    }

    /**
     * @param class-string $class
     * @param list<string> $exclude
     *
     * @return array<string, mixed>
     */
    public function forClass(string $class, bool $input = false, array $exclude = [], string $suffix = ''): array
    {
        if (is_a($class, \DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        $name = $this->componentName($class, $input, $suffix);
        if (!isset($this->components[$name])) {
            $this->components[$name] = [];
            $this->components[$name] = $this->objectSchema(new \ReflectionClass($class), $input, $exclude);
        }

        return ['$ref' => '#/components/schemas/'.$name];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function components(): array
    {
        ksort($this->components);

        return $this->components;
    }

    public function propertyName(string $name): string
    {
        return $this->case->apply($name);
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<string>             $exclude
     *
     * @return array<string, mixed>
     */
    private function objectSchema(\ReflectionClass $class, bool $input, array $exclude): array
    {
        $defaults = $this->constructorDefaults($class);
        $properties = [];
        $required = [];
        foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || \in_array($property->getName(), $exclude, true)) {
                continue;
            }

            $name = $this->propertyName($property->getName());
            $type = $this->types->resolve($property);
            $schema = $this->forType($type, $input) + $this->constraints($property);
            $hasDefault = \array_key_exists($property->getName(), $defaults) || $property->hasDefaultValue();
            $default = $defaults[$property->getName()] ?? ($property->hasDefaultValue() ? $property->getDefaultValue() : null);
            if ($hasDefault && null !== ($exported = $this->export($default))) {
                $schema['default'] = $exported;
            }

            $properties[$name] = $schema;
            if ($input ? !$hasDefault && !$type->isNullable() : !$type->isNullable()) {
                $required[] = $name;
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => [] === $properties ? new \ArrayObject() : $properties,
            'required' => $required,
        ], static fn (mixed $value): bool => [] !== $value);
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return array<string, mixed>
     */
    private function constructorDefaults(\ReflectionClass $class): array
    {
        $defaults = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted() && $parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private function constraints(\ReflectionProperty $property): array
    {
        $schema = [];
        foreach ($property->getAttributes(Constraint::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $constraint = $attribute->newInstance();
            $schema += match (true) {
                $constraint instanceof Assert\NotBlank => ['minLength' => 1],
                $constraint instanceof Assert\Positive => ['exclusiveMinimum' => 0],
                $constraint instanceof Assert\PositiveOrZero => ['minimum' => 0],
                $constraint instanceof Assert\Negative => ['exclusiveMaximum' => 0],
                $constraint instanceof Assert\NegativeOrZero => ['maximum' => 0],
                $constraint instanceof Assert\Length => array_filter(['minLength' => $constraint->min, 'maxLength' => $constraint->max], static fn (mixed $value): bool => null !== $value),
                $constraint instanceof Assert\Count => array_filter(['minItems' => $constraint->min, 'maxItems' => $constraint->max], static fn (mixed $value): bool => null !== $value),
                $constraint instanceof Assert\Range => array_filter(['minimum' => $constraint->min, 'maximum' => $constraint->max], static fn (mixed $value): bool => is_numeric($value)),
                $constraint instanceof Assert\Choice && \is_array($constraint->choices) => ['enum' => array_values($constraint->choices)],
                $constraint instanceof Assert\Email => ['format' => 'email'],
                $constraint instanceof Assert\Url => ['format' => 'uri'],
                $constraint instanceof Assert\Uuid => ['format' => 'uuid'],
                default => [],
            };
        }

        return $schema;
    }

    /**
     * @param class-string<\BackedEnum> $class
     *
     * @return array<string, mixed>
     */
    private function enum(string $class): array
    {
        $values = array_map(static fn (\BackedEnum $case): int|string => $case->value, $class::cases());

        return ['type' => \is_int($values[0] ?? null) ? 'integer' : 'string', 'enum' => $values];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function nullable(array $schema): array
    {
        if (isset($schema['type']) && \is_string($schema['type']) && !isset($schema['$ref'])) {
            $schema['type'] = [$schema['type'], 'null'];
            if (isset($schema['enum']) && \is_array($schema['enum'])) {
                $schema['enum'][] = null;
            }

            return $schema;
        }

        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    private function export(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            \is_scalar($value) => $value,
            \is_array($value) && array_is_list($value) && [] === array_filter($value, static fn (mixed $item): bool => !\is_scalar($item)) => $value,
            default => null,
        };
    }

    /**
     * @param class-string $class
     */
    private function componentName(string $class, bool $input, string $suffix): string
    {
        $key = $class.'|'.($input ? 'in' : 'out').'|'.$suffix;
        if (isset($this->names[$key])) {
            return $this->names[$key];
        }

        $short = substr($class, (int) strrpos('\\'.$class, '\\')).$suffix;
        $taken = \in_array($short, $this->names, true);
        $name = $taken ? $short.($input ? 'Input' : 'Output') : $short;
        $index = 2;
        while (\in_array($name, $this->names, true)) {
            $name = $short.$index++;
        }

        return $this->names[$key] = $name;
    }
}
