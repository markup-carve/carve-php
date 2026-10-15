<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;

/**
 * An unresolved cross-reference renders as its literal `</#id>` source, so an
 * importer reads that run back as a cross-reference. A run whose id resolves in
 * the converted document, or whose `<` is escaped, stays text.
 */
final class ImportedCrossrefs
{
    /**
     * @var string
     */
    public const RUN = '/(?<!\\\\)<\/#([^ >\r\n]+)>/';

    /**
     * @return array<string, true>
     */
    public static function resolvedTargets(string $carve): array
    {
        if (!str_contains($carve, '</#')) {
            return [];
        }
        $resolved = [];
        $visit = static function (array $node) use (&$visit, &$resolved): void {
            if (($node['type'] ?? null) === 'heading_ref' && isset($node['href']) && is_string($node['target'] ?? null)) {
                $resolved[$node['target']] = true;
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $visit($value);
                }
            }
        };
        $visit((new AstCodec())->encode((new CarveConverter())->parse($carve)));

        return $resolved;
    }

    /**
     * @param string $value
     * @param array<string, true> $resolved
     *
     * @return list<array{type: string, ...<string, mixed>}>|null The text split into text and heading_ref nodes, or null when nothing splits.
     */
    public static function splitText(string $value, array $resolved): ?array
    {
        if (!str_contains($value, '</#') || preg_match_all(self::RUN, $value, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }
        $nodes = [];
        $cursor = 0;
        foreach ($matches[0] as $index => [$run, $offset]) {
            $target = $matches[1][$index][0];
            if (isset($resolved[$target])) {
                continue;
            }
            if ($offset > $cursor) {
                $nodes[] = HtmlImportNodes::text(substr($value, $cursor, $offset - $cursor));
            }
            $nodes[] = ['type' => 'heading_ref', 'target' => $target];
            $cursor = $offset + strlen($run);
        }
        if ($cursor === 0) {
            return null;
        }
        if ($cursor < strlen($value)) {
            $nodes[] = HtmlImportNodes::text(substr($value, $cursor));
        }

        return $nodes;
    }
}
