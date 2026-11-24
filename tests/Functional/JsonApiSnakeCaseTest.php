<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

final class JsonApiSnakeCaseTest extends FunctionalTestCase
{
    public function testSingleResourceIsWrappedAsJsonApi(): void
    {
        $client = self::createClient(['environment' => 'json_api']);
        $client->request('GET', '/api/things/7');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.api+json');
        self::assertSame([
            'data' => [
                'type' => 'things',
                'id' => '7',
                'attributes' => ['name' => 'Thing 7', 'color' => 'red', 'note' => null, 'unit_count' => 0],
            ],
        ], $this->json($client));
    }

    public function testListIsACollectionOfResources(): void
    {
        $client = self::createClient(['environment' => 'json_api']);
        $client->request('GET', '/api/things?page=2');

        $data = $this->json($client)['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertIsArray($data[0]);
        self::assertSame(['things', '20'], [$data[0]['type'], $data[0]['id']]);
    }

    public function testSnakeCaseBodyIsReadIntoCamelCaseProperties(): void
    {
        $client = self::createClient(['environment' => 'json_api']);
        $client->jsonRequest('POST', '/api/things', ['name' => 'Gate', 'quantity' => 2, 'unit_count' => 3]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->json($client)['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['attributes']);
        self::assertSame(3, $data['attributes']['unit_count']);
    }

    public function testViolationFieldsFollowTheCase(): void
    {
        $client = self::createClient(['environment' => 'json_api']);
        $client->jsonRequest('POST', '/api/things', ['name' => 'Gate', 'quantity' => 2, 'unit_count' => -1]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(['unit_count'], $this->fields($this->json($client)));
    }
}
