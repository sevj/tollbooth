<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tollbooth\Tests\Fixtures\TestKernel;

final class EndpointTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testRouteParameterIsBoundAndOutputSerialized(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/things/7?note=hello');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['id' => 7, 'name' => 'Thing 7', 'color' => 'red', 'note' => 'hello'], $this->json($client));
    }

    public function testNonNumericIdDoesNotMatchTheRoute(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/things/abc');

        self::assertResponseStatusCodeSame(404);
    }

    public function testQueryParametersAreCastToTheirTypes(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/things?page=3&color=red');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['id' => 30, 'name' => 'Page 3', 'color' => 'red', 'note' => null]], $this->json($client));
    }

    public function testInvalidQueryParameterAnswersBadRequestProblem(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/things?color=blue');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame([
            'type' => 'about:blank',
            'title' => 'Bad Request',
            'status' => 400,
            'detail' => 'The query parameter "color" must be of type one of "red", "amber".',
        ], $this->json($client));
    }

    public function testValidBodyIsHydratedWithTheDeclaredStatus(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/things', ['name' => 'Gate', 'quantity' => 2, 'color' => 'red']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['id' => 1, 'name' => 'Gate', 'color' => 'red', 'note' => null], $this->json($client));
    }

    public function testInvalidBodyAnswersValidationProblem(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/things', ['name' => '', 'quantity' => -1]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = $this->json($client);
        self::assertSame('Validation Failed', $problem['title']);
        self::assertSame(422, $problem['status']);
        self::assertIsArray($problem['violations']);
        self::assertSame(['name', 'quantity'], array_column($problem['violations'], 'field'));
    }

    public function testMalformedBodyAnswersBadRequestProblem(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/things', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testVoidResultAnswersNoContent(): void
    {
        $client = self::createClient();
        $client->request('DELETE', '/api/things/3');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());
    }

    public function testHttpExceptionKeepsItsStatusAndMessage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/things/404');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Thing 404 does not exist.', $this->json($client)['detail']);
    }

    public function testServerErrorHidesTheMessageOutsideDebug(): void
    {
        $client = self::createClient(['debug' => false]);
        $client->request('GET', '/api/explode');

        self::assertResponseStatusCodeSame(500);
        self::assertSame(['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500], $this->json($client));
    }

    /**
     * @return array<mixed>
     */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
