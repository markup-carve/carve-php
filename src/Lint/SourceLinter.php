<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Stamp;

class SourceLinter
{
    /**
     * @var string
     */
    private const ITEM = '/^([ \t]*)([-*]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-zA-Z])[.)])(\{[^{}\r\n]*\})?( +)(?:\[[ xX_?>-]\] +)?/';

    /**
     * @var string
     */
    private const BLOCK = '/^(?:#{1,6} +\S|>(?: |$)|`{3,}|~{3,}|::(?: |$)|:{3,}(?: |$)|!\[|\[[^\]]+\]: +\S|(?:-{3,}|\*{3,}|_{3,})[ \t]*$|\{[^{}]+\}[ \t]*$|\|.*\|[ \t]*$)/';

    /**
     * @param string $source
     * @param callable(): void|null $onRecoveredContainerMetadata
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, ?callable $onRecoveredContainerMetadata = null): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $rows = preg_split('/\r\n|\r|\n/', $source, flags: PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $rowCount = count($rows);
        $map = SourceOffsets::map($source);
        $validUtf8 = mb_check_encoding($source, 'UTF-8');
        $prefixCounts = $validUtf8 ? [] : SourceOffsets::asciiPrefixCounts($source);
        $length = strlen($source);
        $warnings = [];
        $emit = static function (int $line, int $at, int $size, string $rule, string $message) use (&$warnings, $rows, $length, $map, $validUtf8, $prefixCounts): void {
            if (!isset($rows[$line - 1])) {
                return;
            }
            [$text, $start] = $rows[$line - 1];
            $at = min($at, strlen($text));
            $column = $validUtf8 ? SourceOffsets::toCodepoint($start + $at, $map) - SourceOffsets::toCodepoint($start, $map) + 1
                : (isset($prefixCounts[$start + $at], $prefixCounts[$start])
                    ? $prefixCounts[$start + $at] - $prefixCounts[$start] + 1 : SourceOffsets::toColumn($text, $at));
            $warnings[] = new LintWarning($line, $column, $rule, $message, $start + $at, min($start + $at + $size, $length));
        };
        foreach ($converter->getParser()->getUnattachedBlockAttributes() as $span) {
            $warnings[] = new LintWarning(
                $span->startLine,
                $span->startColumn,
                'unattached-block-attribute',
                'This block attribute reaches no block before its document or container ends. Move it above the block it describes, or delete it.',
                SourceOffsets::toByte($span->startOffset, $map, $length),
                SourceOffsets::toByte($span->endOffset, $map, $length),
            );
        }
        $ignored = [];
        $inlineVerbatim = [];
        $ambiguousFenceEnds = [];
        $paragraphs = [];
        $headings = [];
        $blockRuns = [];
        $starts = [];
        $items = [];
        $fences = [];
        $textSpans = [];
        $pending = [[$document, 0]];
        while ($pending !== []) {
            [$node, $depth] = array_pop($pending);
            $type = $node->getType();
            $pos = $node->getPos();
            if ($pos !== null) {
                if (in_array($type, ['text', 'mention', 'tag'], true)) {
                    $textSpans[] = [SourceOffsets::toByte($pos->startOffset, $map, $length), SourceOffsets::toByte($pos->endOffset, $map, $length)];
                }
                // A multi-line inline verbatim span owns its later lines, so they open no block.
                if ($type === 'code' && $pos->endLine > $pos->startLine) {
                    $opening = $rows[$pos->startLine - 1][0] ?? '';
                    if (preg_match('/^[ \t]+`{3,}/', $opening)) {
                        $ambiguousFenceEnds[$pos->endLine] = true;
                    }
                }
                if (in_array($type, ['code', 'math', 'raw_inline'], true)) {
                    for ($ln = $pos->startLine + 1; $ln <= $pos->endLine; $ln++) {
                        $inlineVerbatim[$ln] = true;
                    }
                }
                if (in_array($type, ['code_block', 'raw_block', 'comment', 'frontmatter'], true)) {
                    for ($ln = $pos->startLine; $ln <= $pos->endLine; $ln++) {
                        $ignored[$ln] = true;
                    }
                }
                if ($type === 'paragraph') {
                    for ($ln = $pos->startLine; $ln <= $pos->endLine; $ln++) {
                        $paragraphs[$ln] = true;
                    }
                    $first = $node->getChildren()[0] ?? null;
                    if ($first instanceof Text && $first->getPos() !== null) {
                        $starts[] = [$first->getPos(), $first->getContent()];
                    }
                }
                if (in_array($type, ['block_quote', 'table'], true)) {
                    $opening = $rows[$pos->startLine - 1][0] ?? '';
                    $at = strlen(mb_substr($opening, 0, $pos->startColumn - 1, 'UTF-8'));
                    $column = self::visual(substr($opening, 0, $at));
                    $kind = $type === 'table' ? '|' : '>';
                    for ($ln = $pos->startLine; $ln <= $pos->endLine; $ln++) {
                        $blockRuns[$kind][$ln][] = [$pos->startLine, $column];
                    }
                }
                if ($type === 'heading') {
                    $headings[$pos->endLine] = true;
                }
                if (in_array($type, ['div', 'admonition', 'figure_group', 'line_block', 'directive'], true) || ($node instanceof BlockQuote && $node->isFenced())) {
                    for ($ln = $pos->startLine; $ln <= min($pos->endLine, $rowCount); $ln++) {
                        $text = $rows[$ln - 1][0];
                        $view = ltrim(self::containerView($ln === 1 ? preg_replace('/^\x{FEFF}/u', '', $text) ?? $text : $text), " \t");
                        if (preg_match('/^(:{3,})(?:[ \t]|$)/', $view, $match)) {
                            $fences[] = ['first' => $ln, 'last' => $pos->endLine, 'column' => strlen($text) - strlen($view), 'width' => strlen($match[1]), 'bare' => trim($view) === $match[1]];
                            if ($depth > 1 && preg_match('/^:{3,} +footnotes[ \t]*$/', $view)) {
                                $warnings[] = new LintWarning($pos->startLine, $pos->startColumn, 'footnotes-placement-in-container', 'This contained footnotes marker does not place the endnotes. Move it to document level.', SourceOffsets::toByte($pos->startOffset, $map, $length), SourceOffsets::toByte($pos->endOffset, $map, $length));
                            }

                            break;
                        }
                        if (!str_starts_with(ltrim($text), '{')) {
                            break;
                        }
                    }
                }
                if ($node instanceof ListItem && isset($rows[$pos->startLine - 1])) {
                    $text = $rows[$pos->startLine - 1][0];
                    $at = strlen(mb_substr($text, 0, $pos->startColumn > 1 ? $pos->startColumn - 1 : 0, 'UTF-8'));
                    if (preg_match(self::ITEM, substr($text, $at), $match)) {
                        $base = self::visual(substr($text, 0, $at)) + self::visual($match[1]);
                        $content = $base + mb_strlen($match[2], 'UTF-8') + strlen($match[4]);
                        $rest = substr($text, $at);
                        $deepest = self::visual(substr($text, 0, $at));
                        while (preg_match(self::ITEM, $rest, $nested)) {
                            $deepest += self::visual($nested[1]) + mb_strlen($nested[2], 'UTF-8') + strlen($nested[4]);
                            $rest = substr($rest, strlen($nested[0]));
                        }
                        $items[] = ['first' => $pos->startLine, 'last' => $pos->endLine, 'base' => $base, 'content' => $content, 'quotes' => substr_count(substr($text, 0, $at), '>'), 'deepest' => $deepest];
                    }
                }
            }
            foreach (array_reverse($node->getChildren()) as $child) {
                $pending[] = [$child, $depth + 1];
            }
        }
        sort($textSpans);
        $textRuns = [];
        foreach ($textSpans as [$from, $to]) {
            $last = array_key_last($textRuns);
            if ($last !== null && $textRuns[$last][1] === $from) {
                $textRuns[$last][1] = $to;
            } else {
                $textRuns[] = [$from, $to];
            }
        }
        $inText = static function (int $from, int $to) use ($textRuns): bool {
            $lo = 0;
            $hi = count($textRuns);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($textRuns[$mid][0] <= $from) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            return $lo > 0 && $textRuns[$lo - 1][1] >= $to;
        };
        foreach (
            [
                'djot-plus-bullet' => '/^[ \t]*\K\+(?=[ \t]+\S)/m',
                'djot-superscript-caret' => '/(?<![{[])\^(?![\s[])((?:(?!\n[ \t]*\n)[^\^])+?)(?<![\s[])\^(?!\})/u',
            ] as $rule => $pattern
        ) {
            preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$match, $from]) {
                $slashes = 0;
                for ($i = $from - 1; $i >= 0 && $source[$i] === '\\'; $i--) {
                    $slashes++;
                }
                $to = $from + strlen($match);
                if ($slashes % 2 === 1 || !$inText($from, $from + 1) || !$inText($to - 1, $to)) {
                    continue;
                }
                $index = 0;
                while ($index + 1 < $rowCount && $rows[$index + 1][1] <= $from) {
                    $index++;
                }
                $emit($index + 1, $from - $rows[$index][1], strlen($match), $rule, $rule === 'djot-plus-bullet'
                    ? 'A plus bullet is literal text in Carve. Use a dash for a list item.'
                    : 'Bare carets are literal text in Carve. Use {^text^} for superscript.');
            }
        }
        $listLines = [];
        foreach ((new DefinitionTermFoldLinter())->lint($source) as $warning) {
            $listLines[$warning->line] = true;
        }
        usort($items, static fn (array $a, array $b): int => ($a['first'] <=> $b['first']) ?: ($a['content'] <=> $b['content']));
        $active = [];
        $nextItem = 0;
        $ended = null;
        $openFence = null;
        $previousRun = null;
        foreach ($rows as $index => [$text, $start]) {
            $ln = $index + 1;
            while (isset($items[$nextItem]) && $items[$nextItem]['first'] < $ln) {
                $active[] = $items[$nextItem++];
            }
            $containing = null;
            foreach ($active as $key => $item) {
                if ($item['last'] < $ln) {
                    if ($ended === null || [$item['last'], -$item['first'], $item['content']] > [$ended['last'], -$ended['first'], $ended['content']]) {
                        $ended = $item;
                    }
                    unset($active[$key]);
                } elseif ($containing === null || $item['content'] > $containing['content']) {
                    $containing = $item;
                }
            }
            $owner = $containing ?? $ended;
            [$view, $at] = self::quotedView($text, $owner['quotes'] ?? 0);
            $column = self::visual(substr($text, 0, $at));
            $run = isset($view[0]) && str_contains('|>', $view[0]) && preg_match(self::BLOCK, $view)
                ? [$view[0], $owner['first'] ?? null] : null;
            $blockStarts = $run !== null
                ? array_filter($blockRuns[$run[0]][$ln] ?? [], static fn (array $block): bool => $block[1] >= ($owner['content'] ?? 0)) : [];
            $blockRun = $blockStarts !== [] ? min(array_column($blockStarts, 0)) : null;
            $continuingRun = $run !== null && $run[0] === '|'
                && $column > ($owner['content'] ?? 0) && $run === $previousRun
                || $blockRun !== null && $blockRun < $ln;
            $previousRun = $run !== null && $run[0] === '|' && $column > ($owner['content'] ?? 0) ? $run : null;
            if ($openFence !== null) {
                if ($containing !== null && $containing['first'] === $openFence[0]) {
                    $run = strspn($view, $openFence[1]);
                    if ($run >= $openFence[2] && trim(substr($view, $run)) === '') {
                        $openFence = null;
                    }
                    $listLines[$ln] = true;

                    continue;
                }
                $openFence = null;
            }
            if (isset($listLines[$ln]) || $column === 0 || !preg_match(self::BLOCK, $view)) {
                continue;
            }
            if ($owner !== null && $owner['deepest'] > $owner['content'] && $owner['deepest'] === $column) {
                continue;
            }
            $fenceChar = isset($view[0]) && str_contains('`~:', $view[0]) && strspn($view, $view[0]) >= 3 ? $view[0] : null;
            if (isset($ignored[$ln]) && $fenceChar === null && !(str_starts_with($view, '>') && $blockRun === $ln)) {
                $listLines[$ln] = true;

                continue;
            }
            $adjacent = $ended;
            if ($adjacent !== null) {
                for ($i = $adjacent['last']; $i < $index; $i++) {
                    if (self::quotedView($rows[$i][0], $adjacent['quotes'])[0] !== '') {
                        $adjacent = null;

                        break;
                    }
                }
            }
            $candidate = $containing ?? $adjacent;
            $rule = null;
            if ($candidate !== null) {
                if ($column > $candidate['content'] && !$continuingRun) {
                    $rule = 'list-item-block-overindented';
                } elseif ($containing === null && $column > $candidate['base'] && $column < $candidate['content']) {
                    $rule = 'list-item-body-detached';
                }
            }
            if ($rule !== null) {
                preg_match('/^\S+/', $view, $token);
                $emit($ln, $at, strlen($token[0] ?? $view), $rule, "This block opener does not use the list item's content column " . $candidate['content'] . '. Align it with that column, or escape it to keep literal text.');
                $listLines[$ln] = true;
            }
            if ($candidate !== null && $fenceChar !== null && $column >= $candidate['content']) {
                $openFence = [$candidate['first'], $fenceChar, strspn($view, $fenceChar)];
            }
        }
        $fenceParser = new FencedBlockParser();
        foreach ($rows as $index => [$text, $start]) {
            $ln = $index + 1;
            if (isset($ignored[$ln])) {
                continue;
            }
            if (isset($headings[$ln]) && preg_match('/(?:^|\s)(\{\s*[.#][^{}]*\})\s*$/u', $text, $match, PREG_OFFSET_CAPTURE)) {
                $emit($ln, $match[1][1], strlen($match[1][0]), 'heading-trailing-attribute', "A heading's trailing attribute block is literal text. Move it to its own line above the heading.");
            }
            $view = ltrim(self::containerView($text), " \t");
            $at = strlen($text) - strlen($view);
            if (preg_match('/^([ \t]*)(`{3,}|~{3,})[ \t]*raw[ \t]+\S+/', $view, $match)) {
                $emit($ln, $at + strlen($match[1]), strlen($view) - strlen($match[1]), 'raw-block-syntax', 'Use a fence followed by =FORMAT for a raw block; raw FORMAT does not open one.');
            } elseif (isset($paragraphs[$ln]) && (!isset($inlineVerbatim[$ln]) || isset($ambiguousFenceEnds[$ln])) && preg_match('/^(`{3,}|~{3,})/', $view, $match)) {
                $run = $match[1];
                if (!str_contains(substr($view, strlen($run)), $run)) {
                    // A raw block's `=FORMAT` is a well-formed info string, not
                    // an invalid one: `parseCodeFenceOpener` refuses it because
                    // `parseRawBlockOpener` owns that spelling. Asking only the
                    // code-fence reader reported a column defect as an info
                    // string defect (carve-php#2764).
                    $wellFormed = $fenceParser->parseCodeFenceOpener($view) !== null
                        || $fenceParser->parseRawBlockOpener($view) !== null;
                    if (!$wellFormed) {
                        $emit($ln, $at, strlen($view), 'fence-opener-fallback', 'This fence has an invalid info string and parses as paragraph content. Use a language, optional quoted title, and optional label.');
                    } elseif ($at > 0 && strspn($text, " \t") > 0 && strspn(self::containerView($text), " \t") > 0 && !isset($listLines[$ln])) {
                        $emit($ln, $at, strlen($run), 'fence-delimiter-indentation', "This fence is indented past its container's content column and does not open a code block.");
                    }
                }
            }
            if (!isset($inlineVerbatim[$ln]) && str_starts_with($text, '>') && strlen($text) > 1 && !str_starts_with($text, '> ')) {
                $emit($ln, 0, strlen($text), 'blockquote-marker-without-space', 'A blockquote marker must be bare or followed by a space.');
            }
            preg_match_all('/\{\{([^{}]*)\}\}/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $inner = $match[1][0];
                $body = trim($inner, " \t");
                if ($body !== '' && !(strspn($inner, " \t") > 0 && str_contains('#@', $body[0]))) {
                    continue;
                }
                $from = $start + $match[0][1];
                $end = $from + strlen($match[0][0]);
                $lo = 0;
                $hi = count($textRuns);
                while ($lo < $hi) {
                    $mid = intdiv($lo + $hi, 2);
                    if ($textRuns[$mid][0] <= $from) {
                        $lo = $mid + 1;
                    } else {
                        $hi = $mid;
                    }
                }
                if ($lo > 0 && $textRuns[$lo - 1][1] >= $end) {
                    $emit($ln, $match[0][1], strlen($match[0][0]), 'empty-include-path', 'This include-shaped text has no path. Add a path or remove the braces.');
                }
            }
        }
        foreach ($starts as [$pos, $value]) {
            if (isset($listLines[$pos->startLine])) {
                continue;
            }
            $view = ltrim($value, " \t");
            if (!preg_match('/^(?::{3,}|\{[.#])/', $view) || !isset($rows[$pos->startLine - 1])) {
                continue;
            }
            [$text, $start] = $rows[$pos->startLine - 1];
            $at = max(0, SourceOffsets::toByte($pos->startOffset, $map, $length) - $start);
            $title = preg_match('/^:{3,}[ \t]+[A-Za-z_][\w-]*[ \t]+([^"\[ \t].*)$/', ltrim($text));
            $emit($pos->startLine, $at, $title ? strlen($view) : (str_starts_with($view, ':') ? strspn($view, ':') : 2), $title ? 'fence-title-syntax' : 'block-marker-as-text', $title ? 'A fence title must use straight double quotes; put attributes on a separate preceding line.' : 'This block-shaped marker parsed as text. Check its syntax and container indentation.');
        }
        usort($fences, static fn (array $a, array $b): int => ($a['first'] <=> $b['first']) ?: ($b['last'] <=> $a['last']));
        $closed = [];
        $claimed = [];
        foreach (array_reverse($fences, true) as $index => $fence) {
            for ($i = min($fence['last'], $rowCount) - 1; $i >= $fence['first']; $i--) {
                $view = ltrim(self::containerView($rows[$i][0]), " \t");
                if (trim($view) === '' || str_starts_with(trim($view), '^ ')) {
                    continue;
                }
                if (
                    rtrim($view) === str_repeat(':', $fence['width'])
                    && strlen($rows[$i][0]) - strlen($view) === $fence['column']
                    && !isset($claimed[$i])
                ) {
                    $closed[$index] = true;
                    $claimed[$i] = true;
                }

                break;
            }
        }
        foreach ($fences as $index => $fence) {
            $text = $rows[$fence['first'] - 1][0];
            $view = ltrim(self::containerView($fence['first'] === 1 ? preg_replace('/^\x{FEFF}/u', '', $text) ?? $text : $text), " \t");
            $opener = $fenceParser->parseDivFenceOpener($view);
            if ($opener !== null && $opener['invalidMetadata']) {
                if ($onRecoveredContainerMetadata !== null) {
                    $onRecoveredContainerMetadata();
                }
                $emit($fence['first'], strlen($text) - strlen($view), strlen($view), 'fence-title-syntax', 'Invalid container metadata was dropped. Use a straight-double-quoted title or a bracketed label; the container and its children are preserved.');
            }
            if ($fence['bare'] && !isset($closed[$index])) {
                for ($i = $index - 1; $i >= 0; $i--) {
                    $parent = $fences[$i];
                    if ($parent['first'] < $fence['first'] && $parent['last'] >= $fence['last'] && $parent['column'] === $fence['column']) {
                        if ($parent['width'] !== $fence['width']) {
                            $emit($fence['first'], $fence['column'], $fence['width'], 'colon-fence-length-mismatch', 'This bare fence has a different width from its enclosing fence.');
                        }

                        break;
                    }
                }
            }
            if (!isset($closed[$index])) {
                $emit($fence['first'], $fence['column'], $fence['width'], 'unclosed-container-fence', 'This colon-fenced container has no closer. Add a bare fence of the same width.');
            }
        }
        $declared = null;
        if (preg_match('/\A(?:\xEF\xBB\xBF)?---(?:yaml|toml|json)?[ \t]*(?:\r\n|\r|\n)(.*?)(?:\r\n|\r|\n)---[ \t]*(?:\r\n|\r|\n|$)/s', $source, $front, PREG_OFFSET_CAPTURE)) {
            if (preg_match('/^[ \t]*carve-version[ \t]*:[ \t]*(\S+)[ \t]*\r?$/m', $front[1][0], $version, PREG_OFFSET_CAPTURE)) {
                $value = $version[1][0];
                $offset = $front[1][1] + $version[1][1];
                // A YAML scalar may be quoted; the version is what is inside the quotes.
                if (preg_match('/^(["\'])(.*)\1$/', $value, $quoted)) {
                    $value = $quoted[2];
                    $offset++;
                }
                $declared = [$value, $offset];
            }
        }
        $stamp = Stamp::read($source);
        if ($declared === null && $stamp !== null) {
            $at = strrpos($source, $stamp['version']);
            $declared = [$stamp['version'], $at === false ? 0 : $at];
        }
        if ($declared !== null) {
            [$value, $at] = $declared;
            $parts = explode('.', $value);
            $current = explode('.', CarveConverter::SPEC_VERSION);
            $size = max(count($parts), count($current));
            if (!preg_match('/^\d+(?:\.\d+)*$/', $value) || version_compare(implode('.', array_pad($parts, $size, '0')), implode('.', array_pad($current, $size, '0')), '>')) {
                foreach ($rows as $index => [$text, $start]) {
                    if ($start <= $at && $at <= $start + strlen($text)) {
                        $emit($index + 1, $at - $start, strlen($value), 'carve-version-unsupported', 'The declared Carve version is newer than or unrecognized by this engine.');

                        break;
                    }
                }
            }
        }
        usort($warnings, static fn (LintWarning $a, LintWarning $b): int => $a->start <=> $b->start);

        return $warnings;
    }

    private static function visual(string $text): int
    {
        $column = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $column = $char === "\t" ? (intdiv($column, 4) + 1) * 4 : $column + 1;
        }

        return $column;
    }

    /**
     * @return array{string, int}
     */
    private static function quotedView(string $text, int $quotes): array
    {
        $rest = $text;
        for ($i = 0; $i < $quotes; $i++) {
            if (!preg_match('/^[ \t]*>(?: |$)/', $rest, $match)) {
                break;
            }
            $rest = substr($rest, strlen($match[0]));
        }
        $rest = ltrim($rest, " \t");

        return [$rest, strlen($text) - strlen($rest)];
    }

    private static function containerView(string $text): string
    {
        while (
            preg_match('/^[ \t]*> ?/', $text, $match)
            || preg_match(self::ITEM, $text, $match)
            || preg_match('/^[ \t]*(?:: |\[\^[^\]\r\n]+\]: +)/', $text, $match)
        ) {
            $text = substr($text, strlen($match[0]));
        }

        return $text;
    }
}
