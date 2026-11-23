<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;

/**
 * The node rules of carve-js `lint/structure.ts`, first half.
 */
final class NodeRulesA
{
    /**
     * @var array<string, true>
     */
    private const ATTRIBUTE_HOSTS = ['paragraph' => true, 'heading' => true, 'definition_term' => true, 'cell_paragraph' => true];

    /**
     * @var array<string>
     */
    private const WRITER_PAIRS = [
        'deprecated-bare-underline', 'deprecated-bare-strike', 'deprecated-bare-highlight',
        'markdown-strikethrough-double-tilde', 'markdown-strong-double-star',
    ];

    /**
     * The pairs of `$mark` that the rule `$id` reports in the source `$text` of one text node, as byte ranges. The
     * writer tests its own text with them (C2-W-146).
     *
     * @param string $text
     * @param string $mark
     * @param string $id
     *
     * @return array<array{0: int, 1: int}>
     */
    public static function pairRanges(string $text, string $mark, string $id): array
    {
        if (in_array($id, self::WRITER_PAIRS, true)) {
            return self::readPairs($text, $mark, $id);
        }
        // The Djot pairs, as their rule rows take them: left to right, each opener with the next closer.
        $ranges = [];
        $length = strlen($text);
        for ($at = 0; ($start = strpos($text, $mark, $at)) !== false;) {
            $end = $start + 2 <= $length ? strpos($text, $mark, $start + 2) : false;
            while ($end !== false && (self::endsWith($text, $end, '[\p{Zs}\t\n\r]') || self::escapedAt($text, $end))) {
                $end = strpos($text, $mark, $end + 1);
            }
            if ($end === false) {
                break;
            }
            if (self::djotReported($text, $mark, $start, $end)) {
                $ranges[] = [$start, $end + 1];
            }
            $at = $end + 1;
        }

        return $ranges;
    }

    /**
     * The conditions of the Djot pair rows on the pair from `$start` to the closer at `$end`.
     *
     * @param string $text
     * @param string $mark
     * @param int $start
     * @param int $end
     *
     * @return bool
     */
    private static function djotReported(string $text, string $mark, int $start, int $end): bool
    {
        $inside = substr($text, $start + 1, $end - $start - 1);
        if (
            self::escapedAt($text, $start) || preg_match('/^[\p{Zs}\t\n\r\f\v]|[\p{Zs}\t\n\r\f\v]$/u', $inside) === 1
            || $inside[0] === $mark || $inside[strlen($inside) - 1] === $mark
        ) {
            return false;
        }
        if ($mark === '_') {
            return !self::endsWith($text, $start, '[\p{L}\p{N}]') && !self::startsWith($text, $end + 1, '[\p{L}\p{N}]');
        }
        $before = $start > 0 ? $text[$start - 1] : '';

        return $before !== '{' && $before !== $mark && ($text[$end + 1] ?? '') !== '}';
    }

    private static function canOpen(string $text, string $mark, string $id, int $at): bool
    {
        if (!str_starts_with($id, 'deprecated-bare-')) {
            return true;
        }
        $after = $at + strlen($mark);

        return !self::joined($text, $after, $id)
            && !self::escapedAt($text, $at)
            && !($at > 0 && $text[$at - 1] === $mark[0])
            && $after < strlen($text)
            && !self::startsWith($text, $after, '[\p{Zs}\t\n\r\f\v]')
            && $text[$after] !== $mark[0]
            && (strlen($mark) === 2 || !self::endsWith($text, $at, '[\p{L}\p{N}]'));
    }

    /**
     * Whether `/CLASS$/u` matches `substr($text, 0, $at)`, read on the last characters only: the whole prefix made
     * each test linear, and the pair search quadratic. `\z`, not `$`: PCRE `$` also matches before a final line feed.
     *
     * @param string $text
     * @param int $at
     * @param string $class
     *
     * @return bool
     */
    private static function endsWith(string $text, int $at, string $class): bool
    {
        $from = max(0, $at - 8);
        while ($from > 0 && (ord($text[$from]) & 0xC0) === 0x80) {
            $from--;
        }

        return preg_match('/' . $class . '\z/u', substr($text, $from, $at - $from)) === 1;
    }

