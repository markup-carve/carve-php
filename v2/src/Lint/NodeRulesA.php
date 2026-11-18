<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Lint;

/**
 * The node rules of carve-js `lint/structure.ts`, first half.
 */
final class NodeRulesA
{
    /**
     * @var array<string>
     */
    private const TABLE_KEYS = ['aligns', 'widths', 'header-rows', 'footer-rows'];

    /**
     * The JS `checkTextPair`: a paired run of `$mark` in a text node.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     * @param string $mark
     * @param string $id
     *
     * @return void
     */
    public static function textPair(array $node, string $path, Context $c, string $mark, string $id): void
    {
        if (($node['type'] ?? null) !== 'text') {
            return;
        }
        $range = Support::bounds($node);
        if ($range === null) {
            return;
        }
        $text = substr($c->source, $range[0], $range[1] - $range[0]);
        $at = 0;
        $length = strlen($text);
        $size = strlen($mark);
        while ($at < $length) {
            $start = strpos($text, $mark, $at);
            if ($start === false || $start + $size + 1 > $length) {
                return;
            }
            $end = strpos($text, $mark, $start + $size + 1);
            if ($end === false) {
                return;
            }
            if (self::pairs($text, $mark, $start, $end)) {
                $c->report($id, $range[0] + $start, $range[0] + $end + $size, $path);
            }
            $at = $end + $size;
        }
    }

