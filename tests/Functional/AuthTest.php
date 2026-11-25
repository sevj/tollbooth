<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tollbooth\Tests\Fixtures\TestKeys;

final class AuthTest extends FunctionalTestCase
{
    public function testMissingCredentialsAnswerUnauthorizedWithAChallenge(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/secure/things/7');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'ApiKey header="X-Api-Key"');
        self::assertSame('Authentication is required to access this endpoint.', $this->json($client)['detail']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedKeys(): iterable
    {
        yield 'malformed' => ['not-a-key', 'The API key is not valid.'];
        yield 'unknown id' => ['tb_test_unknown00001_RRRRRRRRRRRRRRRRRRRRRRRRRRRRRRRR', 'The API key is not valid.'];
        yield 'wrong secret' => ['tb_test_reader000001_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', 'The API key is not valid.'];
        yield 'expired' => [TestKeys::EXPIRED, 'The API key has expired.'];
        yield 'outside allowed IPs' => [TestKeys::RESTRICTED, 'The API key is not allowed from this address.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedKeys')]
    public function testRejectedKeyAnswersUnauthorized(string $key, string $detail): void
    {
        $client = $this->client();
        $client->request('GET', '/api/secure/things/7', server: ['HTTP_X_API_KEY' => $key]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame($detail, $this->json($client)['detail']);
    }

    public function testValidKeyWithTheScopeIsLetThrough(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/secure/things/7', server: ['HTTP_X_API_KEY' => TestKeys::READER]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testMissingScopeAnswersForbidden(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/secure/things', server: ['HTTP_X_API_KEY' => TestKeys::READER]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('This endpoint requires the scope "things:write".', $this->json($client)['detail']);
    }

    public function testAnonymousCallsCannotSkipTheScopesOfAnEndpointThatAlsoAcceptsThem(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/secure/catalog');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'ApiKey header="X-Api-Key"');
        self::assertSame('Authentication is required: this endpoint requires the scope "things:read".', $this->json($client)['detail']);

        $client->request('GET', '/api/secure/catalog', server: ['HTTP_X_API_KEY' => TestKeys::READER]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testConsumerIsInjectedIntoTheEndpoint(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/secure/me', server: ['HTTP_X_API_KEY' => TestKeys::READER]);

        self::assertResponseStatusCodeSame(200, (string) $client->getResponse()->getContent());
        self::assertSame(['id' => 'reader000001', 'name' => 'reader', 'method' => 'api_key', 'plan' => 'pro'], $this->json($client));
    }

    public function testKeyNamesKeepTheirDashes(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/secure/me', server: ['HTTP_X_API_KEY' => TestKeys::ACME]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('acme-erp', $this->json($client)['name']);
    }

    public function testSymfonyUserIsAcceptedWhereTheEndpointAllowsIt(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/secure/me', server: ['PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'secret']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['id' => 'alice', 'name' => 'alice', 'method' => 'user', 'plan' => null], $this->json($client));
    }

    public function testSymfonyUserIsRefusedWhereOnlyApiKeysAreAccepted(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/secure/things/7', server: ['PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'secret']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testPublicEndpointNeedsNoCredentials(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/secure/status');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok', 'consumer' => 'ip:127.0.0.1'], $this->json($client));
    }

    public function testDefaultMethodAppliesOutsideTheGroup(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/things/7');

        self::assertResponseStatusCodeSame(200);
    }

    private function client(): KernelBrowser
    {
        return self::createClient(['environment' => 'auth']);
    }
}
