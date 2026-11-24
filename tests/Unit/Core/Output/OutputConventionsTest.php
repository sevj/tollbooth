<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core\Output;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Output\OutputRenderer;
use Tollbooth\Core\Output\ResourceTypeResolver;
use Tollbooth\Tests\Fixtures\Model\Color;
use Tollbooth\Tests\Fixtures\Model\ThingView;

final class OutputConventionsTest extends TestCase
{
    /**
     * @return iterable<string, array{NameCase, string, string}>
     */
    public static function paths(): iterable
    {
        yield 'snake nested' => [NameCase::SnakeCase, 'items[0].unitPrice', 'items[0].unit_price'];
        yield 'camel nested' => [NameCase::CamelCase, 'shipping_address.zip_code', 'shippingAddress.zipCode'];
        yield 'preserve' => [NameCase::Preserve, 'items[0].unitPrice', 'items[0].unitPrice'];
    }

    #[DataProvider('paths')]
    public function testPropertyPathsFollowTheCase(NameCase $case, string $path, string $expected): void
    {
        self::assertSame($expected, $case->applyToPath($path));
    }

    /**
     * @return iterable<string, array{NameCase, object, string}>
     */
    public static function resourceTypes(): iterable
    {
        yield 'view suffix' => [NameCase::SnakeCase, new ThingView(1, 'a', Color::Red), 'things'];
        yield 'compound snake' => [NameCase::SnakeCase, new OrderLineView(), 'order_lines'];
        yield 'compound camel' => [NameCase::CamelCase, new OrderLineView(), 'orderLines'];
        yield 'irregular plural' => [NameCase::SnakeCase, new Category(), 'categories'];
    }

    #[DataProvider('resourceTypes')]
    public function testResourceTypeIsThePluralOfTheClassName(NameCase $case, object $resource, string $expected): void
    {
        self::assertSame($expected, (new ResourceTypeResolver($case))->typeOf($resource));
    }

    public function testDataEnvelopeWrapsThePayload(): void
    {
        $response = $this->renderer(Envelope::Data)->render(['a' => 1], 200);

        self::assertSame('{"data":{"a":1}}', $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testJsonApiFallsBackToMetaWithoutAnIdentifier(): void
    {
        $response = $this->renderer(Envelope::JsonApi)->render(['total' => 3], 200);

        self::assertSame('{"meta":{"total":3}}', $response->getContent());
    }

    public function testJsonApiEmptyListIsAnEmptyCollection(): void
    {
        self::assertSame('{"data":[]}', $this->renderer(Envelope::JsonApi)->render([], 200)->getContent());
    }

    public function testNullAnswersNoContentUnlessAnotherStatusIsDeclared(): void
    {
        self::assertSame(204, $this->renderer(Envelope::Plain)->render(null, 200)->getStatusCode());
        self::assertSame(202, $this->renderer(Envelope::Plain)->render(null, 202)->getStatusCode());
    }

    private function renderer(Envelope $envelope): OutputRenderer
    {
        $serializer = new Serializer([new BackedEnumNormalizer(), new ObjectNormalizer()], [new JsonEncoder()]);

        return new OutputRenderer($serializer, $envelope, new ResourceTypeResolver(NameCase::SnakeCase));
    }
}

final class OrderLineView
{
}

final class Category
{
}
