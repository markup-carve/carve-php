<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
            while ($end !== false && !self::pairs($text, $mark, $start, $end, $id)) {
                $next = $end + $size;
                $end = $next < $length ? strpos($text, $mark, $next) : false;
            }
            if ($end === false) {
                $at = $start + $size;

                continue;
            }
            if (self::pairs($text, $mark, $start, $end, $id)) {
                $c->report($id, $range[0] + $start, $range[0] + $end + $size, $path);
            }
            $at = $end + $size;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function multilineMarkdownStrong(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'paragraph') {
            return;
        }
        $bounds = Support::bounds($node);
        if ($bounds === null) {
            return;
        }
        $raw = Support::raw($node, $c);
        preg_match_all('/\*\*([^*]+)\*\*/s', $raw, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => [$whole, $offset]) {
            $inside = $matches[1][$i][0];
            if (!str_contains($inside, "\n") || str_contains($inside, "\n\n") || self::jsTrim($inside) !== $inside) {
                continue;
            }
            $start = $bounds[0] + $offset;
            $end = $start + strlen($whole);
            $masked = false;
            foreach ($c->leafMask as [$from, $to]) {
                $masked = $masked || ($from < $end && $to > $start);
            }
            if ($masked) {
                continue;
            }
            $owner = $path;
            foreach (Support::kids($node) as $at => $child) {
                $part = Support::bounds($child);
                if ($part !== null && $part[0] <= $start && $part[1] > $start) {
                    $owner = $path . '/children/' . $at;

                    break;
                }
            }
            $c->report('markdown-strong-double-star', $start, $end, $owner);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param string $mark
     * @param string $id
     *
     * @return void
     */
    public static function multilineBarePair(array $node, string $path, Context $c, string $mark, string $id): void
    {
        if (($node['type'] ?? null) !== 'paragraph') {
            return;
        }
        $bounds = Support::bounds($node);
        if ($bounds === null) {
            return;
        }
        $raw = Support::raw($node, $c);
        $first = strpos($raw, $mark);
        if ($first === false) {
            return;
        }
        if ($first + 2 >= strlen($raw)) {
            return;
        }
        $last = strpos($raw, $mark, $first + 2);
        if (
            $last === false || !str_contains(substr($raw, $first, $last - $first), "\n")
            || !self::pairs($raw, $mark, $first, $last, $id)
        ) {
            return;
        }
        $c->report($id, $bounds[0] + $first, $bounds[0] + $last + 1, $path);
    }

    /**
     * @param string $text
     * @param string $mark
     * @param int $start
     * @param int $end
     * @param string $id
     *
     * @return bool
     */
    private static function pairs(string $text, string $mark, int $start, int $end, string $id): bool
    {
        $size = strlen($mark);
        $before = $start > 0 ? $text[$start - 1] : null;
        $inside = substr($text, $start + $size, $end - $start - $size);

        if ($mark === '**') {
            return $before !== '\\' && $inside !== '' && !str_contains($inside, '*') && !str_contains($inside, "\n\n")
                && self::jsTrim($inside) === $inside;
        }

        if ($id === 'djot-subscript-tilde' || $id === 'djot-superscript-caret') {
            return $before !== '\\' && $before !== '{' && $inside !== '' && self::jsTrimEnd($inside) === $inside
                && !in_array($inside[0], [' ', "\t", "\n", '}'], true);
        }

        $priorWord = preg_match('/[\p{L}\p{N}_]$/u', substr($text, 0, $start)) === 1;
        $nextWord = preg_match('/^[\p{L}\p{N}_]/u', substr($text, $end + $size)) === 1;
        if ($mark === '_' && preg_match('/[\p{L}\p{N}]_[\p{L}\p{N}]/u', $inside) === 1) {
            return false;
        }

        return $before !== '\\' && $inside !== '' && $inside[0] !== $mark[0] && $inside[strlen($inside) - 1] !== $mark[0]
            && self::jsTrim($inside) === $inside && !$priorWord && !$nextWord;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
            $range = self::tableKeyRange($node, $key, $c);
            if ($range !== null) {
                $end = $range[1] + 1 + strlen($value);
                $c->report('table-attribute-invalid', $range[0], $end, $path, ['key' => $key]);
            }
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
            $walk['pending'][] = [$start + max(0, $comment - 1), $end];
        }
    }

    /**
     * C2-W-137: a comment between a table row and its continuation row (C2-B-175) belongs after the whole row.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
        $bounds = Support::bounds($node);
        if ($bounds !== null) {
            $c->firstHeading ??= [$bounds[0], $bounds[1], $path];
        }
        $c->headingKinds[$kind] = true;
        if (count($c->headingKinds) === 2 && !$c->mixedReported) {
            if ($c->firstHeading !== null) {
                [$start, $end, $firstPath] = $c->firstHeading;
                $c->report('heading-marker-mixed', $start, $end, $firstPath);
            }
            $c->mixedReported = true;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
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
        $before = preg_replace('/^[=#]+[ \t]*/', '', substr($end, 0, $hash));
        if (strspn($run, '#') === strlen($run) && $range !== null && trim((string)$before) !== '') {
            $c->report('heading-closing-hashes', $range[0] + $hash + 1, $range[0] + strlen($end), $path, ['run' => $run]);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
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
        if (
            $brace !== false && str_ends_with($end, '}') && $range !== null
            && preg_match('/^\{[ \t]*[.#][^{}]*\}$/', substr($end, $brace + 1)) === 1
        ) {
            $c->report('heading-trailing-attribute', $range[0] + $brace + 1, $range[0] + strlen($end), $path, [
                'block' => substr($end, $brace + 1),
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
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function separatorRow(array $node, string $path, Context $c): void
    {
        if (
            ($node['type'] ?? null) !== 'table_row' || Support::kids($node) === []
            || (int)substr($path, (int)strrpos($path, '/') + 1) !== 1
        ) {
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
        if ($text === '' || strspn($text, '-:') !== strlen($text)) {
            return false;
        }

        return substr_count($text, '-') >= 1;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
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
            if (isset($child['caption']) || ($child['type'] ?? null) === 'table') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
    public static function frontmatterBreak(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'frontmatter') {
            return;
        }
        $value = self::jsTrim(Support::str($node, 'value') ?? '');
        $pos = Support::pos($node);
        $first = $pos === null ? null : ($c->lines[$pos['startLine'] - 1] ?? null);
        $firstValue = $pos === null ? null : ($c->lines[$pos['startLine']] ?? null);
        if (
            $first !== null && Support::trim($first->text) === '---'
            && $firstValue !== null && $pos['endLine'] > $pos['startLine']
            && ($value === '' || !str_contains($value, ':') || Support::trim($firstValue->text) === '')
        ) {
            Support::reportNode('frontmatter-shaped-thematic-break', $node, $path, $c);
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
    public static function multilineTerm(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'definition_term' || $pos === null || $pos['endLine'] <= $pos['startLine']) {
            return;
        }
        $line = $c->lines[$pos['startLine']] ?? null;
        if ($line !== null) {
            $indent = strspn($line->text, " \t");
            $c->report('description-term-multi-line', $line->start + $indent, $line->end, $path);
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
        $markerColumn = $c->layout->columnOf($pos['startOffset'], $c->layout->quoteDepth($path));
        $indentOffset = strspn($second->text, " \t>");
        $quotes = $c->layout->quoteDepth($path);
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
    public static function figureNested(array $node, string $path, Context $c): void
    {
        $attrs = $node['attrs'] ?? null;
        $classes = is_array($attrs) ? ($attrs['classes'] ?? []) : [];
        if (
            ($node['type'] ?? null) !== 'div' || !is_array($classes) || !in_array('figure', $classes, true)
            || preg_match('/^:{3,}[ \t]+figure[ \t]*$/m', Support::raw($node, $c)) !== 1
        ) {
            return;
        }
        $parent = Support::parent($path);
        while ($parent !== null) {
            if (($c->nodeTypes[$parent] ?? null) === 'figure_group') {
                Support::reportNode('figure-group-nested', $node, $path, $c);

                return;
            }
            $parent = Support::parent($parent);
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
    public static function footnotePlacement(array $node, string $path, Context $c): void
    {
        if (self::nestedDirective($node, $path, 'footnotes')) {
            Support::reportNode('footnotes-placement-in-container', $node, $path, $c);
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
    public static function semanticOutside(array $node, string $path, Context $c): void
    {
        $type = Support::str($node, 'type') ?? '';
        if ($type === 'span' || !is_array($node['attrs'] ?? null)) {
            return;
        }
        $bounds = Support::bounds($node);
        if ($bounds === null) {
            return;
        }
        $raw = Support::raw($node, $c);
        $names = implode('|', array_map(static fn (array $pair): string => $pair[0], $c->semanticKeys()));
        $pattern = '/(?<![A-Za-z0-9_-])(' . $names . ')(?:=(?:"(?:\\\\.|[^"])*"|[^\s}]+))?(?=[\s}])/';
        preg_match_all($pattern, $raw, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $index => [$spelling, $offset]) {
            $before = substr($raw, 0, $offset);
            $open = strrpos($before, '{');
            $close = strrpos($before, '}');
            if ($open === false || ($close !== false && $close > $open)) {
                continue;
            }
            $name = $matches[1][$index][0];
            $c->report('semantic-attribute-outside-span', $bounds[0] + $offset, $bounds[0] + $offset + strlen($spelling), $path, [
                'name' => $name,
                'type' => $type,
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
    public static function semanticValue(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'span') {
            return;
        }
        foreach (Support::keyValues($node) as [$name, $value]) {
            if ($value !== '' && in_array([$name, null], $c->semanticKeys(), true)) {
                $bounds = Support::bounds($node);
                if ($bounds === null) {
                    continue;
                }
                $raw = Support::raw($node, $c);
                if (preg_match('/\b(' . $name . '=(?:"(?:\\\\.|[^"])*"|[^\s}]+))/', $raw, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $start = $bounds[0] + $match[1][1];
                    $c->report('semantic-attribute-value-ignored', $start, $start + strlen($match[1][0]), $path, ['name' => $name]);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $key
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return array{0: int, 1: int}|null
     */
    public static function tableKeyRange(array $node, string $key, Context $c): ?array
    {
        $bounds = Support::bounds($node);
        if ($bounds === null) {
            return null;
        }
        $raw = Support::raw($node, $c);
        if (preg_match('/\{[^}]*?\b(' . preg_quote($key, '/') . ')=/', $raw, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = $bounds[0] + $match[1][1];

        return [$start, $start + strlen($key)];
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
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
                $range = self::tableKeyRange($node, $key, $c);
                if ($range === null) {
                    continue;
                }
                $c->report('table-column-arity', $range[0], $range[1], $path, [
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
            $range = self::tableKeyRange($node, 'widths', $c);
            if ($range !== null) {
                $c->report('table-width-total', $range[0], $range[1], $path);
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
        $cells = Support::kids($node);
        $lastCell = $cells[count($cells) - 1] ?? [];
        if (str_starts_with($cell, '{') && str_ends_with($cell, '}') && !isset($lastCell['attrs'])) {
            Support::reportNode('table-row-attribute-as-cell', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function emphasizedHeading(array $node, string $path, Context $c): void
    {
        $kids = Support::kids($node);
        if (
            ($node['type'] ?? null) === 'paragraph' && count($kids) === 1
            && in_array(($kids[0] ?? [])['type'] ?? null, ['emphasis', 'strong'], true)
        ) {
            Support::reportNode('emphasis-as-heading', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function commentEmphasis(array $node, string $path, Context $c): void
    {
        $raw = Support::raw($node, $c);
        $type = $node['type'] ?? null;
        if (
            ($type === 'emphasis' && (str_starts_with($raw, '/*') || str_ends_with($raw, '*/')))
            || ($type === 'strong' && (str_starts_with($raw, '*/') || str_ends_with($raw, '/*')))
        ) {
            Support::reportNode('comment-shaped-emphasis', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function globStrong(array $node, string $path, Context $c): void
    {
        if (
            ($node['type'] ?? null) === 'strong' && str_starts_with(Support::raw($node, $c), '*.')
            && str_ends_with(Support::raw($node, $c), '*')
        ) {
            Support::reportNode('glob-shaped-strong', $node, $path, $c);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unattachedInline(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'text') {
            return;
        }
        $parent = substr($path, 0, (int)strrpos($path, '/children/'));
        if (($c->nodeTypes[$parent] ?? '') === 'cell_paragraph') {
            return;
        }
        $source = Support::raw($node, $c);
        $range = Support::bounds($node);
        if ($range === null || preg_match_all('/(?<!\\\\)\{[.#][^{}]*\}/', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }
        foreach ($matches[0] as [$block, $at]) {
            if ($at < 1) {
                continue;
            }
            $c->report('unattached-inline-attribute', $range[0] + $at, $range[0] + $at + strlen($block), $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function deprecatedCellAlignment(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'table_cell') {
            return;
        }
        $raw = Support::raw($node, $c);
        $source = ($node['header'] ?? false) && str_starts_with($raw, '=') ? substr($raw, 1) : $raw;
        $run = self::oldAlignmentRun(self::jsTrimStart($source));
        if ($run === null) {
            return;
        }
        $bounds = Support::bounds($node);
        if ($bounds !== null) {
            $start = $bounds[0] + (int)strpos($raw, $run['prefix']);
            $c->report('deprecated-cell-alignment', $start, $start + strlen($run['prefix']), $path, $run);
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
        $bounds = Support::bounds($node);
        if ($bounds === null) {
            return;
        }
        $inner = ltrim(substr($block, 1, -1));
        $corrected = '{' . $marker . ($inner === '' ? '' : ' ' . $inner) . '}';
        $c->report('table-marker-run-padding', $bounds[0], $bounds[0] + $close + 2, $path, [
            'spelling' => $block . $marker,
            'marker' => $marker,
            'block' => $block,
            'correctedPrefix' => $corrected,
        ]);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
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
        if ($first === null || $last === null || preg_match('/^[ \t]*`{3,}[ \t]*$/', $last->text) !== 1) {
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
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
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function brokenCrossref(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'xref' || ($node['namespace'] ?? null) !== 'heading' || array_key_exists('href', $node)) {
            return;
        }
        $target = Support::str($node, 'key') ?? '';
        $candidates = Support::caseOnly($target, array_keys($c->ids));
        if ($candidates !== []) {
            Support::reportNode('broken-crossref', $node, $path, $c, [
                'target' => $target,
                'caseOnlyPhrase' => Support::caseOnlyPhrase($candidates, 'id'),
            ], 1);

            return;
        }
        Support::reportNode('broken-crossref', $node, $path, $c, ['target' => $target], 2);
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
