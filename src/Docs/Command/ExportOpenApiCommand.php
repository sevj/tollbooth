<?php

declare(strict_types=1);

namespace Tollbooth\Docs\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Tollbooth\Core\Metadata\Group;
use Tollbooth\Docs\OpenApiGenerator;

/**
 * @internal
 */
#[AsCommand('tollbooth:openapi:export', 'Print or write the OpenAPI document of a group')]
final readonly class ExportOpenApiCommand
{
    public function __construct(
        private OpenApiGenerator $generator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Group to export')] string $group = Group::DEFAULT,
        #[Option('File to write instead of printing')] ?string $output = null,
        #[Option('Write YAML instead of JSON')] bool $yaml = false,
    ): int {
        $document = $this->generator->generate($group);
        if (null === $document) {
            $io->error(\sprintf('No endpoint belongs to the group "%s". Groups: "%s".', $group, implode('", "', $this->generator->groups())));

            return Command::FAILURE;
        }

        $content = $yaml
            ? Yaml::dump(json_decode(json_encode($document, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR), 12, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP)
            : json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";

        if (null === $output) {
            $io->write($content);

            return Command::SUCCESS;
        }

        (new Filesystem())->dumpFile($output, $content);
        $io->success(\sprintf('OpenAPI document of "%s" written to %s.', $group, $output));

        return Command::SUCCESS;
    }
}
