<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core\Output;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Output\OutputRenderer;
use Tollbooth\Core\Output\ResourceTypeResolver;
use Tollbooth\Core\Output\SerializerLocator;
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
        self::assertSame($expected, (new ResourceTypeResolver())->typeOf($resource, $case));
    }

    public function testDataEnvelopeWrapsThePayload(): void
    {
        $response = $this->render(Envelope::Data, ['a' => 1], 200);

        self::assertSame('{"data":{"a":1}}', $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testJsonApiFallsBackToMetaWithoutAnIdentifier(): void
    {
        $response = $this->render(Envelope::JsonApi, ['total' => 3], 200);

        self::assertSame('{"meta":{"total":3}}', $response->getContent());
    }

    public function testJsonApiEmptyListIsAnEmptyCollection(): void
    {
        self::assertSame('{"data":[]}', $this->render(Envelope::JsonApi, [], 200)->getContent());
    }

    public function testNullAnswersNoContentUnlessAnotherStatusIsDeclared(): void
    {
        self::assertSame(204, $this->render(Envelope::Plain, null, 200)->getStatusCode());
        self::assertSame(202, $this->render(Envelope::Plain, null, 202)->getStatusCode());
        self::assertSame('application/vnd.api+json', $this->render(Envelope::JsonApi, null, 202)->headers->get('Content-Type'));
        self::assertFalse($this->render(Envelope::Plain, null, 200)->headers->has('Content-Type'));
    }

    private function render(Envelope $envelope, mixed $result, int $status): Response
    {
        $serializer = new Serializer([new BackedEnumNormalizer(), new ObjectNormalizer()], [new JsonEncoder()]);
        $renderer = new OutputRenderer(new SerializerLocator(new ServiceLocator(['snake_case' => static fn () => $serializer])), new ResourceTypeResolver());

        return $renderer->render($result, $status, $envelope, NameCase::SnakeCase);
    }
}

final class OrderLineView
{
}

final class Category
{
}
