<?php

declare(strict_types=1);

namespace Tollbooth\Changes;

use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
final readonly class SnapshotStore
{
    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * @param array<string, array<mixed>> $documents
     */
    public function save(string $version, array $documents, \DateTimeImmutable $at, bool $overwrite = false): string
    {
        $file = $this->file($version);
        if (!$overwrite && is_file($file)) {
            throw new \RuntimeException(\sprintf('A snapshot of %s already exists in %s. Pass --force to replace it.', $version, $file));
        }

        (new Filesystem())->dumpFile($file, json_encode(
            ['version' => $version, 'created_at' => $at->format(\DATE_ATOM), 'groups' => $documents],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
        )."\n");

        return $file;
    }

    /**
     * @return array{version: string, created_at: string, groups: array<string, array<mixed>>}
     */
    public function load(string $versionOrFile): array
    {
        $file = is_file($versionOrFile) ? $versionOrFile : $this->file($versionOrFile);
        if (!is_file($file)) {
            throw new \RuntimeException(\sprintf('No snapshot "%s" in %s. Known: %s.', $versionOrFile, $this->directory, implode(', ', $this->versions()) ?: 'none'));
        }

        $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !\is_string($data['version'] ?? null) || !\is_array($data['groups'] ?? null)) {
            throw new \RuntimeException(\sprintf('%s is not a Tollbooth contract snapshot.', $file));
        }

        $groups = [];
        foreach ($data['groups'] as $group => $document) {
            if (\is_array($document)) {
                $groups[(string) $group] = $document;
            }
        }

        return ['version' => $data['version'], 'created_at' => \is_string($data['created_at'] ?? null) ? $data['created_at'] : '', 'groups' => $groups];
    }

    /**
     * @return list<string>
     */
    public function versions(): array
    {
        $versions = array_map(static fn (string $file): string => basename($file, '.json'), glob($this->directory.'/*.json') ?: []);
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return array_reverse($versions);
    }

    public function latest(): ?string
    {
        return $this->versions()[0] ?? null;
    }

    private function file(string $version): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
            throw new \InvalidArgumentException(\sprintf('"%s" cannot name a snapshot: use letters, digits, dots, dashes and underscores.', $version));
        }

        return rtrim($this->directory, '/').'/'.$version.'.json';
    }
}
