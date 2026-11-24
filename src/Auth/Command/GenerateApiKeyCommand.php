<?php

declare(strict_types=1);

namespace Tollbooth\Auth\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Auth\ApiKey\ApiKeyFormat;

/**
 * @internal
 */
#[AsCommand('tollbooth:api-key:generate', 'Generate an API key and print its configuration')]
final class GenerateApiKeyCommand
{
    /**
     * @param list<string> $scope
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Name of the consumer, for instance "acme-erp"')] string $name,
        #[Option('Scope granted to the key, repeatable')] array $scope = [],
        #[Option('Plan of the consumer')] ?string $plan = null,
        #[Option('Generate a tb_test_ key instead of a tb_live_ one')] bool $test = false,
    ): int {
        $generated = ApiKeyFormat::generate(!$test);

        $io->success('Give this key to the consumer now. It is not stored and cannot be shown again.');
        $io->writeln('  '.$generated['key']);
        $io->newLine();
        $io->writeln('Add it to config/packages/tollbooth.yaml:');
        $io->newLine();
        $io->writeln(implode("\n", array_filter([
            'tollbooth:',
            '    auth:',
            '        api_keys:',
            \sprintf('            %s:', $name),
            \sprintf('                id: %s', $generated['id']),
            \sprintf('                hash: %s', $generated['hash']),
            [] === $scope ? null : \sprintf('                scopes: [%s]', implode(', ', $scope)),
            null === $plan ? null : \sprintf('                plan: %s', $plan),
        ])));

        return Command::SUCCESS;
    }
}