    /**
     * Whether `/^CLASS/u` matches `substr($text, $at)`, read on the character at `$at` only.
     *
     * @param string $text
     * @param int $at
     * @param string $class
     *
     * @return bool
     */
    private static function startsWith(string $text, int $at, string $class): bool
    {
        if ($at >= strlen($text)) {
            return false;
        }
        $lead = ord($text[$at]);
        $length = $lead < 0xC0 ? 1 : ($lead < 0xE0 ? 2 : ($lead < 0xF0 ? 3 : 4));

        return preg_match('/^' . $class . '/u', substr($text, $at, $length)) === 1;
    }

    /**
     * The pair rules whose reading follows carve-js `paired`: a candidate opener, the first later closer that no
     * whitespace and no backslash stands before, and the test of the pair; the search goes on behind that closer.
     *
     * @param string $text
     * @param string $mark
     * @param string $id
     *
     * @return array<array{0: int, 1: int}>
     */
    private static function readPairs(string $text, string $mark, string $id): array
    {
        $ranges = [];
        $size = strlen($mark);
        $at = 0;
        $length = strlen($text);
        while ($at < $length) {
            $start = strpos($text, $mark, $at);
            if ($start === false) {
                break;
            }
            if (!self::canOpen($text, $mark, $id, $start)) {
                $at = $start + $size;

                continue;
            }
            $end = self::nextCloser($text, $mark, $id, $start + $size + 1);
            if ($end < 0) {
                break;
            }
            if (self::validPair($text, $mark, $id, $start, $end)) {
                $ranges[] = [$start, $end + $size];
            }
            $at = $end + $size;
        }

        return $ranges;
    }

    private static function nextCloser(string $text, string $mark, string $id, int $from): int
    {
        $end = $from <= strlen($text) ? strpos($text, $mark, $from) : false;
        $size = strlen($mark);
        while (
            $end !== false && (
                self::endsWith($text, $end, '[\p{Zs}\t\n\r]') || self::escapedAt($text, $end)
                || self::joined($text, $end + $size, $id)
            )
        ) {
            $next = $end + $size;
            $end = $next <= strlen($text) ? strpos($text, $mark, $next) : false;
        }

        return $end === false ? -1 : $end;
    }