    /**
     * @param string $text
     * @param string $mark
     * @param int $start
     * @param int $end
     *
     * @return bool
     */
    private static function pairs(string $text, string $mark, int $start, int $end): bool
    {
        $size = strlen($mark);
        $before = $start > 0 ? $text[$start - 1] : null;
        $after = $text[$end + $size] ?? null;
        $inside = substr($text, $start + $size, $end - $start - $size);

        return $before !== '\\' && $inside !== '' && $inside[0] !== $mark[0] && $inside[strlen($inside) - 1] !== $mark[0]
            && self::jsTrim($inside) === $inside && !Support::isWord($before) && !Support::isWord($after);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function imageAlt(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'image' && ($node['alt'] ?? null) === '' && ($node['decorative'] ?? null) !== true) {
            Support::reportNode('a11y/image-alt', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function emptyDestination(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'link' && ($node['href'] ?? null) === '') {
            Support::reportNode('empty-link-destination', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function looseAttribute(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'list') {
            return;
        }
        foreach (Support::keyValues($node) as [$key]) {
            if ($key === 'loose') {
                Support::reportNode('deprecated-loose-attribute', $node, $path, $c);

                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function tableAttribute(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table' || $c->layout->sourceFormat($path) !== 'pipe') {
            return;
        }
        if (self::attributeValue($node, $c, 'header-rows') !== null) {
            Support::reportNode('deprecated-table-attribute', $node, $path, $c);
        }
    }

    /**
     * C2-B-227: a table keeps a key it reads in `attrs` only when the value is invalid, or `header-rows` on a pipe table.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string>
     */
    private static function keptTableKeys(array $node): array
    {
        if (($node['type'] ?? null) !== 'table') {
            return [];
        }
        $out = [];
        foreach (Support::keyValues($node) as [$key]) {
            if (in_array($key, self::TABLE_KEYS, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function invalidTableAttribute(array $node, string $path, Context $c): void
    {
        $pipe = $c->layout->sourceFormat($path) === 'pipe';
        $kept = self::keptTableKeys($node);
        foreach (Support::keyValues($node) as [$key, $value]) {
            if (!in_array($key, $kept, true)) {
                continue;
            }
            // C2-B-186: a valid count of `header-rows` on a pipe table is deprecated, not invalid.
            if ($pipe && $key === 'header-rows' && preg_match('/^\d*$/', $value) === 1) {
                continue;
            }
            Support::reportNode('table-attribute-invalid', $node, $path, $c, ['key' => $key]);
        }
    }

    /**
     * The offset of a line comment behind the closing pipe of a row line, or -1.
     *
     * @param string $text
     *
     * @return int
     */
    private static function trailingComment(string $text): int
    {
        $pipe = strrpos($text, '|');
        $at = strpos($text, '%%', $pipe === false ? 0 : $pipe + 1);
        if ($at === false || $at === 0) {
            return -1;
        }

        return $text[$at - 1] === ' ' || $text[$at - 1] === "\t" ? $at : -1;
    }

    /**
     * One line of a table for `comment-splits-table-row`.
     *
     * @param array{pending: array<array{0: int, 1: int}>, afterRow: bool} $walk
     * @param int $start
     * @param string $text
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    private static function walkRowLine(array &$walk, int $start, string $text, string $path, Context $c): void
    {
        $end = $start + strlen(self::jsTrimEnd($text));
        if (str_starts_with($text, '%%')) {
            if ($walk['afterRow']) {
                $walk['pending'][] = [$start, $end];
            }

            return;
        }
        $row = str_starts_with($text, '|') || str_starts_with($text, '+');
        if (str_starts_with($text, '+') && $walk['afterRow']) {
            foreach ($walk['pending'] as [$from, $to]) {
                $c->report('comment-splits-table-row', $from, $to, $path);
            }
        }
        $walk['pending'] = [];
        $walk['afterRow'] = $row;
        $comment = $row ? self::trailingComment($text) : -1;
        if ($comment >= 0) {
            $walk['pending'][] = [$start + $comment, $end];
        }
    }

    /**
     * C2-W-137: a comment between a table row and its continuation row (C2-B-175) belongs after the whole row.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function commentSplitsRow(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'table' || $pos === null) {
            return;
        }
        $walk = ['pending' => [], 'afterRow' => false];
        for ($number = $pos['startLine']; $number <= $pos['endLine']; $number++) {
            $line = $c->lines[$number - 1] ?? null;
            if ($line === null) {
                continue;
            }
            $offset = strspn($line->text, "\t >");
            self::walkRowLine($walk, $line->start + $offset, substr($line->text, $offset), $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $type
     *
     * @return int
     */
    private static function groupRows(array $node, string $type): int
    {
        if (($node['type'] ?? null) !== 'table') {
            return 0;
        }
        $rows = 0;
        foreach (Support::kids($node) as $group) {
            if (($group['type'] ?? null) !== $type) {
                continue;
            }
            foreach (Support::kids($group) as $row) {
                if (($row['type'] ?? null) === 'table_row') {
                    $rows++;
                }
            }
        }

        return $rows;
    }

    /**
     * C2-B-187, C2-B-209: a valid count larger than the rows it can take is clamped, and the hint names the count to write.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function rowCountClamped(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'table' || $pos === null) {
            return;
        }
        $head = self::groupRows($node, 'table_head');
        $below = self::groupRows($node, 'table_body') + self::groupRows($node, 'table_foot');
        $limits = [['footer-rows', $below]];
        if ($c->layout->sourceFormat($path) !== 'pipe') {
            array_unshift($limits, ['header-rows', $head + $below]);
        }
        $line = $c->lines[$pos['startLine'] - 1] ?? null;
        foreach ($limits as [$key, $limit]) {
            $value = self::attributeValue($node, $c, $key);
            if ($line === null || $value === null || preg_match('/^\d+$/', $value) !== 1 || (float)$value <= $limit) {
                continue;
            }
            $found = strpos($line->text, $key . '=');
            $at = $found === false ? -1 : $found;
            $c->report(
                'table-row-count-clamped',
                $line->start + $at,
                $line->start + $at + strlen($key) + 1 + strlen($value),
                $path,
                ['key' => $key, 'count' => $value, 'effective' => (string)$limit],
            );
        }
    }

    /**
     * The value of a key in the attribute block on the first line of a node, '' for a bare key, null when absent.
     *
     * @param array<string, mixed> $node
     * @param \MarkupCarve\Carve2\Lint\Context $c
     * @param string $key
     *
     * @return string|null
     */
    private static function attributeValue(array $node, Context $c, string $key): ?string
    {
        $pos = Support::pos($node);
        if ($pos === null) {
            return null;
        }
        $source = $c->lines[$pos['startLine'] - 1] ?? null;
        if ($source === null) {
            return null;
        }
        $line = self::jsTrim($source->text);
        if (!str_starts_with($line, '{') || !str_ends_with($line, '}')) {
            return null;
        }
        $at = strpos($line, $key);
        if ($at === false || ($at > 0 && $line[$at - 1] !== '{' && $line[$at - 1] !== ' ')) {
            return null;
        }
        $equals = $at + strlen($key);
        if (($line[$equals] ?? null) !== '=') {
            return '';
        }

        return self::readAttributeValue($line, $equals + 1);
    }

    /**
     * @param string $line
     * @param int $offset
     *
     * @return string
     */
    private static function readAttributeValue(string $line, int $offset): string
    {
        $end = $offset;
        $quoted = ($line[$end] ?? null) === '"';
        if ($quoted) {
            $end++;
        }
        $start = $end;
        $stop = $quoted ? '"' : ' ';
        $length = strlen($line);
        while ($end < $length && $line[$end] !== $stop && $line[$end] !== '}') {
            $end++;
        }

        return substr($line, $start, $end - $start);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function headingLevel(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'heading' || !is_int($node['level'] ?? null)) {
            return;
        }
        $level = $node['level'];
        $previous = $c->headingLevel;
        if ($previous > 0 && $level > $previous + 1) {
            Support::reportNode('heading-level-skip', $node, $path, $c, ['previous' => (string)$previous, 'level' => (string)$level]);
        }
        $c->headingLevel = $level;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function headingKind(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'heading') {
            return;
        }
        $kind = substr(self::jsTrimStart(Support::raw($node, $c)), 0, 1);
        if ($kind !== '#' && $kind !== '=') {
            return;
        }
        $c->headingKinds[$kind] = true;
        if (count($c->headingKinds) === 2 && !$c->mixedReported) {
            Support::reportNode('heading-marker-mixed', $node, $path, $c);
            $c->mixedReported = true;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function headingClosing(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'heading') {
            return;
        }
        $end = self::jsTrimEnd(Support::raw($node, $c));
        $hash = strrpos($end, ' #');
        if ($hash === false) {
            return;
        }
        $run = substr($end, $hash + 1);
        $range = Support::bounds($node);
        if (strspn($run, '#') === strlen($run) && $range !== null) {
            $c->report('heading-closing-hashes', $range[0] + $hash + 1, $range[0] + strlen($end), $path, ['run' => $run]);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function headingTrailing(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'heading') {
            return;
        }
        $end = self::jsTrimEnd(Support::raw($node, $c));
        $brace = strrpos($end, ' {');
        $range = Support::bounds($node);
        if ($brace !== false && str_ends_with($end, '}') && $range !== null) {
            $c->report('heading-trailing-attribute', $range[0] + $brace + 1, $range[0] + strlen($end), $path, [
                'block' => substr($end, $brace + 1),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function duplicateReferenceUrl(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'link_reference_definition') {
            return;
        }
        $href = Support::str($node, 'href') ?? '';
        if (isset($c->referenceUrls[$href])) {
            Support::reportNode('duplicate-reference-url', $node, $path, $c);
        } else {
            $c->referenceUrls[$href] = true;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function separatorRow(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table_row' || Support::kids($node) === []) {
            return;
        }
        $line = self::jsTrim(Support::raw($node, $c));
        if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
            return;
        }
        foreach (explode('|', (string)substr($line, 1, -1)) as $cell) {
            if (!self::isSeparatorCell($cell)) {
                return;
            }
        }
        Support::reportNode('deprecated-separator-row', $node, $path, $c);
    }

    /**
     * @param string $value
     *
     * @return bool
     */
    private static function isSeparatorCell(string $value): bool
    {
        $text = self::jsTrim($value);
        if (strlen($text) < 3 || strspn($text, '-:') !== strlen($text)) {
            return false;
        }

        return substr_count($text, '-') >= 3;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function tableRows(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table') {
            return;
        }
        $width = -1;
        foreach (Support::kids($node) as $outer => $section) {
            if (!in_array($section['type'] ?? null, ['table_head', 'table_body', 'table_foot'], true)) {
                continue;
            }
            foreach (Support::kids($section) as $inner => $row) {
                if (($row['type'] ?? null) !== 'table_row') {
                    continue;
                }
                $cells = count(Support::kids($row));
                if ($width < 0) {
                    $width = $cells;
                } elseif ($cells !== $width) {
                    Support::reportNode('table-row-cell-count', $row, "{$path}/children/{$outer}/children/{$inner}", $c, [
                        'cells' => (string)$cells,
                        'width' => (string)$width,
                    ]);
                }
            }
        }
    }

    /**
     * C2-A-084: the children of a group that carry a caption.
     *
     * @param array<string, mixed> $node
     *
     * @return int
     */
    private static function panelCount(array $node): int
    {
        if (($node['type'] ?? null) !== 'figure_group') {
            return 0;
        }
        $count = 0;
        foreach (Support::kids($node) as $child) {
            if (isset($child['caption'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function figureEmpty(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'figure_group' && self::panelCount($node) === 0) {
            Support::reportNode('figure-group-empty', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function figureSingle(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'figure_group' && self::panelCount($node) === 1) {
            Support::reportNode('figure-group-single-panel', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function substitutionArrow(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'strike' && str_contains(Support::raw($node, $c), '~>')) {
            Support::reportNode('deprecated-substitution-arrow', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function frontmatterBreak(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'frontmatter') {
            return;
        }
        $value = self::jsTrim(Support::str($node, 'value') ?? '');
        if ($value === '' || !str_contains($value, ':')) {
            Support::reportNode('frontmatter-shaped-thematic-break', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function bidiControl(array $node, string $path, Context $c): void
    {
        if (!in_array($node['type'] ?? null, ['text', 'code', 'code_block', 'math'], true)) {
            return;
        }
        $range = Support::bounds($node);
        if ($range === null) {
            return;
        }
        $text = substr($c->source, $range[0], $range[1] - $range[0]);
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
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function multilineTerm(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'definition_term' || $pos === null || $pos['endLine'] <= $pos['startLine']) {
            return;
        }
        $line = $c->lines[$pos['startLine']] ?? null;
        if ($line !== null) {
            $c->report('description-term-multi-line', $line->start, $line->end, $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function headingContinuation(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'heading' || $pos === null || $pos['endLine'] <= $pos['startLine']) {
            return;
        }
        $first = $c->lines[$pos['startLine'] - 1] ?? null;
        // C2-B-137: the line that continues the heading may stand below comment-only lines.
        $next = $pos['startLine'];
        while ($next < $pos['endLine'] && isset($c->lines[$next]) && str_starts_with(self::jsTrimStart($c->lines[$next]->text), '%%')) {
            $next++;
        }
        $second = $next < $pos['endLine'] ? $c->lines[$next] ?? null : null;
        if ($first === null || $second === null) {
            return;
        }
        $width = strspn(self::jsTrimStart($first->text), '#=');
        $indent = strspn($second->text, ' ');
        if ($indent > $width) {
            $c->report('deprecated-indented-line-under-heading', $second->start, $second->end, $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function figureNested(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'figure_group') {
            return;
        }
        $parent = Support::parent($path);
        if ($parent !== null && ($c->nodeTypes[$parent] ?? null) === 'figure_group') {
            Support::reportNode('figure-group-nested', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
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
            if ($caption !== null && str_contains(Support::raw($caption, $c), '#')) {
                Support::reportNode('figure-group-panel-number', $caption, "{$path}/children/{$at}/caption", $c);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function footnotePlacement(array $node, string $path, Context $c): void
    {
        if (self::nestedDirective($node, $path, 'footnotes')) {
            Support::reportNode('footnotes-placement-in-container', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
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
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function semanticOutside(array $node, string $path, Context $c): void
    {
        $type = Support::str($node, 'type') ?? '';
        if ($type === 'span') {
            return;
        }
        foreach (Support::keyValues($node) as [$name, $value]) {
            if (in_array($name, ['abbr', 'dfn', 'time', 'mark'], true)) {
                Support::reportNode('semantic-attribute-outside-span', $node, $path, $c, [
                    'name' => $name,
                    'type' => $type,
                    'value' => $value,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function semanticValue(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'span') {
            return;
        }
        foreach (Support::keyValues($node) as [$name, $value]) {
            if ($value !== '' && in_array($name, ['mark', 'kbd', 'samp'], true)) {
                Support::reportNode('semantic-attribute-value-ignored', $node, $path, $c, ['name' => $name]);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function tableColumnArity(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table') {
            return;
        }
        $columns = count(Support::kids($node, 'columns'));
        $kept = self::keptTableKeys($node);
        foreach (['aligns', 'widths'] as $key) {
            $value = self::attributeValue($node, $c, $key);
            // C2-B-211: an invalid list sets no column, and table-attribute-invalid reports it.
            if ($value === null || in_array($key, $kept, true)) {
                continue;
            }
            $count = count(explode(',', $value));
            if ($count < $columns) {
                Support::reportNode('table-column-arity', $node, $path, $c, [
                    'key' => $key,
                    'count' => (string)$count,
                    'widest' => (string)$columns,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function tableWidth(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table') {
            return;
        }
        $total = 0.0;
        foreach (Support::kids($node, 'columns') as $column) {
            $width = $column['width'] ?? 0;
            $total += is_int($width) || is_float($width) ? $width : 0;
        }
        if ($total > 1) {
            Support::reportNode('table-width-total', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function rowAttributeCell(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table_row') {
            return;
        }
        $source = Support::raw($node, $c);
        $last = strrpos($source, '|');
        if ($last === false || $last === 0) {
            return;
        }
        $before = strrpos(substr($source, 0, $last), '|');
        if ($before === false) {
            return;
        }
        $cell = self::jsTrim(substr($source, $before + 1, $last - $before - 1));
        if (str_starts_with($cell, '{') && str_ends_with($cell, '}')) {
            Support::reportNode('table-row-attribute-as-cell', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function emphasizedHeading(array $node, string $path, Context $c): void
    {
        $kids = Support::kids($node);
        if (($node['type'] ?? null) === 'paragraph' && count($kids) === 1 && (($kids[0] ?? [])['type'] ?? null) === 'emphasis') {
            Support::reportNode('emphasis-as-heading', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function commentEmphasis(array $node, string $path, Context $c): void
    {
        $raw = Support::raw($node, $c);
        if (($node['type'] ?? null) === 'emphasis' && str_starts_with($raw, '/*') && str_ends_with($raw, '*/')) {
            Support::reportNode('comment-shaped-emphasis', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function globStrong(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'strong' && str_contains(Support::raw($node, $c), '*.')) {
            Support::reportNode('glob-shaped-strong', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function djotBracedStrike(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'strike' && str_starts_with(Support::raw($node, $c), '{~')) {
            Support::reportNode('djot-subscript-tilde-braced', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function djotBracedHighlight(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'highlight' && str_starts_with(Support::raw($node, $c), '{=')) {
            Support::reportNode('djot-highlight-braces', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function unattachedInline(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'text') {
            return;
        }
        $source = Support::raw($node, $c);
        $at = strpos($source, '{.');
        if ($at === false || $at < 1) {
            return;
        }
        $end = strpos($source, '}', $at + 2);
        $range = Support::bounds($node);
        if ($end !== false && $range !== null) {
            $c->report('unattached-inline-attribute', $range[0] + $at, $range[0] + $end + 1, $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function deprecatedCellAlignment(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table_cell') {
            return;
        }
        $source = self::jsTrimStart(Support::raw($node, $c));
        $prefix = $source[0] ?? '';
        $space = $source[1] ?? '';
        if (!in_array($prefix, ['<', '>', '~'], true) || ($space !== ' ' && $space !== "\t")) {
            return;
        }
        if (self::jsTrim(substr($source, 1)) === '') {
            return;
        }
        Support::reportNode('deprecated-cell-alignment', $node, $path, $c, ['prefix' => $prefix, 'braced' => '{' . $prefix . '}']);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function markerRunPadding(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table_cell') {
            return;
        }
        $source = self::jsTrimStart(Support::raw($node, $c));
        if (!str_starts_with($source, '{')) {
            return;
        }
        $close = strpos($source, '}');
        if ($close === false || $close < 2) {
            return;
        }
        $marker = $source[$close + 1] ?? '';
        if (!in_array($marker, ['<', '>', '~'], true)) {
            return;
        }
        $block = substr($source, 0, $close + 1);
        Support::reportNode('table-marker-run-padding', $node, $path, $c, [
            'spelling' => $block . $marker,
            'marker' => $marker,
            'block' => $block,
        ]);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function fenceCloserIndent(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'code_block' || $pos === null || $pos['endLine'] <= $pos['startLine']) {
            return;
        }
        $first = $c->lines[$pos['startLine'] - 1] ?? null;
        $last = $c->lines[$pos['endLine'] - 1] ?? null;
        if ($first === null || $last === null) {
            return;
        }
        $opener = strpos($first->text, '```');
        $closer = strpos($last->text, '```');
        if ($opener === false || $closer === false || $opener === $closer) {
            return;
        }
        $c->report('fence-closer-indented', $last->start + $closer, $last->end, $path);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function figureOpenerMetadata(array $node, string $path, Context $c): void
    {
        $attrs = $node['attrs'] ?? null;
        $classes = is_array($attrs) && is_array($attrs['classes'] ?? null) ? $attrs['classes'] : [];
        if (($node['type'] ?? null) !== 'div' || !in_array('figure', $classes, true)) {
            return;
        }
        $pos = Support::pos($node);
        $first = $c->lines[$pos === null ? 0 : $pos['startLine'] - 1] ?? null;
        if ($first === null || !str_starts_with(self::jsTrimStart($first->text), '::: figure ')) {
            return;
        }
        if (str_contains($first->text, '"') || str_contains($first->text, '[')) {
            $c->report('figure-group-opener-metadata', $first->start, $first->end, $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function commentInLineBlock(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) === 'line' && str_starts_with(self::jsTrimStart(Support::raw($node, $c)), '%%')) {
            Support::reportNode('comment-line-in-line-block', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
     *
     * @return void
     */
    public static function brokenCrossref(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'xref' || ($node['namespace'] ?? null) !== 'heading' || array_key_exists('href', $node)) {
            return;
        }
        Support::reportNode('broken-crossref', $node, $path, $c, ['target' => Support::str($node, 'key') ?? ''], 2);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve2\Lint\Context $c
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
     * @param \MarkupCarve\Carve2\Lint\Context $c
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
     * @param \MarkupCarve\Carve2\Lint\Context $c
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
