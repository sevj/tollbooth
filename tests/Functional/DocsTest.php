<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DocsTest extends FunctionalTestCase
{
    public function testDocumentDescribesPathsParametersAndResponses(): void
    {
        $document = $this->document('test', 'default');

        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame([['url' => '/api']], $document['servers']);

        $get = $this->at($document, 'paths', '/things/{id}', 'get');
        self::assertSame('tollbooth_get_thing', $get['operationId']);
        self::assertSame('Get thing', $get['summary']);
        self::assertSame(['things'], $get['tags']);
        self::assertSame([
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ['name' => 'note', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
        ], $get['parameters']);
        self::assertSame(['$ref' => '#/components/schemas/ThingView'], $this->at($get, 'responses', '200', 'content', 'application/json', 'schema'));
        self::assertSame(['200', '304', '400', '404'], array_map(strval(...), array_keys($this->at($get, 'responses'))));
        self::assertSame(['ETag'], array_keys($this->at($get, 'responses', '200', 'headers')));
        self::assertSame(['type' => 'string', 'format' => 'binary'], $this->at($document, 'paths', '/things/{id}/file', 'get', 'responses', '200', 'content', 'application/octet-stream', 'schema'));
        self::assertArrayNotHasKey('security', $get);
    }

    public function testSchemasComeFromTypesDocblocksAndConstraints(): void
    {
        $document = $this->document('test', 'default');

        $view = $this->at($document, 'components', 'schemas', 'ThingView');
        self::assertSame(['id', 'name', 'color', 'unitCount'], $view['required']);
        self::assertSame(['title' => 'Color', 'type' => 'string', 'enum' => ['red', 'amber']], $this->at($view, 'properties', 'color'));
        self::assertSame(['type' => ['string', 'null']], $this->at($view, 'properties', 'note'));

        $input = $this->at($document, 'components', 'schemas', 'CreateThingInput');
        self::assertArrayNotHasKey('required', $input);
        self::assertSame(['type' => 'string', 'minLength' => 1, 'default' => ''], $this->at($input, 'properties', 'name'));
        self::assertSame(['type' => 'integer', 'exclusiveMinimum' => 0, 'default' => 0], $this->at($input, 'properties', 'quantity'));

        $list = $this->at($document, 'paths', '/things', 'get');
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ThingView']], $this->at($list, 'responses', '200', 'content', 'application/json', 'schema'));
        self::assertSame(['type' => 'integer', 'default' => 1], $this->at($list, 'parameters', '0', 'schema'));
    }

    public function testBodyAndVoidEndpoints(): void
    {
        $document = $this->document('test', 'default');

        $post = $this->at($document, 'paths', '/things', 'post');
        self::assertSame(['$ref' => '#/components/schemas/CreateThingInput'], $this->at($post, 'requestBody', 'content', 'application/json', 'schema'));
        self::assertSame(['201', '400', '415', '422'], array_map(strval(...), array_keys($this->at($post, 'responses'))));
        self::assertSame(['$ref' => '#/components/schemas/ValidationProblem'], $this->at($document, 'components', 'responses', 'ValidationFailed', 'content', 'application/problem+json', 'schema'));

        $delete = $this->at($document, 'paths', '/things/{id}', 'delete');
        self::assertSame(['description' => 'No content.'], $this->at($delete, 'responses', '204'));
    }

    public function testJsonApiResponsesAreResourceDocumentsInTheGroupCase(): void
    {
        $document = $this->document('json_api', 'default');

        $data = $this->at($document, 'paths', '/things/{id}', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'schema', 'properties', 'data');
        self::assertSame(['type' => 'string', 'const' => 'things'], $this->at($data, 'properties', 'type'));
        self::assertSame(['$ref' => '#/components/schemas/ThingViewAttributes'], $this->at($data, 'properties', 'attributes'));

        $attributes = $this->at($document, 'components', 'schemas', 'ThingViewAttributes', 'properties');
        self::assertSame(['name', 'color', 'note', 'unit_count'], array_keys($attributes));
    }

    public function testSecurityFollowsTheResolvedAuthAndScopes(): void
    {
        $document = $this->document('auth', 'secured');

        self::assertSame(['ApiKey', 'Session'], array_keys($this->at($document, 'components', 'securitySchemes')));
        self::assertSame(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'], $this->at($document, 'components', 'securitySchemes', 'ApiKey'));
        self::assertSame([['ApiKey' => ['me:read']], ['Session' => []]], $this->at($document, 'paths', '/secure/me', 'get', 'security'));

        $read = $this->at($document, 'paths', '/secure/things/{id}', 'get');
        self::assertSame([['ApiKey' => ['things:read']]], $read['security']);
        self::assertSame(['200', '304', '401', '403', '404'], array_map(strval(...), array_keys($this->at($read, 'responses'))));

        $status = $this->at($document, 'paths', '/secure/status', 'get');
        self::assertArrayNotHasKey('security', $status);
    }

    public function testRateLimitedEndpointsDocumentTheHeadersAndThe429(): void
    {
        $document = $this->document('rate_limit', 'default');

        $search = $this->at($document, 'paths', '/search', 'get');
        self::assertSame(['$ref' => '#/components/responses/TooManyRequests'], $this->at($search, 'responses', '429'));
        self::assertSame(['RateLimit-Policy', 'RateLimit', 'ETag'], array_keys($this->at($search, 'responses', '200', 'headers')));
        self::assertSame([['name' => 'search.minute', 'limit' => 2, 'window' => 60]], $search['x-rate-limits']);
        self::assertSame(['$ref' => '#/components/schemas/RateLimitProblem'], $this->at($document, 'components', 'responses', 'TooManyRequests', 'content', 'application/problem+json', 'schema'));
    }

    public function testDocumentationPageLoadsTheReference(): void
    {
        $client = self::createClient(['environment' => 'groups']);
        $client->request('GET', '/_tollbooth/docs/partner');

        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-url="/_tollbooth/openapi/partner.json"', $html);
        self::assertStringContainsString('@scalar/api-reference@1.77.0', $html);
        self::assertStringContainsString('<a href="/_tollbooth/docs">default</a>', $html);
    }

    public function testUnknownGroupIsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/openapi/nope.json');

        self::assertResponseStatusCodeSame(404);
    }

    public function testExportCommandPrintsTheDocument(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester((new Application($kernel))->find('tollbooth:openapi:export'));

        self::assertSame(0, $tester->execute(['--yaml' => true]));
        self::assertStringStartsWith("openapi: 3.1.0\n", $tester->getDisplay());
    }

    /**
     * @return array<mixed>
     */
    private function document(string $environment, string $group): array
    {
        $client = self::createClient(['environment' => $environment]);
        $client->request('GET', '/_tollbooth/openapi/'.$group.'.json');
        self::assertResponseStatusCodeSame(200);

        return $this->json($client);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function at(array $data, string ...$keys): array
    {
        foreach ($keys as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data, 'Missing key '.$key);
            $data = $data[$key];
        }
        self::assertIsArray($data);

        return $data;
    }
}