    private static function escapedAt(string $text, int $at): bool
    {
        $slashes = 0;
        while ($at - 1 - $slashes >= 0 && $text[$at - 1 - $slashes] === '\\') {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }

    private static function validPair(string $text, string $mark, string $id, int $start, int $end): bool
    {
        $inside = substr($text, $start + strlen($mark), $end - $start - strlen($mark));
        $edge = '/^[\p{Zs}\t\n\r\f\v]|[\p{Zs}\t\n\r\f\v]$/u';
        if (self::escapedAt($text, $start) || $inside === '' || preg_match($edge, $inside) === 1) {
            return false;
        }
        if ($inside[0] === $mark[0] || $inside[strlen($inside) - 1] === $mark[0]) {
            return false;
        }
        if ($mark === '**' && str_contains($inside, '*')) {
            return false;
        }
        if ($id === 'markdown-strong-double-star' || $id === 'markdown-strikethrough-double-tilde') {
            return true;
        }

        return !self::endsWith($text, $start, '[\p{L}\p{N}]') && !self::startsWith($text, $end + strlen($mark), '[\p{L}\p{N}]');
    }

    /**
     * @param string $text
     * @param int $at
     * @param string $id
     *
     * @return bool
     */
    private static function joined(string $text, int $at, string $id): bool
    {
        return str_starts_with($id, 'deprecated-bare-')
            && preg_match('/^[\p{M}\x{200D}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u', substr($text, $at, 4)) === 1;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function substitutionArrow(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'strike') {
            return;
        }
        foreach (Support::kids($node) as $child) {
            if (($child['type'] ?? null) !== 'text') {
                continue;
            }
            $raw = Support::raw($child, $c);
            $at = strpos($raw, '~>');
            while ($at !== false) {
                $slashes = 0;
                for ($before = $at - 1; $before >= 0 && $raw[$before] === '\\'; $before--) {
                    $slashes++;
                }
                if ($slashes % 2 === 0) {
                    Support::reportNode('deprecated-substitution-arrow', $node, $path, $c);

                    return;
                }
                $at = strpos($raw, '~>', $at + 2);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function bidiControl(array $node, string $path, Context $c): void
    {
        if (!in_array($node['type'] ?? null, ['text', 'code', 'code_block', 'math'], true)) {
            return;
        }
        if (str_contains($path, '/title/')) {
            return;
        }
        $range = Support::bounds($node);
        if ($range === null) {
            return;
        }
        $text = substr($c->source, $range[0], $range[1] - $range[0]);
        if ($node['type'] === 'code_block' && !isset($c->options['payload'])) {
            $feed = strpos($text, "\n");
            if ($feed === false) {
                return;
            }
            $range[0] += $feed + 1;
            $text = substr($text, $feed + 1, strlen(Support::str($node, 'value') ?? ''));
        }
        if (preg_match_all('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return;
        }
        foreach ($matches[0] as [$char, $at]) {
            $code = strtoupper(str_pad(dechex(mb_ord($char, 'UTF-8') ?: 0), 4, '0', STR_PAD_LEFT));
            $c->report('bidi-control-in-source', $range[0] + $at, $range[0] + $at + strlen($char), $path, ['code' => $code]);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function headingContinuation(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'heading' || $pos === null || $pos['endLine'] <= $pos['startLine']) {
            return;
        }
        $quotes = $c->layout->quoteDepth($path);
        // The marker line stands behind the heading's attribute lines.
        $marker = NodeRulesB::openerBehindAttributes($node, $pos['startOffset'], $quotes, $c);
        $markerLine = $c->lineOf($marker);
        // C2-B-137: comment-only lines may stand between the attribute lines and the marker line.
        while ($marker !== $pos['startOffset'] && $markerLine < $pos['endLine'] && str_starts_with(substr($c->source, $marker, 2), '%%')) {
            $markerLine++;
            $marker = $c->lines[$markerLine - 1]->start + strspn($c->lines[$markerLine - 1]->text, " \t>");
        }
        $first = $c->lines[$markerLine - 1] ?? null;
        // C2-B-137: the line that continues the heading may stand below comment-only lines.
        $next = $markerLine;
        while ($next < $pos['endLine'] && isset($c->lines[$next]) && str_starts_with(self::jsTrimStart($c->lines[$next]->text), '%%')) {
            $next++;
        }
        $second = $next < $pos['endLine'] ? $c->lines[$next] ?? null : null;
        if ($first === null || $second === null) {
            return;
        }
        $markerOffset = $marker - $first->start;
        $markerColumn = $quotes === 0 && strspn($first->text, ' ') === $markerOffset
            ? $markerOffset : $c->layout->columnOf($marker, $quotes);
        $indentOffset = strspn($second->text, " \t>");
        $indent = $quotes === 0 && !str_contains(substr($second->text, 0, $indentOffset), "\t")
            ? $indentOffset : $c->layout->columnOf($second->start + $indentOffset, $quotes);
        if ($indent > $markerColumn) {
            $c->report('deprecated-indented-line-under-heading', $second->start, $second->end, $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function figurePanelNumber(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'figure_group') {
            return;
        }
        foreach (Support::kids($node) as $at => $panel) {
            $caption = Support::kids(['caption' => [$panel['caption'] ?? null]], 'caption')[0] ?? null;
            if ($caption !== null && preg_match('/(?<!\\\\)#(?=\s|[:.]|$|[^A-Za-z0-9_])/u', Support::raw($caption, $c)) === 1) {
                Support::reportNode('figure-group-panel-number', $panel, "{$path}/children/{$at}", $c);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function referencesPlacement(array $node, string $path, Context $c): void
    {
        if (self::nestedDirective($node, $path, 'references')) {
            Support::reportNode('references-placement-in-container', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param string $kind
     *
     * @return bool
     */
    private static function nestedDirective(array $node, string $path, string $kind): bool
    {
        return ($node['type'] ?? null) === 'directive' && count(explode('/', $path)) > 3 && ($node['kind'] ?? null) === $kind;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unattachedInline(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'text') {
            return;
        }
        // carve#6176: the text of a paragraph, heading, term or cell; a description's through its paragraphs.
        $parent = substr($path, 0, (int)strrpos($path, '/children/'));
        if (!isset(self::ATTRIBUTE_HOSTS[$c->nodeTypes[$parent] ?? ''])) {
            return;
        }
        $source = Support::raw($node, $c);
        $range = Support::bounds($node);
        if ($range === null) {
            return;
        }
        $length = strlen($source);
        for ($at = strpos($source, '{'); $at !== false; $at = strpos($source, '{', $at + 1)) {
            if ($at < 1 || self::escapedAt($source, $at)) {
                continue;
            }
            // The parser's end, so a quoted `}` stays inside the block; `{}` is no attribute block to report.
            $end = AttributeParser::read($source, $at, $length, new Attrs());
            if ($end > $at && !AttributeParser::$lastEmpty) {
                $c->report('unattached-inline-attribute', $range[0] + $at, $range[0] + $end, $path);
                $at = $end - 1;
            }
        }
    }

    /**
     * The complete 0.1.13 alignment spelling at the start of a cell.
     *
     * @return array{prefix: string, braced: string}|null
     */
    public static function oldAlignmentRun(string $source): ?array
    {
        $mark = self::oldAlignmentPrefix($source);
        if ($mark === '') {
            return null;
        }
        $block = '';
        $offset = strlen($mark);
        if (($source[$offset] ?? '') === '{') {
            $end = strpos($source, '}', $offset);
            if ($end === false) {
                return null;
            }
            $block = substr($source, $offset + 1, $end - $offset - 1);
            if (str_contains($block, '{')) {
                return null;
            }
        }
        $prefix = $block === '' && ($source[$offset] ?? '') !== '{' ? $mark : substr($source, 0, $offset + strlen($block) + 2);
        $after = $source[strlen($prefix)] ?? '';
        if (($after !== ' ' && $after !== "\t") || self::jsTrim(substr($source, strlen($prefix))) === '') {
            return null;
        }

        return ['prefix' => $prefix, 'braced' => '{' . $mark . (self::jsTrim($block) === '' ? '' : ' ' . self::jsTrim($block)) . '}'];
    }

    private static function oldAlignmentPrefix(string $source): string
    {
        $first = $source[0] ?? '';
        $second = $source[1] ?? '';
        $vertical = $second === '^' || $second === 'v' || $second === '~';
        if (in_array($first, ['<', '>', '~'], true)) {
            return $first . ($vertical ? $second : '');
        }

        return $first === '?' && $vertical ? '?' . $second : '';
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function collectFragment(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'link' && str_starts_with(Support::str($node, 'href') ?? '', '#')) {
            $c->fragmentLinks[] = [$node, $path];
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function platformIssue(array $node, string $path, Context $c): void
    {
        $range = Support::bounds($node);
        if (($node['type'] ?? null) !== 'text' || $range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 0; $at < $length; $at++) {
            if ($source[$at] !== '#' || ($at > 0 && Support::isWord($source[$at - 1])) || !ctype_digit($source[$at + 1] ?? '')) {
                continue;
            }
            $end = $at + 1 + strspn($source, '0123456789', $at + 1);
            $c->report('platform-issue-reference', $range[0] + $at, $range[0] + $end, $path, [
                'host' => 'GitHub',
                'text' => substr($source, $at, $end - $at),
            ]);
            $at = $end - 1;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function platformMention(array $node, string $path, Context $c): void
    {
        $values = ['host' => 'GitHub', 'what' => 'a mention token'];
        if (($node['type'] ?? null) === 'mention') {
            Support::reportNode('platform-mention-token', $node, $path, $c, $values + ['text' => Support::raw($node, $c)]);

            return;
        }
        $range = Support::bounds($node);
        if (($node['type'] ?? null) !== 'text' || $range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 0; $at < $length; $at++) {
            if ($source[$at] !== '@' || ($at > 0 && Support::isWord($source[$at - 1])) || !Support::isWord($source[$at + 1] ?? null)) {
                continue;
            }
            $end = $at + 1;
            while ($end < $length && (Support::isWord($source[$end]) || $source[$end] === '-')) {
                $end++;
            }
            $text = substr($source, $at, $end - $at);
            $c->report('platform-mention-token', $range[0] + $at, $range[0] + $end, $path, $values + ['text' => $text]);
            $at = $end - 1;
        }
    }

    /**
     * The JS `trim` of ECMAScript whitespace.
     *
     * @param string $text
     *
     * @return string
     */
    private static function jsTrim(string $text): string
    {
        return self::jsTrimEnd(self::jsTrimStart($text));
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private static function jsTrimStart(string $text): string
    {
        return preg_replace('/^[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+/u', '', $text) ?? ltrim($text, " \t\n\r\v\f");
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private static function jsTrimEnd(string $text): string
    {
        return preg_replace('/[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $text) ?? rtrim($text, " \t\n\r\v\f");
    }
}
