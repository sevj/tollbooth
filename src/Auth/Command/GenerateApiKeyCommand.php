<?php

declare(strict_types=1);

namespace Tollbooth\Auth\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tollbooth\Auth\ApiKey\ApiKey;
use Tollbooth\Auth\ApiKey\ApiKeyFormat;
use Tollbooth\Auth\ApiKey\ManageableApiKeyProviderInterface;
use Tollbooth\Core\RateLimit\PlanCatalogInterface;

/**
 * @internal
 */
#[AsCommand('tollbooth:api-key:generate', 'Generate an API key and print its configuration')]
final readonly class GenerateApiKeyCommand
{
    public function __construct(
        private ?ManageableApiKeyProviderInterface $store = null,
        private ?PlanCatalogInterface $plans = null,
    ) {
    }

    /**
     * @param list<string> $scope
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Name of the consumer, for instance "acme-erp"')] string $name,
        #[Option('Scope granted to the key, repeatable')] array $scope = [],
        #[Option('Plan of the consumer')] ?string $plan = null,
        #[Option('Generate a tb_test_ key instead of a tb_live_ one')] bool $test = false,
        #[Option('Save the key in the database instead of printing its configuration')] bool $store = false,
    ): int {
        if (null !== $plan && null !== $this->plans && !\in_array($plan, $this->plans->names(), true)) {
            $io->error(\sprintf('There is no plan "%s". Known plans: %s.', $plan, implode(', ', $this->plans->names()) ?: 'none'));

            return Command::FAILURE;
        }

        $generated = ApiKeyFormat::generate(!$test);

        if ($store) {
            if (null === $this->store) {
                $io->error('Keys are not stored in a database. Enable tollbooth.auth.doctrine_keys, or use a key provider implementing ManageableApiKeyProviderInterface.');

                return Command::FAILURE;
            }
            foreach ($this->store->all() as $existing) {
                if (0 === strcasecmp($existing->name, $name)) {
                    $io->error(\sprintf('A key is already named "%s". Pick another name, or rotate that key from the dashboard.', $existing->name));

                    return Command::FAILURE;
                }
            }
            $this->store->save(new ApiKey($generated['id'], $name, $generated['hash'], $scope, $plan, createdAt: new \DateTimeImmutable(), test: $test));
            $io->success('The key is saved. Give it to the consumer now: it cannot be shown again.');
            $io->writeln('  '.$generated['key']);

            return Command::SUCCESS;
        }

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
            $test ? '                test: true' : null,
        ])));

        return Command::SUCCESS;
    }
}
