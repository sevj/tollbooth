<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

final class EndpointTest extends FunctionalTestCase
{
    public function testRouteParameterIsBoundAndOutputSerialized(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/things/7?note=hello');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['id' => 7, 'name' => 'Thing 7', 'color' => 'red', 'note' => 'hello', 'unitCount' => 0], $this->json($client));
    }

    public function testNonNumericIdDoesNotMatchTheRoute(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/things/abc');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testWrongMethodUnderThePrefixAnswersAProblemWithAllow(): void
    {
        $client = self::createClient();
        $client->request('PUT', '/api/things/7');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('Allow', 'DELETE, GET');
        self::assertSame(['type' => 'about:blank', 'title' => 'Method Not Allowed', 'status' => 405], $this->json($client));
    }

    public function testQueryParametersAreCastToTheirTypes(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/things?page=3&color=red');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['id' => 30, 'name' => 'Page 3', 'color' => 'red', 'note' => null, 'unitCount' => 0]], $this->json($client));
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
        $client->jsonRequest('POST', '/api/things', ['name' => 'Gate', 'quantity' => 2, 'color' => 'red', 'unitCount' => 3]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['id' => 1, 'name' => 'Gate', 'color' => 'red', 'note' => null, 'unitCount' => 3], $this->json($client));
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
        self::assertSame(['name', 'quantity'], $this->fields($problem));
    }

    public function testWrongTypeInBodyAnswersValidationProblem(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/things', ['name' => 'Gate', 'quantity' => 'two']);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->json($client);
        self::assertSame(['quantity'], $this->fields($problem));
        self::assertIsArray($problem['violations']);
        self::assertSame(['field' => 'quantity', 'message' => 'This value should be of type int.'], $problem['violations'][0]);
    }

    public function testMalformedBodyAnswersBadRequestProblem(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/things', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('The request body is not valid JSON.', $this->json($client)['detail']);
    }

    public function testNonJsonBodyIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/things', server: ['CONTENT_TYPE' => 'text/plain'], content: 'name=Gate');

        self::assertResponseStatusCodeSame(415);
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
}
