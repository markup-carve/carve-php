<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;

/**
 * The second half of the carve-js node rules (`lint/structure.ts`), one method per JS rule function, and the checks that run
 * after the walk.
 *
 * @phpstan-type OpenerScan array{
 *     openers: array<int, array{0: int, 1: int, 2: string}>,
 *     formed: array<int, array{0: int, 1: int}>,
 *     content: array<int, array{0: int, 1: int, 2: int}>,
 *     started: bool,
 *     scopes: int,
 * }
 */
final class NodeRulesB
{
    /**
     * @var array<string, true> The inline leaf blocks a braced opener pairs in.
     */
    private const OPENER_LEAVES = [
        'paragraph' => true,
        'heading' => true,
        'definition_term' => true,
        'cell_paragraph' => true,
        'line' => true,
        'citation_definition' => true,
    ];

    /**
     * @var array<string, true> C2-I-007: a delimiter pairs only inside its bracket.
     */
    private const BRACKETS = [
        'link' => true,
        'span' => true,
        'inline_footnote' => true,
        'inline_extension' => true,
    ];

    /**
     * @var array<string, true> The spans whose marks a closer can stand in.
     */
    private const BRACED_SPANS = [
        'emphasis' => true,
        'strong' => true,
        'underline' => true,
        'strike' => true,
        'highlight' => true,
        'superscript' => true,
        'subscript' => true,
        'insert' => true,
        'delete' => true,
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
     * C2-I-059: a braced opener left as text in a bare strong or emphasis, reported when a braced closer of its mark in
     * the same content would pair with it. A port of the carve-js scan (`lint/structure.ts`).
     *
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
        $parent = Support::parent($path);
        $type = $parent === null ? null : ($c->nodeTypes[$parent] ?? null);
        $range = Support::bounds($node);
        if ($parent === null || ($type !== 'strong' && $type !== 'emphasis') || $range === null) {
            return;
        }
        $brace = strpos($c->source, '{', $range[0]);
        if ($brace === false || $brace >= $range[1]) {
            return;
        }
        $leaf = $parent;
        while ($leaf !== null && !isset(self::OPENER_LEAVES[$c->nodeTypes[$leaf] ?? ''])) {
            $leaf = Support::parent($leaf);
        }
        if ($leaf === null) {
            return;
        }
        $c->closerStarts[$leaf] ??= self::pairedOpeners($leaf, $c);
        foreach ($c->closerStarts[$leaf][$path] ?? [] as $at) {
            $c->report('braced-span-opener-as-text', $at, $at + 2, $path);
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
     * The text openers of a leaf block that a braced closer of their content pairs with, by the path of their text node.
     * Scope 0 is the leaf block, each bracket its own scope (C2-I-007).
     *
     * @param string $leaf
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return array<string, array<int, int>>
     */
    private static function pairedOpeners(string $leaf, Context $c): array
    {
        $node = $c->layout->node($leaf);
        $range = $node === null ? null : Support::bounds($node);
        if ($node === null || $range === null) {
            return [];
        }
        $scan = ['openers' => [], 'formed' => [], 'content' => [], 'started' => false, 'scopes' => 0];
        foreach (Support::kids($node) as $i => $kid) {
            self::collectOpeners($kid, $leaf . '/children/' . $i, 0, false, $scan, $c);
        }
        if ($scan['openers'] === []) {
            return [];
        }
        [$start, $end] = $range;
        $inside = [];
        foreach ($scan['content'] as [$from, $to, $scope]) {
            for ($at = $from; $at < $to; $at++) {
                $inside[$at] = $scope + 1;
            }
        }
        $events = [];
        foreach ($scan['openers'] as $index => [$at, $scope]) {
            $events[] = [$at, $scope, $index];
        }
        foreach ($scan['formed'] as [$at, $scope]) {
            $events[] = [$at, $scope, -1];
        }
        usort($events, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $next = 0;
        $count = count($events);
        $stacks = [];
        $paired = [];
        $from = $start + 1;
        while (($at = strpos($c->source, '}', $from)) !== false && $at < $end) {
            $from = $at + 1;
            while ($next < $count && $events[$next][0] < $at - 1) {
                [$opener, $scope, $index] = $events[$next++];
                $stacks[$scope][] = [$c->source[$opener + 1], $index];
            }
            $mark = $c->source[$at - 1];
            $scope = $inside[$at] ?? 0;
            if (
                $scope === 0 || ($inside[$at - 1] ?? 0) !== $scope || !isset(Tables::BRACED_MARKS[$mark])
                || self::escapedInside($at - 1, $start, $inside, $c->source)
            ) {
                continue;
            }
            $taken = self::closeNearest($stacks[$scope - 1], $mark);
            if ($taken >= 0) {
                [$opener, , $path] = $scan['openers'][$taken];
                $paired[$path][] = $opener;
            }
        }

        return $paired;
    }

    /**
     * Collects the text openers, the braced openers of formed spans and the content a closer can stand in: text minus
     * valid attribute blocks, smart punctuation and span marks, no atom (C2-W-107).
     *
     * @phpstan-param OpenerScan $scan
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param int $scope
     * @param bool $bare Whether the parent is a bare strong or emphasis.
     * @param array $scan
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    private static function collectOpeners(array $node, string $path, int $scope, bool $bare, array &$scan, Context $c): void
    {
        $type = $node['type'] ?? null;
        $type = is_string($type) ? $type : '';
        $range = Support::bounds($node);
        if (isset(self::OPENER_LEAVES[$type]) || $range === null) {
            return;
        }
        if (isset(self::BRACKETS[$type])) {
            $scope = ++$scan['scopes'];
        }
        $kids = Support::kids($node);
        if (isset(self::BRACED_SPANS[$type])) {
            self::spanMarks($kids, $range, $scope, $scan, $c->source);
        }
        if ($type === 'text') {
            if ($bare) {
                self::textOpeners($path, $range, $scope, $scan, $c->source);
            }
            if ($scan['started']) {
                self::textContent($range, $scope, $scan, $c->source);
            }

            return;
        }
        if ($type === 'smart_punctuation') {
            if ($scan['started']) {
                $scan['content'][] = [$range[0], $range[1], $scope];
            }

            return;
        }
        $opens = ($type === 'strong' || $type === 'emphasis') && $c->source[$range[0]] !== '{';
        foreach ($kids as $i => $kid) {
            self::collectOpeners($kid, $path . '/children/' . $i, $scope, $opens, $scan, $c);
        }
    }

    /**
     * The marks of a span with content are where a closer can stand; a braced span's opener takes closers too (C2-I-058).
     *
     * @phpstan-param OpenerScan $scan
     *
     * @param array<int, array<string, mixed>> $kids
     * @param array{0: int, 1: int} $range
     * @param int $scope
     * @param array $scan
     * @param string $source
     *
     * @return void
     */
    private static function spanMarks(array $kids, array $range, int $scope, array &$scan, string $source): void
    {
        $last = $kids === [] ? null : Support::bounds($kids[array_key_last($kids)]);
        if ($last === null) {
            return;
        }
        $width = $source[$range[0]] === '{' ? 2 : 1;
        if ($width === 2) {
            $scan['formed'][] = [$range[0], $scope];
        }
        $scan['content'][] = [$range[0], $range[0] + $width, $scope];
        $scan['content'][] = [$last[1], $last[1] + $width, $scope];
    }

    /**
     * The braced openers left as text in a text child of a bare strong or emphasis, in source order.
     *
     * @phpstan-param OpenerScan $scan
     *
     * @param string $path
     * @param array{0: int, 1: int} $range
     * @param int $scope
     * @param array $scan
     * @param string $source
     *
     * @return void
     */
    private static function textOpeners(string $path, array $range, int $scope, array &$scan, string $source): void
    {
        for ($at = $range[0]; $at + 1 < $range[1]; $at++) {
            if ($source[$at] !== '{' || self::escaped($source, $at)) {
                continue;
            }
            // C2-I-043: a valid attribute block at a brace comes before a braced opener.
            $block = self::attributeBlockEnd($source, $at, $range[1]);
            if ($block >= 0) {
                $at = $block - 1;

                continue;
            }
            if (isset(Tables::BRACED_MARKS[$source[$at + 1]])) {
                $scan['openers'][] = [$at, $scope, $path];
                $scan['started'] = true;
            }
        }
    }

    /**
     * C2-I-043: a valid attribute block left as text is still an atom, so no closer stands in it.
     *
     * @phpstan-param OpenerScan $scan
     *
     * @param array{0: int, 1: int} $range
     * @param int $scope
     * @param array $scan
     * @param string $source
     *
     * @return void
     */
    private static function textContent(array $range, int $scope, array &$scan, string $source): void
    {
        [$from, $end] = $range;
        for ($at = $from; $at < $end; $at++) {
            if ($source[$at] !== '{' || self::escaped($source, $at)) {
                continue;
            }
            $after = self::attributeBlockEnd($source, $at, $end);
            if ($after < 0) {
                continue;
            }
            if ($at > $from) {
                $scan['content'][] = [$from, $at, $scope];
            }
            $from = $after;
            $at = $after - 1;
        }
        if ($end > $from) {
            $scan['content'][] = [$from, $end, $scope];
        }
    }

    /**
     * The offset behind a valid attribute block with at least one part at `$at` (C2-I-028), or -1.
     *
     * @param string $source
     * @param int $at
     * @param int $end
     *
     * @return int
     */
    private static function attributeBlockEnd(string $source, int $at, int $end): int
    {
        $after = AttributeParser::read($source, $at, $end, new Attrs());

        return $after > 0 && !AttributeParser::$lastEmpty ? $after : -1;
    }

    /**
     * C2-I-058, C2-I-059: a closer takes the nearest open opener of its mark and removes every opener above it. Returns
     * the index of the text opener it takes, or -1.
     *
     * @param array<int, array{0: string, 1: int}>|null $stack
     * @param string $mark
     *
     * @return int
     */
    private static function closeNearest(?array &$stack, string $mark): int
    {
        if ($stack === null) {
            return -1;
        }
        $marks = array_column($stack, 0);
        if (!in_array($mark, $marks, true)) {
            return -1;
        }
        while ($stack !== []) {
            [$top, $index] = array_pop($stack);
            if ($top === $mark) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Whether a backslash run of the content, right before `$at`, escapes it.
     *
     * @param int $at
     * @param int $start
     * @param array<int, int> $inside
     * @param string $source
     *
     * @return bool
     */
    private static function escapedInside(int $at, int $start, array $inside, string $source): bool
    {
        $slashes = 0;
        while ($at - 1 - $slashes >= $start && ($inside[$at - 1 - $slashes] ?? 0) !== 0 && $source[$at - 1 - $slashes] === '\\') {
            $slashes++;
        }

        return $slashes % 2 === 1;
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
