<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core\Metadata;

use PHPUnit\Framework\TestCase;
use Tollbooth\Core\Metadata\Group;
use Tollbooth\Core\Metadata\GroupResolver;
use Tollbooth\Core\Metadata\InvalidEndpointException;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;

final class GroupResolverTest extends TestCase
{
    private GroupResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new GroupResolver(new Group(Group::DEFAULT, Envelope::Plain, NameCase::Preserve), [
            'partner' => self::group(namespace: 'App\\Api\\Partner'),
            'partner_v2' => self::group(namespace: 'App\\Api\\Partner\\V2', envelope: 'json_api'),
            'admin' => self::group(path: '/admin', case: 'snake_case'),
            'admin_users' => self::group(path: '/admin/users/'),
        ]);
    }

    public function testLongestNamespaceWins(): void
    {
        self::assertSame('partner', $this->resolver->resolve('App\\Api\\Partner\\GetOrder', '/orders', null)->name);
        self::assertSame('partner_v2', $this->resolver->resolve('App\\Api\\Partner\\V2\\GetOrder', '/orders', null)->name);
    }

    public function testNamespaceMatchesWholeSegmentsOnly(): void
    {
        self::assertSame(Group::DEFAULT, $this->resolver->resolve('App\\Api\\PartnerTools\\Ping', '/ping', null)->name);
    }

    public function testLongestPathWinsWhenNoNamespaceMatches(): void
    {
        self::assertSame('admin', $this->resolver->resolve('App\\Api\\Stats', '/admin/stats', null)->name);
        self::assertSame('admin_users', $this->resolver->resolve('App\\Api\\Users', '/admin/users/{id}', null)->name);
        self::assertSame(Group::DEFAULT, $this->resolver->resolve('App\\Api\\Users', '/administrators', null)->name);
    }

    public function testNamespaceWinsOverPath(): void
    {
        self::assertSame('partner', $this->resolver->resolve('App\\Api\\Partner\\Stats', '/admin/stats', null)->name);
    }

    public function testExplicitGroupWins(): void
    {
        self::assertSame('admin', $this->resolver->resolve('App\\Api\\Partner\\Stats', '/stats', 'admin')->name);
        self::assertSame(Group::DEFAULT, $this->resolver->resolve('App\\Api\\Partner\\Stats', '/stats', 'default')->name);
    }

    public function testUnknownExplicitGroupIsRejected(): void
    {
        $this->expectException(InvalidEndpointException::class);
        $this->expectExceptionMessage('unknown group "billing"');

        $this->resolver->resolve('App\\Api\\Stats', '/stats', 'billing');
    }

    public function testUnsetConventionsInheritTheGlobalOnes(): void
    {
        $group = $this->resolver->resolve('App\\Api\\Stats', '/admin/stats', null);

        self::assertSame(Envelope::Plain, $group->envelope);
        self::assertSame(NameCase::SnakeCase, $group->case);
    }

    /**
     * @return array{namespace: ?string, path: ?string, prefix: string, output: array{envelope: ?string, case: ?string}}
     */
    private static function group(?string $namespace = null, ?string $path = null, ?string $envelope = null, ?string $case = null): array
    {
        return ['namespace' => $namespace, 'path' => $path, 'prefix' => '', 'output' => ['envelope' => $envelope, 'case' => $case]];
    }
}
