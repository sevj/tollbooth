<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Changes;

use PHPUnit\Framework\TestCase;
use Tollbooth\Changes\Change;
use Tollbooth\Changes\ChangeKind;
use Tollbooth\Changes\ChangeReport;
use Tollbooth\Changes\ContractDiffer;

final class ContractDifferTest extends TestCase
{
    public function testDirectionDecidesWhetherARemovedFieldBreaks(): void
    {
        $report = $this->diff(
            ['Order' => self::object(['id' => ['type' => 'string'], 'note' => ['type' => 'string']]), 'OrderInput' => self::object(['note' => ['type' => 'string']])],
            ['Order' => self::object(['id' => ['type' => 'string']]), 'OrderInput' => self::object([])],
        );

        self::assertSame([
            ['breaking', 'Order.note', 'Removed.', ['GET /orders', 'POST /orders']],
            ['changed', 'OrderInput.note', 'No longer read.', ['POST /orders']],
        ], self::rows($report));
    }

    public function testNewRequiredInputFieldBreaksAndAnOptionalOneDoesNot(): void
    {
        $report = $this->diff(
            ['OrderInput' => self::object(['note' => ['type' => 'string']])],
            ['OrderInput' => self::object(['note' => ['type' => 'string'], 'coupon' => ['type' => 'string'], 'quantity' => ['type' => 'integer']], ['quantity'])],
        );

        self::assertSame([
            ['breaking', 'OrderInput.quantity', 'New required field.', ['POST /orders']],
            ['added', 'OrderInput.coupon', 'New field.', ['POST /orders']],
        ], self::rows($report));
    }

    public function testEnumValuesAndNullabilityFollowTheDirection(): void
    {
        $report = $this->diff(
            ['Order' => self::object(['status' => ['type' => 'string', 'enum' => ['pending', 'paid']], 'paid_at' => ['type' => 'string']]), 'OrderInput' => self::object(['status' => ['type' => 'string', 'enum' => ['pending', 'paid']]])],
            ['Order' => self::object(['status' => ['type' => 'string', 'enum' => ['pending', 'paid', 'refunded']], 'paid_at' => ['type' => ['string', 'null']]]), 'OrderInput' => self::object(['status' => ['type' => 'string', 'enum' => ['pending']]])],
        );

        self::assertSame([
            ['breaking', 'OrderInput.status', 'Value "paid" no longer accepted.', ['POST /orders']],
            ['risky', 'Order.paid_at', 'Can now be null.', ['GET /orders', 'POST /orders']],
            ['risky', 'Order.status', 'New value "refunded".', ['GET /orders', 'POST /orders']],
        ], self::rows($report));
    }

    public function testTightenedInputConstraintsBreak(): void
    {
        $report = $this->diff(
            ['OrderInput' => self::object(['quantity' => ['type' => 'integer', 'maximum' => 99], 'note' => ['type' => 'string', 'maxLength' => 100]])],
            ['OrderInput' => self::object(['quantity' => ['type' => 'integer', 'maximum' => 50], 'note' => ['type' => 'string', 'maxLength' => 200]])],
        );

        self::assertSame([
            ['breaking', 'OrderInput.quantity', 'maximum 99 → 50.', ['POST /orders']],
            ['changed', 'OrderInput.note', 'maxLength 100 → 200.', ['POST /orders']],
        ], self::rows($report));
    }

    public function testTypeChangeBreaks(): void
    {
        $report = $this->diff(['Order' => self::object(['id' => ['type' => 'integer']])], ['Order' => self::object(['id' => ['type' => 'string']])]);

        self::assertSame([['breaking', 'Order.id', 'The type changes from integer to string.', ['GET /orders', 'POST /orders']]], self::rows($report));
    }

    public function testNewScopeBreaksAndDeprecationIsReported(): void
    {
        $before = self::document([]);
        $after = self::document([], getSecurity: [['ApiKey' => ['orders:read']]], postExtra: ['deprecated' => true, 'x-sunset' => '2027-03-01']);

        self::assertSame([
            ['breaking', 'GET /orders', 'The scope "orders:read" is now required.', ['GET /orders']],
            ['deprecated', 'POST /orders', 'Deprecated, sunset on 2027-03-01.', ['POST /orders']],
        ], self::rows((new ContractDiffer())->diff(['default' => $before], ['default' => $after])));
    }

    public function testNextVersionFollowsSemver(): void
    {
        $breaking = new ChangeReport([new Change(ChangeKind::Breaking, 'g', 'e', 'm')]);
        $added = new ChangeReport([new Change(ChangeKind::Added, 'g', 'e', 'm')]);
        $changed = new ChangeReport([new Change(ChangeKind::Changed, 'g', 'e', 'm')]);

        self::assertSame('3.0.0', $breaking->nextVersion('2.4.1'));
        self::assertSame('0.5.0', $breaking->nextVersion('0.4.2'));
        self::assertSame('2.5.0', $added->nextVersion('v2.4.1'));
        self::assertSame('2.4.2', $changed->nextVersion('2.4.1'));
        self::assertNull($changed->nextVersion('main'));
    }

    /**
     * @param array<string, array<mixed>> $before
     * @param array<string, array<mixed>> $after
     */
    private function diff(array $before, array $after): ChangeReport
    {
        return (new ContractDiffer())->diff(['default' => self::document($before)], ['default' => self::document($after)]);
    }

    /**
     * @param array<string, array<mixed>> $schemas
     * @param list<mixed>                 $getSecurity
     * @param array<string, mixed>        $postExtra
     *
     * @return array<string, mixed>
     */
    private static function document(array $schemas, array $getSecurity = [['ApiKey' => []]], array $postExtra = []): array
    {
        $order = ['200' => ['description' => 'Success.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Order']]]]];

        return [
            'openapi' => '3.1.0',
            'paths' => ['/orders' => [
                'get' => ['operationId' => 'list', 'responses' => $order, 'security' => $getSecurity],
                'post' => [
                    'operationId' => 'create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/OrderInput']]]],
                    'responses' => $order,
                ] + $postExtra,
            ]],
            'components' => ['schemas' => $schemas],
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string>         $required
     *
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required = []): array
    {
        return array_filter(['type' => 'object', 'properties' => $properties, 'required' => $required], static fn (mixed $value): bool => [] !== $value);
    }

    /**
     * @return list<array{string, string, string, list<string>}>
     */
    private static function rows(ChangeReport $report): array
    {
        return array_map(static fn (Change $change): array => [$change->kind->value, $change->element, $change->message, $change->operations], $report->changes);
    }
}
