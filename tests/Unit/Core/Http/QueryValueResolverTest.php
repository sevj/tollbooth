<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Core\Http\QueryValueResolver;
use Tollbooth\Tests\Fixtures\Model\Color;

final class QueryValueResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function validValues(): iterable
    {
        yield 'int' => ['int', '42', 42];
        yield 'negative int' => ['int', '-3', -3];
        yield 'float' => ['float', '1.5', 1.5];
        yield 'bool true' => ['bool', 'true', true];
        yield 'bool zero' => ['bool', '0', false];
        yield 'string' => ['string', 'abc', 'abc'];
        yield 'enum' => [Color::class, 'amber', Color::Amber];
    }

    #[DataProvider('validValues')]
    public function testValueIsCastToTheArgumentType(string $type, string $raw, mixed $expected): void
    {
        self::assertSame([$expected], $this->resolve(new Request(['value' => $raw]), $type));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'int' => ['int', '4.2'];
        yield 'bool' => ['bool', 'maybe'];
        yield 'enum' => [Color::class, 'blue'];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValueIsABadRequest(string $type, string $raw): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolve(new Request(['value' => $raw]), $type);
    }

    public function testMissingValueFallsBackToTheDefault(): void
    {
        self::assertSame([5], $this->resolve(new Request(), 'int', hasDefault: true, default: 5));
        self::assertSame([null], $this->resolve(new Request(), 'int', nullable: true));
    }

    public function testMissingRequiredValueIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('The query parameter "value" is required.');

        $this->resolve(new Request(), 'int');
    }

    public function testAttributeNameOverridesTheArgumentName(): void
    {
        self::assertSame([2], $this->resolve(new Request(['per_page' => '2']), 'int', attribute: new Query('per_page')));
    }

    public function testArgumentsWithoutTheAttributeAreIgnored(): void
    {
        $argument = new ArgumentMetadata('value', 'int', false, false, null);

        self::assertSame([], (new QueryValueResolver())->resolve(new Request(['value' => '1']), $argument));
    }

    /**
     * @return list<mixed>
     */
    private function resolve(Request $request, string $type, bool $hasDefault = false, mixed $default = null, bool $nullable = false, ?Query $attribute = null): array
    {
        $argument = new ArgumentMetadata('value', $type, false, $hasDefault, $default, $nullable, [$attribute ?? new Query()]);

        return (new QueryValueResolver())->resolve($request, $argument);
    }
}
