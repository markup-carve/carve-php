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
     * @var array<string, true> The delimiter spans a closer search climbs through; a bracketed span (link, span, note)
     * is its own content, since a pair never crosses its brackets.
     */
    private const DELIMITER_SPANS = [
        'emphasis' => true,
        'strong' => true,
        'underline' => true,
        'strike' => true,
        'highlight' => true,
        'superscript' => true,
        'subscript' => true,
        'insert' => true,
        'delete' => true,
        'small_caps' => true,
    ];

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
        if ($split === null || ($parent !== 'strong' && $parent !== 'emphasis')) {
            return;
        }
        $range = Support::bounds($node);
        if ($range === null) {
            return;
        }
        $raw = substr($c->source, $range[0], $range[1] - $range[0]);
        $last = null;
        $from = 0;
        while (($at = strpos($raw, '{', $from)) !== false) {
            $from = $at + 1;
            $mark = $raw[$at + 1] ?? '';
            if (($mark !== '*' && $mark !== '/') || self::escaped($raw, $at)) {
                continue;
            }
            $last ??= self::lastClosers(self::contentOf($split, $c), $c);
            $closer = $last[$mark];
            if ($closer !== null && $closer >= $range[0] + $at + 2) {
                $c->report('braced-span-opener-as-text', $range[0] + $at, $range[0] + $at + 2, $path);
            }
        }
    }

    /**
     * Whether an odd run of backslashes stands right before a byte.
     *
     * @param string $text
     * @param int $at
     *
     * @return bool
     */
    private static function escaped(string $text, int $at): bool
    {
        $escapes = 0;
        while ($at - 1 - $escapes >= 0 && $text[$at - 1 - $escapes] === '\\') {
            $escapes++;
        }

        return $escapes % 2 === 1;
    }

    /**
     * The inline content an opener can pair in: climb through the delimiter spans to the leaf block or bracketed span.
     *
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return string
     */
    private static function contentOf(string $path, Context $c): string
    {
        while ($path !== '' && isset(self::DELIMITER_SPANS[$c->nodeTypes[$path] ?? ''])) {
            $path = $c->layout->parent($path);
        }

        return $path;
    }

    /**
     * Where the last unescaped braced closer of each mark starts in the text of a content, once per content so each
     * opener costs one lookup. Code, math and other opaque leaves hold no text nodes and so no closers.
     *
     * @param string $content
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return array{'*': int|null, '/': int|null}
     */
    private static function lastClosers(string $content, Context $c): array
    {
        if (isset($c->closerStarts[$content])) {
            return $c->closerStarts[$content];
        }
        $last = ['*' => null, '/' => null];
        $stack = array_reverse(Support::kids($c->layout->node($content) ?? []));
        while ($stack !== []) {
            $node = array_pop($stack);
            $type = $node['type'] ?? null;
            if (is_string($type) && isset(self::DELIMITER_SPANS[$type])) {
                array_push($stack, ...array_reverse(Support::kids($node)));

                continue;
            }
            $range = $type === 'text' ? Support::bounds($node) : null;
            if ($range === null) {
                continue;
            }
            $text = substr($c->source, $range[0], $range[1] - $range[0]);
            $from = 0;
            while (($at = strpos($text, '}', $from)) !== false) {
                $from = $at + 1;
                $mark = $at > 0 ? $text[$at - 1] : '';
                if ($mark !== '*' && $mark !== '/') {
                    continue;
                }
                if (!self::escaped($text, $at - 1)) {
                    $last[$mark] = $range[0] + $at - 1;
                }
            }
        }

        return $c->closerStarts[$content] = $last;
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
