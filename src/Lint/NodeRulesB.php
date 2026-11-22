<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Resolver;
use Throwable;

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
    public static function delimitedWideRow(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table') {
            return;
        }
        $format = $c->layout->sourceFormat($path);
        if ($format !== 'csv' && $format !== 'tsv') {
            return;
        }
        $width = -1;
        foreach (Support::kids($node) as $section) {
            if (!in_array($section['type'] ?? null, ['table_body', 'table_head', 'table_foot'], true)) {
                continue;
            }
            foreach (Support::kids($section) as $row) {
                if (($row['type'] ?? null) !== 'table_row') {
                    continue;
                }
                $cells = count(Support::kids($row));
                if ($width < 0) {
                    $width = $cells;
                } elseif ($cells > $width) {
                    Support::reportNode('delimited-table-wide-row', $node, $path, $c);

                    return;
                }
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
    public static function intrawordUnderscore(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 1; $at < $length - 2; $at++) {
            if (
                $source[$at] !== '_' || !Support::isWord($source[$at - 1])
                || in_array($source[$at + 1] ?? '', [' ', "\t", "\n"], true)
            ) {
                continue;
            }
            $end = strpos($source, '_', $at + 2);
            if (
                $end === false || !Support::isWord($source[$end + 1] ?? null)
                || in_array($source[$end - 1] ?? '', [' ', "\t", "\n"], true)
            ) {
                continue;
            }
            $c->report('djot-intraword-underscore', $range[0] + $at, $range[0] + $end + 1, $path);
            $at = $end;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function emphasisBeforeAttrs(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 0; $at < $length - 2; $at++) {
            $ch = $source[$at];
            if (!str_contains('*_/~=', $ch) || $source[$at + 1] !== '{') {
                continue;
            }
            if ($at > 0 && $source[$at - 1] === '\\') {
                continue;
            }
            $end = strpos($source, '}', $at + 2);
            if ($end === false) {
                continue;
            }
            if (AttributeParser::read($source, $at + 1, $end + 1, new Attrs()) !== $end + 1) {
                continue;
            }
            // A glued run at the start of a cell belongs to the alignment rule; keep scanning after it.
            if (
                $at <= 1 && self::opensCell($c, $range[0])
                && strlen(NodeRulesA::oldAlignmentRun($source)['prefix'] ?? '') === $end + 1
            ) {
                $at = $end;

                continue;
            }
            $c->report('emphasis-marker-before-attributes', $range[0] + $at, $range[0] + $end + 1, $path, ['ch' => $ch], 0);
            $at = $end;
        }
    }

    private static function opensCell(Context $c, int $start): bool
    {
        $at = $start - 1;
        while (in_array($c->source[$at] ?? '', [' ', "\t"], true)) {
            $at--;
        }
        if (($c->source[$at] ?? '') === '=') {
            $at--;
        }

        return ($c->source[$at] ?? '') === '|';
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function emphasisBeforeAttrsSpan(array $node, string $path, Context $c): void
    {
        $type = $node['type'] ?? null;
        if ($type !== 'strong' && $type !== 'emphasis') {
            return;
        }
        $raw = Support::raw($node, $c);
        $marker = $type === 'strong' ? '*' : '/';
        if (preg_match('/' . preg_quote($marker, '/') . '\{[.#][^{}]*\}$/', $raw, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return;
        }
        $at = $match[0][1];
        if (!str_contains(substr($raw, 1, $at - 1), $marker)) {
            return;
        }
        $bounds = Support::bounds($node);
        $pos = Support::pos($node);
        if ($bounds === null || $pos === null) {
            return;
        }
        $c->report('emphasis-marker-before-attributes', $bounds[0] + $at, $bounds[0] + $at + 1, $path, [
            'marker' => $marker,
            'name' => $type,
            'line' => (string)$pos['startLine'],
            'column' => (string)$pos['startColumn'],
        ], 1);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function carveVersion(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'frontmatter') {
            return;
        }
        $pos = Support::pos($node);
        if ($pos === null) {
            return;
        }
        for ($number = $pos['startLine'] + 1; $number < $pos['endLine']; $number++) {
            $line = $c->lines[$number - 1] ?? null;
            if ($line === null) {
                continue;
            }
            if (preg_match('/^[ \t]*carve(?:-version)?[ \t]*:[ \t]*(\S+)[ \t]*$/', $line->text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            [$version, $offset] = $match[1];
            $numeric = preg_match('/^\d+(?:\.\d+)*$/', $version) === 1;
            $parts = $numeric ? array_map('intval', explode('.', $version)) : [];
            $major = $numeric ? $parts[0] : -1;
            $minor = count($parts) > 1 ? $parts[1] : 0;
            if (
                $major === 0 && ($minor < 2
                || ($minor === 2 && array_sum(array_slice($parts, 2)) === 0))
            ) {
                return;
            }
            $c->report('carve-version-unsupported', $line->start + $offset, $line->start + $offset + strlen($version), $path, [
                'version' => $version,
            ], $numeric ? 1 : 0);

            return;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function commentFenceCloserText(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'comment' || ($node['form'] ?? null) !== 'block' || $pos === null) {
            return;
        }
        $first = $c->lines[$pos['startLine'] - 1] ?? null;
        $width = $first === null ? 0 : strspn($c->source, '%', $pos['startOffset']);
        if ($width < 3) {
            return;
        }
        for ($lineNumber = $pos['startLine'] + 1; $lineNumber <= $pos['endLine']; $lineNumber++) {
            $line = $c->lines[$lineNumber - 1] ?? null;
            if ($line === null) {
                continue;
            }
            $text = self::commentBody($line, $c->layout->quoteDepth($path));
            $run = strspn($text, '%');
            if ($run >= $width && Support::trim(substr($text, $run)) !== '') {
                $c->report('comment-fence-closer-with-text', $line->start, $line->end, $path);
            }
        }
    }

    private static function commentBody(Line $line, int $depth): string
    {
        $at = 0;
        for (; $depth > 0; $depth--) {
            $mark = $at + strspn($line->text, " \t", $at);
            if (($line->text[$mark] ?? '') !== '>' || !in_array($line->text[$mark + 1] ?? '', ['', ' '], true)) {
                break;
            }
            $at = $mark + 1 + (($line->text[$mark + 1] ?? '') === ' ' ? 1 : 0);
        }

        return self::trimStart(substr($line->text, $at));
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function commentInLabel(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
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
    public static function footnoteWhitespace(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'footnote') {
            return;
        }
        $source = Support::raw($node, $c);
        $close = strpos($source, ']');
        if (!str_starts_with($source, '[^') || $close === false) {
            return;
        }
        $label = substr($source, 2, $close - 2);
        $key = Support::str($node, 'key') ?? '';
        $twin = $c->footnoteLabels[$key] ?? null;
        if ($twin !== null && $twin !== $label) {
            $values = ['label' => $label, 'twin' => $twin, 'key' => $key];
            Support::reportNode('footnote-labels-differ-only-in-whitespace', $node, $path, $c, $values);
        } elseif ($twin === null) {
            $c->footnoteLabels[$key] = $label;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function tableColumnOverlap(array $node, string $path, Context $c): void
    {
        if (
            ($node['type'] ?? null) !== 'table'
            || (!self::hasAttribute($node, $c, 'aligns') && !self::hasAttribute($node, $c, 'valigns'))
        ) {
            return;
        }
        $head = self::firstOfType(Support::kids($node), 'table_head');
        $first = $head === null ? null : self::firstOfType(Support::kids($head), 'table_row');
        if ($first === null) {
            return;
        }
        $cells = Support::kids($first);
        foreach (Support::kids($node, 'columns') as $at => $column) {
            // C2-A-095: the column fields carry the fact that `aligns` or `valigns` wrote them.
            $horizontal = isset($column['align'], $cells[$at]['align']);
            $vertical = isset($column['valign'], $cells[$at]['valign']);
            if ($horizontal || $vertical) {
                $range = NodeRulesA::tableKeyRange($node, $horizontal ? 'aligns' : 'valigns', $c);
                if ($range !== null) {
                    $c->report('table-column-overlap', $range[0], $range[1], $path, ['n' => (string)($at + 1)]);
                }
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
    public static function tightBlankLine(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'list' || ($node['spacing'] ?? null) !== 'tight') {
            return;
        }
        foreach (Support::kids($node) as $at => $item) {
            $children = Support::kids($item);
            if (!in_array('paragraph', array_column($children, 'type'), true)) {
                continue;
            }
            $count = count($children);
            for ($inner = 0; $inner < $count - 1; $inner++) {
                $child = $children[$inner] ?? null;
                $next = $children[$inner + 1] ?? null;
                $childPath = $path . '/children/' . $at . '/children/' . $inner;
                if (
                    $child !== null && ($child['type'] ?? null) !== 'comment' && ($next['type'] ?? null) !== 'comment'
                    && $c->layout->blankLinesAfter($childPath) > 0
                ) {
                    Support::reportNode('tight-list-item-with-blank-line', $node, $path, $c);

                    return;
                }
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
    public static function templateComment(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'comment' || ($node['form'] ?? null) !== 'braced') {
            return;
        }
        $source = Support::raw($node, $c);
        $content = str_starts_with($source, '{%') ? Support::trim(substr($source, 2, -2)) : '';
        if (
            in_array($content, ['raw', 'endraw', 'endif', 'endfor', 'endblock'], true)
            || preg_match('/^(?:if|for|block)[ \t\r\n]+\S/', $content) === 1
        ) {
            Support::reportNode('braced-comment-in-a-template-source', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unclosedBracedComment(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $at = strpos($source, '{%');
        if ($at !== false && strpos($source, '%}', $at + 2) === false) {
            $c->report('unclosed-braced-comment', $range[0] + $at, $range[1], $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unclosedEmphasis(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 0; $at < $length - 1; $at++) {
            if (($source[$at] !== '*' && $source[$at] !== '/') || !Support::isWord($source[$at + 1])) {
                continue;
            }
            $backslashes = 0;
            for ($before = $at - 1; $before >= 0 && $source[$before] === '\\'; $before--) {
                $backslashes++;
            }
            if (
                $backslashes % 2 === 1 || ($at > 0 && ($source[$at - 1] === '{'
                || !str_contains(" \t\r\n([{", $source[$at - 1])))
            ) {
                continue;
            }
            if (strpos($source, $source[$at], $at + 1) !== false) {
                continue;
            }
            $c->report('unclosed-inline-emphasis', $range[0] + $at, $range[0] + $at + 1, $path, [
                'span' => $source[$at] === '*' ? 'strong' : 'emphasis',
            ]);

            return;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function orderedKindSplit(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'document') {
            return;
        }
        $children = Support::kids($node);
        $count = count($children);
        for ($at = 1; $at < $count; $at++) {
            $before = $children[$at - 1] ?? [];
            $after = $children[$at] ?? [];
            if (($before['type'] ?? null) !== 'list' || ($after['type'] ?? null) !== 'list' || count(Support::kids($before)) !== 1) {
                continue;
            }
            $firstStyle = self::markerField($before, 'style');
            $nextStyle = self::markerField($after, 'style');
            $firstStart = Support::bounds(Support::kids($before)[0] ?? [])[0] ?? null;
            if ($firstStart === null || preg_match('/^[ivxlcdmIVXLCDM][.)]/', substr($c->source, $firstStart)) !== 1) {
                continue;
            }
            if (($firstStyle === 'i' && $nextStyle === 'a') || ($firstStyle === 'a' && $nextStyle === 'i')) {
                $beforePos = Support::pos($before);
                $afterPos = Support::pos($after);
                if ($beforePos !== null && $afterPos !== null && $afterPos['startLine'] - $beforePos['endLine'] > 3) {
                    continue;
                }
                Support::reportNode('ordered-list-kind-split', $before, $path . '/children/' . ($at - 1), $c);
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
    public static function sigilAfterLetter(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 1; $at < $length - 1; $at++) {
            $sigil = $source[$at];
            if ($sigil !== '@' && $sigil !== '#') {
                continue;
            }
            $before = $source[$at - 1];
            $prefix = substr($source, 0, $at);
            if (
                ord($before) < 128 || preg_match('/[\p{L}\p{N}]$/u', $prefix) !== 1
                || !Support::isWord($source[$at + 1])
            ) {
                continue;
            }
            if ($sigil !== '#' || preg_match('/^#[A-Za-z0-9_]*[A-Za-z_]/', substr($source, $at)) === 1) {
                $c->report('sigil-after-letter', $range[0] + $at, $range[0] + $at + 1, $path);
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
    public static function listItemBodyColumn(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'list_item' || $pos === null) {
            return;
        }
        $marker = $c->layout->markerColumn($path);
        $content = $c->layout->contentColumn($path);
        if ($marker === null || $content === null) {
            return;
        }
        $reportedLines = [];
        foreach (Support::kids($node) as $at => $block) {
            if ($at === 0) {
                continue;
            }
            $blockPos = Support::pos($block);
            $line = $blockPos === null ? null : ($c->lines[$blockPos['startLine'] - 1] ?? null);
            if ($line === null) {
                continue;
            }
            $start = $blockPos['startOffset'];
            $column = $c->layout->columnOf($start, $c->layout->quoteDepth($path));
            if ($column > $marker && $column < $content) {
                $c->report('list-item-body-below-content-column', $start, $line->end, $path);
                $reportedLines[$line->number] = true;
            }
        }
        self::listItemParagraphColumn($node, $path, $c, $pos, $marker, $content, $reportedLines);
    }

    /**
     * Check a later paragraph's first authored line when its parsed position begins after a quote prefix.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param array{startLine: int, endLine: int} $pos
     * @param int $marker
     * @param int $content
     * @param array<int, true> $reportedLines
     *
     * @return void
     */
    private static function listItemParagraphColumn(
        array $node,
        string $path,
        Context $c,
        array $pos,
        int $marker,
        int $content,
        array $reportedLines,
    ): void {
        // A later paragraph can start with a block-shaped line, while its
        // position still begins after the quote marks. Measure that authored
        // first line against the item column as for other later blocks.
        $paragraphLines = [];
        foreach (Support::kids($node) as $at => $child) {
            if ($at === 0) {
                continue;
            }
            $childPos = Support::pos($child);
            if (($child['type'] ?? null) !== 'paragraph' || $childPos === null) {
                continue;
            }
            $paragraphLines[$childPos['startLine']] = true;
        }
        if ($paragraphLines === []) {
            return;
        }
        for ($number = $pos['startLine'] + 1; $number <= $pos['endLine']; $number++) {
            $line = $c->lines[$number - 1] ?? null;
            if ($line === null || !isset($paragraphLines[$number])) {
                continue;
            }
            $quotes = $c->layout->quoteDepth($path);
            $offset = 0;
            for ($i = 0; $i < $quotes; $i++) {
                $offset += strspn($line->text, " \t", $offset);
                if (substr($line->text, $offset, 2) !== '> ') {
                    break;
                }
                $offset += 2;
            }
            $offset += strspn($line->text, " \t", $offset);
            if (preg_match('/^(?:::? |[-*] |[0-9]+[.)] |#{1,6} )/', substr($line->text, $offset)) !== 1) {
                continue;
            }
            $column = $c->layout->columnOf($line->start + $offset, $quotes);
            if ($column > $marker && $column < $content && !isset($reportedLines[$number])) {
                $c->report('list-item-body-below-content-column', $line->start + $offset, $line->end, $path);
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
    public static function listItemBlockIndent(array $node, string $path, Context $c): void
    {
        foreach (self::overindented($node, $path, $c) as [$childPath, $line, $indent, $type, $content]) {
            // The row lists its openers one by one, and an include directive is none of them (carve#6193).
            if ($type === 'include') {
                continue;
            }
            $start = $c->layout->startOffset($childPath) ?? $line->start;
            $c->report('list-item-block-overindented', $start, $line->end, $childPath, [
                'contentColumn' => (string)($content + 1),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function listMarkerBelowColumn(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'list') {
            return;
        }
        $parent = Support::parent($path);
        if ($parent === null || ($c->nodeTypes[$parent] ?? null) !== 'list_item') {
            return;
        }
        $marker = $c->layout->markerColumn($path . '/children/0');
        $base = $c->layout->markerColumn($parent);
        $content = $c->layout->contentColumn($parent);
        if ($marker === null || $base === null || $content === null) {
            return;
        }
        $indexAt = strrpos($path, '/children/');
        $index = $indexAt === false ? 0 : (int)substr($path, $indexAt + 10);
        for ($prior = $index - 1; $prior >= 0; $prior--) {
            $priorPath = $parent . '/children/' . $prior;
            $priorType = $c->nodeTypes[$priorPath] ?? null;
            if ($priorType === 'paragraph') {
                continue;
            }
            if ($priorType !== 'list') {
                break;
            }
            $deeper = $c->layout->markerColumn($priorPath . '/children/0');
            if ($deeper !== null && $marker > $base && $marker < $deeper) {
                Support::reportNode('list-marker-below-content-column', Support::kids($node)[0], $path . '/children/0', $c, [
                    'column' => (string)($marker + 1),
                    'contentColumn' => (string)($deeper + 1),
                    'baseColumn' => (string)($base + 1),
                ]);
            }

            break;
        }
        foreach (Support::kids($node) as $at => $item) {
            if ($at === 0) {
                continue;
            }
            $nextMarker = $c->layout->markerColumn($path . '/children/' . $at);
            if ($nextMarker !== null && $nextMarker > $base && $nextMarker < $marker) {
                Support::reportNode('list-marker-below-content-column', $item, $path . '/children/' . $at, $c, [
                    'column' => (string)($nextMarker + 1),
                    'contentColumn' => (string)($marker + 1),
                    'baseColumn' => (string)($base + 1),
                ]);
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
    public static function blockOverindent(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if ($pos === null) {
            return;
        }
        [$ownerPath, $ownerType] = self::blockOwner($path, $c);
        if (self::listItemThroughDiv($ownerPath, $ownerType, $c)) {
            return;
        }
        $quotes = $c->layout->quoteDepth($path);
        $start = self::openerBehindAttributes($node, $pos['startOffset'], $quotes, $c);
        // The opener's own line decides, not the attribute line above it.
        $quote = self::ancestorPath($path, $c, 'block_quote');
        if ($quote !== null && self::quoteOpenerExempt($quote, ['startLine' => $c->lineOf($start), 'startOffset' => $start], $c)) {
            return;
        }
        $column = $c->layout->columnOf($start, $quotes);
        $content = self::ownerContentColumn($ownerPath, $ownerType, $quotes, $c);
        if ($column <= $content) {
            return;
        }
        $type = Support::str($node, 'type') ?? '';
        $colonDiv = in_array($type, ['div', 'directive', 'admonition', 'figure_group'], true);
        $kind = $type === 'block_quote' ? 'quote' : ($colonDiv ? 'div' : str_replace('_', ' ', $type));
        $width = self::openingMarkWidth($type, substr($c->source, $start, strcspn($c->source, "\n", $start)));
        $past = $column - $content;
        $c->report('block-opener-overindented', $start, $start + $width, $path, [
            'kind' => $kind,
            'past' => (string)$past,
            'columns' => $past === 1 ? 'column' : 'columns',
            'owner' => str_replace('_', ' ', $ownerType),
        ]);
    }

    /**
     * @param string $quote
     * @param array{startLine: int, startOffset: int} $pos
     * @param \MarkupCarve\Carve\Lint\Context $c
     */
    private static function quoteOpenerExempt(string $quote, array $pos, Context $c): bool
    {
        $start = $c->layout->startOffset($quote);
        if ($start === null) {
            return false;
        }
        $line = $c->lines[$pos['startLine'] - 1] ?? null;
        if ($line === null) {
            return false;
        }
        $before = substr($c->source, $line->start, $pos['startOffset'] - $line->start);
        $mark = strrpos($before, '>');
        if ($mark === false || substr($before, $mark + 1) !== ' ') {
            return false;
        }
        if ($c->lineOf($start) === $pos['startLine']) {
            return true;
        }
        $depth = $c->layout->quoteDepth($quote) + 1;
        for ($number = $c->lineOf($start); $number <= $pos['startLine']; $number++) {
            $line = $c->lines[$number - 1] ?? null;
            if ($line === null || preg_match('/^(?:[ \t]*>(?: |$)){' . $depth . '}/', $line->text) !== 1) {
                continue;
            }

            return $number === $pos['startLine'];
        }

        return false;
    }

    /**
     * Where the opening mark of a block stands: an attributed block starts at its attribute lines (C2-W-138).
     *
     * @param array<string, mixed> $node
     * @param int $start
     * @param int $quotes The quote prefixes of the block's container.
     * @param \MarkupCarve\Carve\Lint\Context $c
     */
    public static function openerBehindAttributes(array $node, int $start, int $quotes, Context $c): int
    {
        if (!isset($node['attrs'])) {
            return $start;
        }
        // Attribute ownership survives comment-only lines (C2-B-137).
        $s = $c->source;
        $at = $start;
        $sawAttribute = false;
        while (($s[$at] ?? '') === '{' || ($sawAttribute && substr($s, $at, 2) === '%%')) {
            $sawAttribute = true;
            $end = strpos($s, "\n", $at);
            if ($end === false) {
                return $start;
            }
            $at = $end + 1 + strspn($s, " \t", $end + 1);
            for ($left = $quotes; $left > 0 && ($s[$at] ?? '') === '>'; $left--) {
                $at++;
                $at += strspn($s, " \t", $at);
            }
        }

        return $at;
    }

    /**
     * The whole opening mark of a block opener, as the row of `block-opener-overindented` states its range (carve#6192).
     */
    private static function openingMarkWidth(string $type, string $text): int
    {
        if ($type === 'block_quote' || $type === 'table') {
            return 1;
        }
        if ($type === 'definition_term') {
            return 2;
        }
        if ($type === 'thematic_break') {
            return strlen(Support::trimEnd($text));
        }
        $mark = $text[0] ?? '';
        if ($mark === '' || !str_contains('#=:`~|', $mark)) {
            return 1;
        }

        return strspn($text, $mark);
    }

    /**
     * @return array{0: string, 1: string} The path and type of the block's owner.
     */
    private static function blockOwner(string $path, Context $c): array
    {
        $ownerPath = Support::parent($path) ?? '';
        $ownerType = $c->nodeTypes[$ownerPath] ?? 'document';
        while ($ownerType === 'definition_list' && $ownerPath !== '') {
            $ownerPath = Support::parent($ownerPath) ?? '';
            $ownerType = $c->nodeTypes[$ownerPath] ?? 'document';
        }

        return [$ownerPath, $ownerType];
    }

    private static function listItemThroughDiv(string $path, string $type, Context $c): bool
    {
        if ($type === 'list_item') {
            return true;
        }
        while (in_array($type, ['div', 'admonition', 'directive', 'figure_group'], true)) {
            $path = Support::parent($path) ?? '';
            $type = $c->nodeTypes[$path] ?? 'document';
            if ($type === 'list_item') {
                return true;
            }
        }

        return false;
    }

    private static function ancestorPath(string $path, Context $c, string $type): ?string
    {
        for ($parent = Support::parent($path); $parent !== null; $parent = Support::parent($parent)) {
            if (($c->nodeTypes[$parent] ?? null) === $type) {
                return $parent;
            }
        }

        return null;
    }

    private static function ownerContentColumn(string $path, string $type, int $quotes, Context $c): int
    {
        if ($type === 'document' || $type === 'block_quote') {
            return 0;
        }
        $content = $c->layout->contentColumn($path);
        if ($content !== null) {
            return $content;
        }
        if ($type === 'footnote') {
            $start = $c->layout->startOffset($path) ?? 0;
            $marker = strpos($c->source, '[^', $start);
            $close = $marker === false ? false : strpos($c->source, ']:', $marker + 2);
            if ($close !== false) {
                $at = $close + 2 + strspn($c->source, " \t", $close + 2);

                return $c->layout->columnOf($at, $quotes);
            }
        }
        $start = $c->layout->startOffset($path);

        return $start === null ? 0 : $c->layout->columnOf($start, $quotes);
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
    public static function colonFenceMismatch(array $node, string $path, Context $c): void
    {
        $type = $node['type'] ?? null;
        $pos = Support::pos($node);
        // C2-L-027: a figure group records no opener and closer run.
        if (!in_array($type, ['div', 'directive', 'admonition'], true) || $pos === null) {
            return;
        }
        $opener = self::openerRun($node, $c);
        if ($opener !== null) {
            self::shorterAnonymousDivRuns($node, $path, $c, $opener);
        }
        $endLine = $c->lines[$pos['endLine'] - 1] ?? null;
        if ($opener === null || $endLine === null || $c->lineOf($opener[0]) === $endLine->number) {
            return;
        }
        if (self::endsInComment($node, $pos['endLine'])) {
            return;
        }
        $closerRun = self::bareColonRun($endLine, $c->layout->quoteDepth($path));
        if ($closerRun === null) {
            return;
        }
        $closerLength = $closerRun[1];
        [$activeRun, $innerType] = self::activeColonRun($node, $c, $opener, $pos['endLine']);
        if (
            $closerLength === $activeRun[1]
            || ($activeRun === $opener && self::closesInner($node, $closerLength, $c))
        ) {
            return;
        }
        $activeLine = $c->lineOf($activeRun[0]);
        $activeColumn = $activeRun[0] - ($c->lines[$activeLine - 1]->start ?? 0) + 1;
        $runStart = $closerRun[0];
        // The finding names the closer line's own path, as the data row does.
        $c->report('colon-fence-length-mismatch', $runStart, $runStart + $closerRun[1], $endLine->path, [
            'authoredWidth' => (string)$closerLength,
            'expectedWidth' => (string)$activeRun[1],
            'kind' => str_replace(['directive', '_'], ['div', ' '], (string)($innerType ?? $type)),
            'line' => (string)$activeLine,
            'column' => (string)$activeColumn,
        ], $closerLength < $activeRun[1] ? ($activeRun !== $opener ? 1 : 0) : 2);
    }

    /**
     * @param array<string, mixed> $node
     * @param int $endLine
     */
    private static function endsInComment(array $node, int $endLine): bool
    {
        $children = Support::kids($node);
        $last = $children === [] ? null : $children[array_key_last($children)];

        return ($last['type'] ?? null) === 'comment' && ($last['form'] ?? null) === 'block'
            && (Support::pos($last)['endLine'] ?? -1) === $endLine;
    }

    /**
     * A shorter bare run opens an anonymous div when no open run is short enough to close.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param array{0: int, 1: int} $opener
     *
     * @return void
     */
    private static function shorterAnonymousDivRuns(array $node, string $path, Context $c, array $opener): void
    {
        foreach (Support::kids($node) as $at => $child) {
            if (($child['type'] ?? null) !== 'div') {
                continue;
            }
            $run = self::openerRun($child, $c);
            if ($run === null || $run[1] >= $opener[1]) {
                continue;
            }
            $runLine = $c->lines[$c->lineOf($run[0]) - 1] ?? null;
            if ($runLine === null || self::bareColonRun($runLine, $c->layout->quoteDepth($path)) === null) {
                continue;
            }
            $c->report('colon-fence-length-mismatch', $run[0], $run[0] + $run[1], $runLine->path, [
                'authoredWidth' => (string)$run[1],
                'expectedWidth' => (string)$opener[1],
                'kind' => 'div',
                'line' => (string)$c->lineOf($opener[0]),
                'column' => (string)($opener[0] - ($c->lines[$c->lineOf($opener[0]) - 1]->start ?? 0) + 1),
            ]);
        }
    }

    /**
     * @return array{int, int}|null
     */
    private static function bareColonRun(Line $line, int $quotes): ?array
    {
        $at = 0;
        for ($i = 0; $i < $quotes; $i++) {
            $at += strspn($line->text, " \t", $at);
            if (($line->text[$at] ?? '') !== '>' || !in_array($line->text[$at + 1] ?? '', ['', ' '], true)) {
                return null;
            }
            $at += ($line->text[$at + 1] ?? '') === ' ' ? 2 : 1;
        }
        $at += strspn($line->text, " \t", $at);
        $text = Support::trimEnd(substr($line->text, $at));

        return self::allColons($text) ? [$line->start + $at, strlen($text)] : null;
    }

    /**
     * @param array<string, mixed> $node
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param array{0: int, 1: int} $opener
     * @param int $endLine
     *
     * @return array{0: array{0: int, 1: int}, 1: string|null}
     */
    private static function activeColonRun(array $node, Context $c, array $opener, int $endLine): array
    {
        $children = Support::kids($node);
        $inner = $children === [] ? null : $children[array_key_last($children)];
        if ($inner === null || !in_array($inner['type'] ?? null, ['div', 'directive', 'admonition'], true)) {
            return [$opener, null];
        }
        $innerRun = self::openerRun($inner, $c);
        $innerEnd = Support::pos($inner)['endLine'] ?? 0;
        $innerLast = $innerEnd > 0 ? ($c->lines[$innerEnd - 1] ?? null) : null;
        $innerClosed = $innerRun !== null && $innerLast !== null
            && self::allColons(Support::trim($innerLast->text))
            && strlen(Support::trim($innerLast->text)) === $innerRun[1];
        if ($innerRun !== null && $innerEnd < $endLine && !$innerClosed) {
            return [$innerRun, (string)$inner['type']];
        }

        return [$opener, null];
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function consumedNearMiss(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'code_block' || !in_array($node['syntax'] ?? null, ['csv', 'tsv'], true)) {
            return;
        }
        $pos = Support::pos($node);
        $opener = $pos === null ? null : ($c->lines[$pos['startLine']] ?? null);
        foreach (Support::keyValues($node) as [$word, $value]) {
            if ($value !== '' || $word === 'table') {
                continue;
            }
            $lower = strtolower($word);
            $distance = levenshtein($lower, 'table');
            $swapped = false;
            if (strlen($lower) === 5) {
                for ($at = 0; $at < 4; $at++) {
                    $candidate = $lower;
                    [$candidate[$at], $candidate[$at + 1]] = [$candidate[$at + 1], $candidate[$at]];
                    $swapped = $swapped || $candidate === 'table';
                }
            }
            if ($distance <= 1 || $swapped) {
                if ($opener !== null) {
                    $c->report('consumed-key-near-miss', $opener->start, $opener->end, $path, [
                        'word' => $word,
                        'key' => 'table',
                    ]);
                }
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
    public static function orderedRightAligned(array $node, string $path, Context $c): void
    {
        $children = Support::kids($node);
        if (($node['type'] ?? null) !== 'list' || self::markerField($node, 'kind') !== 'ordered' || count($children) < 2) {
            return;
        }
        $firstMarker = $c->layout->markerColumn($path . '/children/0');
        $secondMarker = $c->layout->markerColumn($path . '/children/1');
        if ($firstMarker === null || $secondMarker === null || !isset($children[0])) {
            return;
        }
        $sameContent = $c->layout->contentColumn($path . '/children/0') === $c->layout->contentColumn($path . '/children/1');
        if ($firstMarker > $secondMarker && $sameContent) {
            Support::reportNode('ordered-marker-right-aligned', $children[1], $path . '/children/1', $c);
        }
    }

    /**
     * C2-B-173: a span mark that joins nothing is the text of its cell, whatever prefix the cell has.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function spanMarkerBlocked(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table_cell' || isset($node['span'])) {
            return;
        }
        $content = Support::kids($node)[0] ?? null;
        $inner = $content !== null && ($content['type'] ?? null) === 'cell_paragraph' ? Support::kids($content) : [];
        $only = count($inner) === 1 ? $inner[0] ?? null : null;
        $marker = $only !== null && ($only['type'] ?? null) === 'text' ? Support::str($only, 'value') ?? '' : '';
        if ($marker !== '<' && $marker !== '^') {
            return;
        }
        // An escaped mark is text by the author's choice.
        if (str_ends_with(rtrim(Support::raw($node, $c), " \t\n\r\v\f"), '\\' . $marker)) {
            return;
        }
        $variant = self::blockedSpanVariant($marker, $path);
        Support::reportNode('table-span-marker-blocked', $node, $path, $c, [], $variant);
    }

    private static function blockedSpanVariant(string $marker, string $path): int
    {
        $rowPath = Support::parent($path);
        $index = $rowPath === null ? null : (int)substr($rowPath, (int)strrpos($rowPath, '/') + 1);

        return $marker === '<' ? ($index === 0 ? 0 : 1) : ($index === 0 ? 2 : 3);
    }

    /**
     * C2-B-174: the brace block in the prefix of a span mark that joins a cell applies to no cell, and fmt drops it; so does
     * a header mark on it whose row is no head row.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function spanMarkerAttribute(array $node, string $path, Context $c): void
    {
        $span = $node['span'] ?? null;
        if (($node['type'] ?? null) !== 'table_cell' || $span === null) {
            return;
        }
        $prefix = Support::raw($node, $c);
        $header = str_starts_with($prefix, '=');
        $braced = str_starts_with($header ? substr($prefix, 1) : $prefix, '{');
        if (!$braced && (!$header || ($c->nodeTypes[self::groupPath($path)] ?? null) === 'table_head')) {
            return;
        }
        $marker = $span === 'rowspan' ? '^' : '<';
        $values = ['marker' => $marker, 'direction' => $marker === '^' ? 'above it' : 'to its left'];
        Support::reportNode('table-span-marker-attribute', $node, $path, $c, $values, $braced ? 0 : 1);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unevenListIndent(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'list') {
            return;
        }
        $children = Support::kids($node);
        $count = count($children);
        $parent = Support::parent($path);
        $outer = $parent === null ? null : $c->layout->markerColumn($parent);
        for ($at = 1; $at < $count; $at++) {
            $first = $c->layout->markerColumn($path . '/children/' . ($at - 1));
            $next = $c->layout->markerColumn($path . '/children/' . $at);
            if ($first !== null && $next !== null && $next < $first && ($outer === null || $next < $outer) && isset($children[$at])) {
                Support::reportNode('uneven-list-indent', $children[$at], $path . '/children/' . $at, $c);
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
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unresolvedReference(array $node, string $path, Context $c): void
    {
        $type = $node['type'] ?? null;
        if (($type !== 'link' && $type !== 'image') || !isset($node['key'])) {
            return;
        }
        if (isset($node[$type === 'link' ? 'href' : 'src'])) {
            return;
        }
        $c->unresolvedReferences[] = [$node, $path];
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unusedReference(array $node, string $path, Context $c): void
    {
        $type = $node['type'] ?? null;
        $key = Support::str($node, 'key');
        if (($type === 'link' || $type === 'image') && $key !== null) {
            $c->usedReferences[$key] = true;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unresolvedFootnote(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'footnote_ref' && !isset($node['number'])) {
            Support::reportNode('unresolved-footnote', $node, $path, $c, ['id' => Support::str($node, 'key') ?? '']);
        }
    }

    /**
     * @param array<string, mixed> $_node
     * @param string $_path
     * @param \MarkupCarve\Carve\Lint\Context $_c
     *
     * @return void
     */
    public static function unusedFootnote(array $_node, string $_path, Context $_c): void
    {
        // Definition and use keys are collected during every visit, including data mode.
    }

    /**
     * C2-N-072: the attribute line above an include without `code` applies to nothing.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function includeAttributesUnused(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'include' && isset($node['attrs']) && self::option($node, 'code') === null) {
            Support::reportNode('include-attributes-unused', $node, $path, $c);
        }
    }

    /**
     * C2-N-088: `lines` on a delimited table include counts physical lines, where `rows` counts records.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function includeLinesTableRows(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'include' || self::option($node, 'lines') === null) {
            return;
        }
        $code = self::option($node, 'code');
        $table = in_array('table', array_column(Support::keyValues($node), 0), true);
        if ($table && ($code === 'csv' || $code === 'tsv')) {
            $bounds = Support::bounds($node);
            if ($bounds === null || preg_match('/@lines:[^\s}]+/', Support::raw($node, $c), $match, PREG_OFFSET_CAPTURE) !== 1) {
                return;
            }
            $start = $bounds[0] + $match[0][1];
            $c->report('include-lines-table-rows-hint', $start, $start + strlen($match[0][0]), $path);
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
        self::finishUnresolvedReferences($c);
        self::finishFragments($c);
        self::finishReferences($c);
        self::finishFootnotes($c);
    }

    private static function finishUnresolvedReferences(Context $c): void
    {
        $labels = [];
        foreach ($c->referenceDefinitions as [$definition]) {
            $key = Support::str($definition, 'key');
            if ($key !== null) {
                $labels[] = $key;
            }
        }
        $headings = null;
        foreach ($c->unresolvedReferences as [$node, $path]) {
            $type = $node['type'] ?? null;
            $key = Support::str($node, 'key') ?? '';
            $candidates = Support::caseOnly($key, $labels);
            if ($type !== 'image') {
                // C2-I-084: a link also looks up heading text, never a heading id.
                $headings ??= self::headingTexts($c);
                $text = Resolver::fold(PlainText::of(InlineRunner::parse($key, [0, strlen($key)], new InlineContext())));
                foreach (Support::caseOnly($text, $headings) as $heading) {
                    if (!in_array($heading, $candidates, true)) {
                        $candidates[] = $heading;
                    }
                }
            }
            $rawRef = $c->layout->rawRef($path) ?? Support::raw($node, $c);
            $values = ['rawRef' => $rawRef];
            if ($candidates !== []) {
                $values['caseOnlyPhrase'] = Support::caseOnlyPhrase($candidates, 'label');
            }
            $variant = $type === 'image' ? ($candidates === [] ? 3 : 2) : ($candidates === [] ? 1 : 0);
            Support::reportNode('unresolved-reference-link', $node, $path, $c, $values, $variant);
        }
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

    private static function finishFragments(Context $c): void
    {
        if ($c->off('broken-fragment-link') || !empty($c->options['extensions'])) {
            return;
        }
        $ids = null;
        foreach ($c->fragmentLinks as [$node, $path]) {
            $href = Support::str($node, 'href');
            if (($node['type'] ?? null) !== 'link' || $href === null) {
                continue;
            }
            $fragment = explode(':~:', substr($href, 1), 2)[0];
            $decoded = rawurldecode($fragment);
            if (strtolower($fragment) === 'top' || isset($c->ids[$fragment]) || isset($c->ids[$decoded])) {
                continue;
            }
            // C2-H-022: footnote, note mark and raw HTML ids are targets too; only a miss pays for the render.
            $ids ??= self::renderedIds($c);
            if (!isset($ids[$fragment]) && !isset($ids[$decoded])) {
                $candidates = Support::caseOnly($fragment, array_keys($ids));
                foreach (Support::caseOnly($decoded, array_keys($ids)) as $candidate) {
                    if (!in_array($candidate, $candidates, true)) {
                        $candidates[] = $candidate;
                    }
                }
                $values = ['href' => $href];
                if ($candidates !== []) {
                    $values['caseOnly'] = $candidates[0];
                }
                Support::reportNode('broken-fragment-link', $node, $path, $c, $values, $candidates === [] ? 1 : 0);
            }
        }
    }

    /**
     * The plain text of every heading, its whitespace folded as C2-I-079 folds a label, in document order.
     *
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return array<string>
     */
    private static function headingTexts(Context $c): array
    {
        try {
            $stack = [Carve::parse($c->source, array_intersect_key($c->options, ['includes' => true, 'extensions' => true]))];
        } catch (Throwable) {
            return [];
        }
        $texts = [];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->type === 'heading') {
                $text = Resolver::fold(PlainText::of($node->children ?? []));
                if ($text !== '') {
                    $texts[] = $text;
                }

                continue;
            }
            if ($node->type !== 'paragraph') {
                foreach (array_reverse($node->children ?? []) as $child) {
                    $stack[] = $child;
                }
            }
        }

        return $texts;
    }

    /**
     * Every id, and every name of an `a` element, of the rendered HTML, in document order.
     *
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return array<string, true>
     */
    private static function renderedIds(Context $c): array
    {
        try {
            $html = Carve::render($c->source, array_intersect_key($c->options, ['includes' => true, 'headingIds' => true]));
        } catch (Throwable) {
            return $c->ids;
        }
        preg_match_all('/<[a-zA-Z][^>]*?\sid="([^"]*)"|<a\b[^>]*?\sname="([^"]*)"/', $html, $matches, PREG_SET_ORDER);
        $ids = [];
        foreach ($matches as $match) {
            $id = ($match[2] ?? '') !== '' ? $match[2] : ($match[1] ?? '');
            $ids[html_entity_decode($id, ENT_QUOTES | ENT_HTML5, 'UTF-8')] = true;
        }

        return $ids + $c->ids;
    }

    private static function finishReferences(Context $c): void
    {
        if (!$c->active('unused-link-reference-definition')) {
            return;
        }
        foreach ($c->referenceDefinitions as [$node, $path]) {
            if (($node['type'] ?? null) === 'link_reference_definition' && !isset($c->usedReferences[Support::str($node, 'key') ?? ''])) {
                Support::reportNode('unused-link-reference-definition', $node, $path, $c);
            }
        }
    }

    private static function finishFootnotes(Context $c): void
    {
        if (!$c->active('unused-footnote-definition')) {
            return;
        }
        foreach ($c->footnoteDefinitions as [$node, $path]) {
            $key = Support::str($node, 'key') ?? '';
            if (($node['type'] ?? null) === 'footnote' && !isset($c->usedFootnotes[$key])) {
                $bounds = Support::bounds($node);
                if ($bounds !== null) {
                    $raw = Support::raw($node, $c);
                    $open = strpos($raw, '[^');
                    $close = $open === false ? false : strpos($raw, ']', $open + 2);
                    if ($close !== false) {
                        $c->report('unused-footnote-definition', $bounds[0] + $open, $bounds[0] + $close + 1, $path, ['label' => $key]);
                    }
                }
            }
        }
    }

    /**
     * The range of a text node, or null for another node or one without a position.
     *
     * @param array<string, mixed> $node
     *
     * @return array{0: int, 1: int}|null
     */
    private static function textRange(array $node): ?array
    {
        return ($node['type'] ?? null) === 'text' ? Support::bounds($node) : null;
    }

    private static function trimStart(string $text): string
    {
        return ltrim($text, " \t\n\r\v\f");
    }

    /**
     * The JS `indentation` of `structure.ts`: -1 for a line of only spaces and tabs.
     *
     * @param string $text
     *
     * @return int
     */
    private static function indentation(string $text): int
    {
        $at = strspn($text, " \t");

        return $at < strlen($text) ? $at : -1;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $field
     *
     * @return string|null
     */
    private static function markerField(array $node, string $field): ?string
    {
        $marker = $node['marker'] ?? null;

        return is_array($marker) && is_string($marker[$field] ?? null) ? $marker[$field] : null;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $name
     *
     * @return string|null
     */
    private static function option(array $node, string $name): ?string
    {
        $options = $node['options'] ?? null;
        if (!is_array($options) || !isset($options[$name])) {
            return null;
        }

        return is_scalar($options[$name]) ? (string)$options[$name] : '';
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param string $type
     *
     * @return array<string, mixed>|null
     */
    private static function firstOfType(array $nodes, string $type): ?array
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === $type) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Whether the attribute line of a node names `key` (the JS `attributeValue` is not undefined).
     *
     * @param array<string, mixed> $node
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param string $key
     *
     * @return bool
     */
    private static function hasAttribute(array $node, Context $c, string $key): bool
    {
        $pos = Support::pos($node);
        $line = $pos === null ? null : ($c->lines[$pos['startLine'] - 1] ?? null);
        if ($line === null) {
            return false;
        }
        $text = Support::trim($line->text);
        if (!str_starts_with($text, '{') || !str_ends_with($text, '}')) {
            return false;
        }
        $at = strpos($text, $key);

        return $at !== false && ($at === 0 || $text[$at - 1] === '{' || $text[$at - 1] === ' ');
    }

    /**
     * The descendants of a list item that open a block past its content column: path, line, indentation, type, content
     * column.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return array<array{0: string, 1: \MarkupCarve\Carve\Lint\Line, 2: int, 3: string, 4: int}>
     */
    private static function overindented(array $node, string $path, Context $c): array
    {
        $content = ($node['type'] ?? null) === 'list_item' ? $c->layout->contentColumn($path) : null;
        if ($content === null) {
            return [];
        }
        $out = [];
        self::overindentedIn(Support::kids($node), $path, $c, $content, Support::pos($node)['startLine'] ?? -1, $out);

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @param string $parentPath
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param int $content
     * @param int $itemLine
     * @param array<array{0: string, 1: \MarkupCarve\Carve\Lint\Line, 2: int, 3: string, 4: int}> $out
     *
     * @return void
     */
    private static function overindentedIn(array $children, string $parentPath, Context $c, int $content, int $itemLine, array &$out): void
    {
        foreach ($children as $at => $child) {
            $childPath = $parentPath . '/children/' . $at;
            $type = Support::str($child, 'type') ?? '';
            $pos = Support::pos($child);
            if ($type !== 'list' && $type !== 'comment' && $pos !== null) {
                $line = $c->lines[$pos['startLine'] - 1] ?? null;
                if (
                    $line !== null && ($pos['startLine'] !== $itemLine || in_array($type, ['div', 'admonition', 'directive'], true))
                    && ($type !== 'paragraph' || ($child['role'] ?? null) === 'image' || isset($child['attrs']))
                ) {
                    $quotes = $c->layout->quoteDepth($childPath);
                    $start = $c->layout->startOffset($childPath) ?? $line->start + self::indentation($line->text);
                    $indent = $c->layout->columnOf($start, $quotes);
                    if ($indent > $content) {
                        $out[] = [$childPath, $line, $indent, $type, $content];
                    }
                }
            }
            if (in_array($type, ['div', 'admonition', 'directive', 'figure_group'], true)) {
                self::overindentedIn(Support::kids($child), $childPath, $c, $content, $itemLine, $out);
            }
        }
    }

    /**
     * C2-L-027: the offset and length of the colon run that opens a div, behind its attribute lines.
     *
     * @param array<string, mixed> $node
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return array{0: int, 1: int}|null
     */
    private static function openerRun(array $node, Context $c): ?array
    {
        $pos = Support::pos($node);
        if ($pos === null) {
            return null;
        }
        $at = $pos['startOffset'];
        $line = $c->lineOf($at);
        while (($c->source[$at] ?? '') !== ':' && isset($c->lines[$line]) && $line < $pos['endLine']) {
            $next = $c->lines[$line++];
            $at = $next->start + strspn($next->text, " \t>");
        }
        $run = strspn($c->source, ':', $at);

        return $run > 0 ? [$at, $run] : null;
    }

    /**
     * Whether a block inside `node` that ends where it ends takes a colon run of `length` as its own closer (C2-B-125).
     *
     * @param array<string, mixed> $node
     * @param int $length
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return bool
     */
    private static function closesInner(array $node, int $length, Context $c): bool
    {
        $end = Support::bounds($node)[1] ?? null;
        $at = $node;
        while (true) {
            $children = Support::kids($at);
            $last = $children === [] ? null : $children[array_key_last($children)];
            if ($last === null || (Support::bounds($last)[1] ?? null) !== $end) {
                return false;
            }
            $type = $last['type'] ?? null;
            if (in_array($type, ['div', 'directive', 'admonition', 'figure_group', 'line_block'], true)) {
                $run = self::openerRun($last, $c);
                if ($run !== null && $run[1] <= $length) {
                    return true;
                }
            }
            $at = $last;
        }
    }

    private static function allColons(string $value): bool
    {
        return strlen($value) >= 3 && strspn($value, ':') === strlen($value);
    }

    private static function groupPath(string $path): string
    {
        $row = substr($path, 0, (int)strrpos($path, '/children/'));

        return substr($row, 0, (int)strrpos($row, '/children/'));
    }
}
