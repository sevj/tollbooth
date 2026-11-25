<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Tollbooth\Tests\Fixtures\TestKernel;

final class ChangesTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        (new Filesystem())->remove(TestKernel::contractDirectory());
    }

    public function testDeprecatedEndpointAnnouncesItsSunset(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/things/7');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Deprecation', '@1780272000');
        self::assertResponseHeaderSame('Sunset', 'Mon, 01 Mar 2027 00:00:00 GMT');
        self::assertResponseHeaderSame('Link', '<https://example.com/migrate-to-v2>; rel="deprecation"');
    }

    public function testCurrentEndpointsCarryNoDeprecationHeader(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/things/7');

        self::assertResponseNotHasHeader('Deprecation');
    }

    public function testDocumentMarksTheOperationDeprecated(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_tollbooth/openapi/default.json');

        $operation = self::path($this->json($client), 'paths', '/v1/things/{id}', 'get');
        self::assertIsArray($operation);
        self::assertTrue($operation['deprecated']);
        self::assertSame('2027-03-01', $operation['x-sunset']);
    }

    public function testDiffAgainstAFreshSnapshotFindsNothing(): void
    {
        self::assertSame(0, $this->command('tollbooth:contract:snapshot', ['version' => '1.4.0'])->getStatusCode());
        self::assertFileExists(TestKernel::contractDirectory().'/1.4.0.json');

        $diff = $this->command('tollbooth:diff');
        self::assertSame(0, $diff->getStatusCode());
        self::assertStringContainsString('No change.', $diff->getDisplay());
    }

    public function testSnapshotRefusesToOverwriteWithoutForce(): void
    {
        $this->command('tollbooth:contract:snapshot', ['version' => '1.4.0']);

        self::assertSame(1, $this->command('tollbooth:contract:snapshot', ['version' => '1.4.0'])->getStatusCode());
        self::assertSame(0, $this->command('tollbooth:contract:snapshot', ['version' => '1.4.0', '--force' => true])->getStatusCode());
    }

    public function testBreakingChangesFailTheBuild(): void
    {
        $this->command('tollbooth:contract:snapshot', ['version' => '1.4.0']);
        $this->editSnapshot('1.4.0', static function (array $snapshot): array {
            $snapshot = self::withPath($snapshot, ['groups', 'default', 'paths', '/gone'], ['get' => ['operationId' => 'tollbooth_gone', 'responses' => ['200' => ['description' => 'Success.']]]]);
            $snapshot = self::withPath($snapshot, ['groups', 'default', 'paths', '/things', 'get'], null, true);

            return self::withPath($snapshot, ['groups', 'default', 'components', 'schemas', 'ThingView', 'properties', 'stock'], ['type' => 'integer']);
        });

        $diff = $this->command('tollbooth:diff', ['--fail-on' => 'breaking']);
        $output = $diff->getDisplay();

        self::assertSame(1, $diff->getStatusCode());
        self::assertStringContainsString('BREAKING   GET /gone: The endpoint is removed.', $output);
        self::assertStringContainsString('BREAKING   ThingView.stock: Removed.', $output);
        self::assertStringContainsString('ADDED      GET /things: New endpoint.', $output);
        self::assertStringContainsString('next version 2.0.0', $output);
    }

    public function testMarkdownChangelogForConsumers(): void
    {
        $this->command('tollbooth:contract:snapshot', ['version' => '0.3.1']);
        $this->editSnapshot('0.3.1', static fn (array $snapshot): array => self::withPath($snapshot, ['groups', 'default', 'paths', '/things', 'post'], null, true));

        $markdown = $this->command('tollbooth:diff', ['--format' => 'markdown', '--fail-on' => 'none']);

        self::assertSame(0, $markdown->getStatusCode());
        self::assertStringStartsWith("## 0.4.0 (unreleased)\n\n### Added\n- `POST /things`: New endpoint.\n", $markdown->getDisplay());
    }

    public function testDiffNeedsASnapshot(): void
    {
        $diff = $this->command('tollbooth:diff');

        self::assertSame(1, $diff->getStatusCode());
        self::assertStringContainsString('No snapshot to compare with', $diff->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function command(string $name, array $input = []): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $tester = new CommandTester((new Application($kernel))->find($name));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param \Closure(array<mixed>): array<mixed> $edit
     */
    private function editSnapshot(string $version, \Closure $edit): void
    {
        $file = TestKernel::contractDirectory().'/'.$version.'.json';
        $snapshot = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        file_put_contents($file, json_encode($edit($snapshot), \JSON_THROW_ON_ERROR));
    }
}
