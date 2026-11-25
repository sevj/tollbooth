<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Map;

/**
 * @internal
 */
final class MermaidExporter
{
    public static function export(ApiMap $map): string
    {
        $lines = ['flowchart LR'];
        $ids = [];
        foreach ($map->resources as $index => $resource) {
            $id = 'r'.$index;
            $ids[$resource->name] = $id;
            $lines[] = \sprintf('  subgraph %s["%s"]', $id, self::text($resource->title));
            foreach ($resource->endpoints as $position => $endpoint) {
                $lines[] = \sprintf('    %s_%d["%s %s"]', $id, $position, $endpoint->metadata->method, self::text($endpoint->path));
            }
            $lines[] = '  end';
        }

        foreach ($map->references as $reference) {
            if (isset($ids[$reference->from], $ids[$reference->to])) {
                $lines[] = \sprintf('  %s -.->|%s| %s', $ids[$reference->from], self::text($reference->label), $ids[$reference->to]);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function text(string $text): string
    {
        return str_replace(['"', '|'], ['#quot;', '#124;'], $text);
    }
}
