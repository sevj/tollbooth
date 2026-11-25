<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tollbooth\Core\Metadata\Limit;
use Tollbooth\Dashboard\Configuration\PlanForm;
use Tollbooth\Dashboard\Configuration\Settings;

final class ConfigurationTest extends TestCase
{
    public function testLimitsAreReadOneLinePerLimit(): void
    {
        $form = PlanForm::submit(self::post(['name' => ' Partner ', 'limits' => "burst: 10/10 seconds\r\n\n600/minute\n1000/day"]), null, ['free']);

        self::assertSame([], $form->errors);
        self::assertSame('partner', $form->name);
        self::assertSame(['burst' => 10, 'minute' => 60, 'day' => 86400], array_combine(array_map(static fn (Limit $limit): string => $limit->name, $form->limits), array_map(static fn (Limit $limit): int => $limit->window, $form->limits)));
    }

    public function testInvalidPlansAreReported(): void
    {
        $form = PlanForm::submit(self::post(['name' => 'free', 'limits' => "60/minute\nsometimes"]), null, ['free']);
        self::assertSame(['name' => 'name_taken', 'limits' => 'limits_format'], $form->errors);
        self::assertSame('sometimes', $form->invalidLine);

        self::assertSame(['name' => 'name_taken', 'limits' => 'limits_required'], PlanForm::submit(self::post(['name' => 'new']), null, [])->errors);
        self::assertSame(['limits' => 'limits_duplicate'], PlanForm::submit(self::post(['limits' => "60/minute\n100/minute"]), 'gold', [])->errors);
    }

    public function testStoredLimitsAreWrittenBackTheWayTheyAreTyped(): void
    {
        $limits = array_map(static fn (Limit $limit): array => $limit->toArray(), Limit::parseAll(['burst' => '10/10 seconds', '600/minute', '20/2 hours', 'x' => '5/90 seconds'], 'plan'));

        self::assertSame("burst: 10/10 seconds\n600/minute\n20/2 hours\nx: 5/90 seconds", PlanForm::of('gold', $limits)->rawLimits);
    }

    public function testSettingsListTheConfigurationBySectionWithoutPlansOrKeys(): void
    {
        $settings = new Settings([
            'path_prefix' => '/api',
            'groups' => ['shop' => ['prefix' => '/v2', 'output' => ['envelope' => 'json_api', 'case' => null]], 'admin' => ['prefix' => '/admin']],
            'auth' => ['default' => ['api_key', 'user'], 'api_keys' => ['acme' => ['hash' => 'secret']]],
            'rate_limit' => ['enabled' => true, 'plans' => ['free' => ['60/minute']], 'legacy_headers' => false],
            'custom' => ['flag' => true],
        ]);

        self::assertSame([
            ['section' => 'general', 'rows' => [['key' => 'tollbooth.path_prefix', 'value' => '/api']]],
            ['section' => 'group', 'rows' => [['key' => 'tollbooth.groups.shop.prefix', 'value' => '/v2'], ['key' => 'tollbooth.groups.shop.output.envelope', 'value' => 'json_api'], ['key' => 'tollbooth.groups.shop.output.case', 'value' => '~']]],
            ['section' => 'auth', 'rows' => [['key' => 'tollbooth.auth.default', 'value' => 'api_key, user']]],
            ['section' => 'rate_limit', 'rows' => [['key' => 'tollbooth.rate_limit.enabled', 'value' => 'true'], ['key' => 'tollbooth.rate_limit.legacy_headers', 'value' => 'false']]],
            ['section' => 'custom', 'rows' => [['key' => 'tollbooth.custom.flag', 'value' => 'true']]],
        ], $settings->sections('shop'));
    }

    /**
     * @param array<string, string> $body
     */
    private static function post(array $body): Request
    {
        return Request::create('http://localhost/_tollbooth/configuration/shop/plans/new', 'POST', $body);
    }
}
