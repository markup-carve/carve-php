<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * The second half of the carve-js node rules (`lint/structure.ts`), one method per JS rule function, and the checks that run
 * after the walk.
 */
final class NodeRulesB
{
    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function commentInLabel(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node, $c);
        if (($node['type'] ?? null) !== 'comment' || ($node['form'] ?? null) !== 'line' || $pos === null) {
            return;
        }
        $parent = Support::parent($path);
        if ($parent !== null && ($c->nodeTypes[$parent] ?? null) === 'inline_footnote') {
            return;
        }
        $line = $c->lines[$pos['startLine'] - 1] ?? null;
        $range = Support::bounds($node);
        if ($line === null || $range === null) {
            return;
        }
        $before = substr($c->source, $line->start, max(0, $range[0] - $line->start));
        $open = strrpos($before, '[');
        if ($open === false || strpos($before, ']', $open) !== false) {
            return;
        }
        $c->report('comment-in-reference-label', $range[0], $range[1], $path);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function bracedSpanAsText(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'text') {
            return;
        }
        $split = Support::parent($path);
        $parent = $split === null ? null : ($c->nodeTypes[$split] ?? null);
        if ($parent !== 'strong' && $parent !== 'emphasis') {
            return;
        }
        $raw = Support::raw($node, $c);
        $star = strpos($raw, '{*');
        $slash = strpos($raw, '{/');
        $at = $star === false ? $slash : ($slash === false ? $star : min($star, $slash));
        $range = Support::bounds($node);
        $lineEnd = $range === null ? false : strpos($c->source, "\n", $range[1]);
        $until = $lineEnd === false ? strlen($c->source) : $lineEnd;
        if (
            $at !== false && $range !== null && strpos($c->source, '}', $range[1]) !== false
            && (int)strpos($c->source, '}', $range[1]) < $until
        ) {
            $c->report('braced-span-opener-as-text', $range[0] + $at, $range[0] + $at + 2, $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function nonDescriptiveLink(array $node, string $path, Context $c): void
    {
        $children = Support::kids($node);
        if (($node['type'] ?? null) !== 'link' || count($children) !== 1) {
            return;
        }
        $child = $children[0] ?? [];
        if (($child['type'] ?? null) !== 'text') {
            return;
        }
        $value = Support::trim(strtolower((string)preg_replace('/[ \t\r\n]+/', ' ', Support::str($child, 'value') ?? '')));
        if ($value === 'here' || $value === 'click here' || $value === 'read more') {
            Support::reportNode('non-descriptive-link-text', $node, $path, $c);
        }
    }

    /**
     * The checks that need the whole tree (the JS `finishStructure`).
     *
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function finish(Context $c): void
    {
        self::suppressUnreferencedFootnotePlatforms($c);
    }

    private static function suppressUnreferencedFootnotePlatforms(Context $c): void
    {
        $prefixes = [];
        foreach ($c->footnoteDefinitions as [$node, $path]) {
            $key = Support::str($node, 'key');
            if ($key !== null && !isset($c->usedFootnotes[$key])) {
                $prefixes[] = $path . '/';
            }
        }
        if ($prefixes === []) {
            return;
        }
        foreach ($c->diagnostics as $index => $entry) {
            if ($entry->code !== 'platform-issue-reference' && $entry->code !== 'platform-mention-token') {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string)$entry->path, $prefix)) {
                    unset($c->diagnostics[$index], $c->findingLines[$index]);

                    break;
                }
            }
        }
        $c->diagnostics = array_values($c->diagnostics);
        $c->findingLines = array_values($c->findingLines);
    }
}
