<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\Utility\QuotedSlotEscaper;

trait NormalizesDjotStructure
{
    /**
     * @param string $source
     * @param int $depth
     */
    private function djotDestinationLines(string $source, int $depth): string
    {
        if (!str_contains($source, "\n")) {
            return $source;
        }
        $rawDestination = $source;
        $rawDestination = preg_replace_callback('/\\\\(?:\r?\n|[^\r\n])/', static fn (array $match): string => str_ends_with($match[0], "\n") ? "\n" : $match[0], $rawDestination) ?? $rawDestination;
        $destination = preg_replace_callback('/\n([ \t]*[^\n]*)/', static function (array $match) use ($depth): string {
            $rest = ltrim($match[1], " \t");
            for ($n = 0; $n < $depth && preg_match('/^>(?:[ \t]|$)/', $rest); $n++) {
                $rest = ltrim(substr($rest, 1), " \t");
            }

            return $rest;
        }, $rawDestination) ?? $rawDestination;

        return $destination;
    }

    private function normalizeDjotStructure(string $source): string
    {
        $lines = explode("\n", $source);
        $maskedSource = $this->maskCodeAndDestinations($source);
        $mask = explode("\n", $maskedSource);
        $divClosers = [];
        if (str_contains($source, ':::')) {
            $this->djotInlineBoundaries($source, $maskedSource, true, $divClosers);
        }
        $lineOffsets = [];
        $lineOffset = 0;
        foreach ($lines as $line) {
            $lineOffsets[] = $lineOffset;
            $lineOffset += strlen($line) + 1;
        }
        $fences = explode("\n", $this->maskDjotFences($source));
        $rows = $this->djotTableRows($source, $this->maskCodeAndDestinations($source, false));
        $divs = $lists = $out = [];
        $paragraph = false;
        $blank = true;
        $depth = 0;
        $headingMarker = '';
        $definitionIndent = -1;
        $table = false;
        $fenceParser = new FencedBlockParser();
        foreach ($lines as $n => $original) {
            $masked = $mask[$n];
            preg_match('/^(?:[ \t]*>[ ]?)*/', $original, $quoteMatch);
            $quote = $quoteMatch[0] ?? '';
            $quoteDepth = substr_count($quote, '>');
            $raw = substr($original, strlen($quote));
            $view = substr($masked, strlen($quote));
            $indent = strspn($raw, " \t");
            $text = substr($raw, $indent);
            $visible = substr($view, $indent);
            if ($text === '') {
                while ($divs !== [] && $quoteDepth < $divs[array_key_last($divs)]['depth']) {
                    array_pop($divs);
                }
                $out[] = $original;
                $paragraph = false;
                $headingMarker = '';
                $blank = true;
                $table = false;

                continue;
            }
            if (preg_match('/^:[ \t]+\S/', $visible) === 1 && ($blank || $definitionIndent >= 0)) {
                $definitionIndent = $indent;
            } elseif ($definitionIndent >= 0 && $blank && $indent <= $definitionIndent) {
                $definitionIndent = -1;
            }
            if ($definitionIndent >= 0) {
                $out[] = $original;
                $headingMarker = '';
                $paragraph = true;
                $blank = false;

                continue;
            }
            $blockIndent = $quoteDepth > $depth ? strspn($original, " \t") : $indent;
            $blockStart = $quoteDepth > $depth || $rows[$n] || $fences[$n] !== $original
                || preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|\|)/', $visible) === 1
                || preg_match('/^(?:[*-][ \t]*){3,}$/', $visible) === 1;
            $item = preg_match('/^(?:([-*+])|((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)))[ \t]+\S/', $visible, $itemMatch) === 1;
            if ($quoteDepth < $depth && ($blockStart || $item)) {
                $paragraph = false;
                $headingMarker = '';
                $lists = [];
                $depth = $quoteDepth;
            }
            while ($divs !== [] && isset($divs[array_key_last($divs)]['itemColumn'])) {
                $owned = $divs[array_key_last($divs)];
                if ($quoteDepth >= $owned['depth'] && !($quoteDepth === $owned['depth'] && $indent < $owned['itemColumn'] && ($item || $blockStart || $blank))) {
                    break;
                }
                array_pop($divs);
                $before = count($out);
                while ($before > 0 && trim($out[$before - 1]) === '') {
                    $before--;
                }
                array_splice($out, $before, 0, [$owned['prefix'] . str_repeat(':', $owned['width'])]);
                $paragraph = false;
            }
            if ($blockStart) {
                $listCount = count($lists);
                while ($lists !== [] && ($blockIndent <= $lists[array_key_last($lists)]->column || ($listCount > 1 && $blockIndent < $lists[array_key_last($lists)]->column + $lists[array_key_last($lists)]->content))) {
                    array_pop($lists);
                    $listCount--;
                    $paragraph = false;
                    $headingMarker = '';
                    $parentKey = array_key_last($lists);
                    if ($parentKey !== null) {
                        $lists[$parentKey]->loose = true;
                    }
                }
                if ($blank) {
                    foreach ($lists as $list) {
                        if ($blockIndent > $list->column) {
                            $list->loose = true;
                        }
                    }
                }
            }
            $top = $lists === [] ? null : $lists[array_key_last($lists)];
            if ($quoteDepth > $depth && $top !== null && $blockIndent >= $top->column + $top->content && !$paragraph) {
                $out[] = $original;
                $blank = false;

                continue;
            }
            if ($fences[$n] !== $original || $rows[$n]) {
                if (!$rows[$n] || ($rows[$n - 1] ?? false) || !($rows[$n + 1] ?? false) || preg_match('/^\|(?:[ \t]*-+[ \t]*\|)+[ \t]*$/', $original) !== 1) {
                    $out[] = $original;
                }
                $paragraph = false;
                $headingMarker = '';
                $blank = false;
                $table = $rows[$n];

                continue;
            }
            if ($table && preg_match('/^\+[ \t].*\|/', $text) === 1) {
                $out[] = $original;

                continue;
            }
            $table = false;
            if (!$item && (preg_match('/\x00DJOTNOTEATTR\x00\d+\x00/', $text) === 1 || trim($view) === '' && str_starts_with($text, '{%'))) {
                $out[] = $original;
                $headingMarker = '';
                $depth = $quoteDepth;
                $blank = !$paragraph;

                continue;
            }
            if (trim($view) === '') {
                $out[] = $original;
                $paragraph = $headingMarker === '' && preg_match('/^(?:(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+)?\[[^\]\n]+\]:/', $text) !== 1;
                $blank = false;

                continue;
            }
            if ($paragraph && $quoteDepth > $depth) {
                $held = '';
                $rest = $original;
                for ($level = 0; $level < $depth; $level++) {
                    if (preg_match('/^[ \t]*>[ ]?/', $rest, $marker) !== 1) {
                        break;
                    }
                    $held .= $marker[0];
                    $rest = substr($rest, strlen($marker[0]));
                }
                $prefix = substr($rest, 0, strspn($rest, " \t"));
                $out[] = $held . $prefix . '\\' . substr($rest, strlen($prefix));
                $blank = false;

                continue;
            }
            if ($quoteDepth !== $depth && !$paragraph) {
                $lists = [];
                $depth = $quoteDepth;
            }
            $div = preg_match('/^(:{3,})(?:[ \t]+.*)?$/', $visible, $divMatch) === 1;
            $topDiv = $divs === [] ? null : $divs[array_key_last($divs)];
            $closeStarts = $divClosers[$lineOffsets[$n]] ?? null;
            if ($div && $topDiv !== null && $closeStarts !== null && $closeStarts !== []) {
                $this->dropOrphanDjotAttributeLine($out);
                $matched = 0;
                $outerStart = $closeStarts[array_key_last($closeStarts)];
                while ($divs !== [] && $divs[array_key_last($divs)]['start'] >= $outerStart) {
                    $closed = array_pop($divs);
                    if ($closed['start'] === $closeStarts[$matched]) {
                        $out[] = $closed['prefix'] . str_repeat(':', $closed['width']);
                        $matched++;
                    }
                }
                $paragraph = false;
                $headingMarker = '';
                $blank = true;

                continue;
            }
            if ($div && preg_match('/^:{3,}[ \t]*$/', $text) === 1 && $topDiv !== null && (strlen($divMatch[1]) >= $topDiv['width'] || $paragraph)) {
                if (strlen($divMatch[1]) >= $topDiv['width']) {
                    $width = strlen($divMatch[1]);
                    $this->dropOrphanDjotAttributeLine($out);
                    while ($divs !== [] && $width >= $divs[array_key_last($divs)]['width']) {
                        $closed = array_pop($divs);
                        $out[] = $closed['prefix'] . str_repeat(':', $closed['width']);
                        if ($closed['invalid'] || ($divs !== [] && $divs[array_key_last($divs)]['invalid'])) {
                            break;
                        }
                    }
                    $paragraph = false;
                    $headingMarker = '';
                    $blank = true;
                } else {
                    $out[] = $quote . str_repeat(' ', $indent) . '\\' . $text;
                    $paragraph = true;
                    $blank = false;
                }

                continue;
            }
            $rule = preg_match('/^(?:[*-][ \t]*){3,}$/', $visible) === 1;
            if ($item && !$rule) {
                $previousList = $lists === [] ? null : $lists[array_key_last($lists)];
                $endsNestedList = $previousList !== null && $indent < $previousList->column;
                while ($lists !== [] && $indent < $lists[array_key_last($lists)]->column) {
                    array_pop($lists);
                }
                $parent = $lists === [] ? null : $lists[array_key_last($lists)];
                if ($parent !== null && $blank && $indent === $parent->column && $endsNestedList && !$parent->loose) {
                    while ($out !== [] && trim($out[array_key_last($out)]) === '') {
                        array_pop($out);
                    }
                    if (!$parent->continuation && trim($out[$previousList->start - 1] ?? "\0") === '') {
                        array_splice($out, $previousList->start - 1, 1);
                    }
                } elseif ($parent !== null && $blank && $indent === $parent->column) {
                    $parent->loose = true;
                }
                if (($parent === null || $indent > $parent->column) && $paragraph && !$blank) {
                    $target = $parent !== null ? $parent->target + $parent->content : $indent;
                    $literal = $itemMatch[1] !== '' ? '\\' . $text : ($parent !== null ? (preg_replace('/[.)]/', '\\\\$0', $text, 1) ?? $text) : $text);
                    $out[] = $quote . str_repeat(' ', $target) . $literal;
                    if ($parent !== null) {
                        $parent->continuation = true;
                    }
                    $blank = false;

                    continue;
                }
                $kind = $itemMatch[1] !== '' ? $itemMatch[1] : (preg_replace('/[0-9A-Za-z]+/', '1', $itemMatch[2]) ?? $itemMatch[2]);
                $nested = $parent !== null && $indent > $parent->column;
                if ($parent === null || $nested) {
                    $lists[] = new DjotListContext(
                        $indent,
                        strlen($itemMatch[0]) - 1,
                        $nested ? $parent->target + $parent->content : 0,
                        $kind,
                        $itemMatch[1] === '*' ? '*' : '-',
                        count($out),
                    );
                } elseif ($kind !== $parent->kind) {
                    if (trim($out !== [] ? $out[array_key_last($out)] : '') !== '') {
                        $out[] = rtrim($quote);
                    }
                    $parent->kind = $kind;
                    $parent->bullet = $parent->bullet === '-' ? '*' : '-';
                }
                $contextKey = array_key_last($lists);
                $context = $lists[$contextKey];
                if ($parent !== null && !$nested && $parent->loose && $parent->continuation && trim($out !== [] ? $out[array_key_last($out)] : '') !== '') {
                    $out[] = rtrim($quote);
                }
                $written = $itemMatch[1] !== '' ? $context->bullet . substr($text, 1) : $text;
                $context->continuation = false;
                $headingMarker = '';
                $out[] = $quote . str_repeat(' ', $context->target) . $written;
                $body = substr($text, strlen($itemMatch[0]) - 1);
                $itemHeading = preg_match('/^(#{1,6})(?:[ \t]+|$)/', $body, $itemHeadingMatch) === 1;
                $headingMarker = $itemHeadingMatch[1] ?? '';
                $itemDiv = preg_match('/^(:{3,})(?:[ \t]+.*)?$/', $body, $itemDivMatch) === 1;
                if ($itemDiv) {
                    $divs[] = ['width' => strlen($itemDivMatch[1]), 'prefix' => $quote . str_repeat(' ', $context->target + $context->content), 'invalid' => $fenceParser->parseDivFenceOpener($body)['invalidMetadata'] ?? false, 'itemColumn' => $context->column + $context->content, 'depth' => $quoteDepth, 'start' => $lineOffsets[$n]];
                }
                $itemQuote = preg_match('/^(?:>[ \t]*)+/', $body, $itemQuoteMatch) === 1;
                if ($itemQuote) {
                    $depth = $quoteDepth + substr_count($itemQuoteMatch[0], '>');
                }
                $paragraph = !$itemDiv && !$itemHeading && !str_contains($body, "\x00DJOTNOTEATTR\x00");
                $blank = $itemDiv;

                continue;
            }
            if ($div && !$paragraph) {
                $parent = $lists === [] ? null : $lists[array_key_last($lists)];
                $owned = $parent !== null && $indent >= $parent->column + $parent->content;
                $divs[] = ['width' => strlen($divMatch[1]), 'prefix' => $quote . str_repeat(' ', $indent), 'invalid' => $fenceParser->parseDivFenceOpener($text)['invalidMetadata'] ?? false, 'depth' => $quoteDepth, 'start' => $lineOffsets[$n]] + ($owned ? ['itemColumn' => $parent->column + $parent->content] : []);
                $out[] = $original;
                $paragraph = false;
                $headingMarker = '';
                $blank = true;

                continue;
            }
            $dedent = $lists !== [] ? min($indent, $lists[0]->column - $lists[0]->target) : 0;
            $attrs = str_starts_with($visible, '{') ? $this->readDjotWordAttributes($raw, $indent) : null;
            $attributeLine = $attrs !== null && $attrs['end'] === strlen($raw);
            if ($blank && !$this->djotListContains($lists, $indent) && !$attributeLine) {
                $lists = [];
            }
            $heading = preg_match('/^(#{1,6})(?:[ \t]+|$)/', $visible, $headingMatch) === 1;
            $reference = preg_match('/^\[[^\]\n]*\]:/', $visible) === 1;
            $block = ($heading && $headingMatch[1] !== $headingMarker) || $div || ($rule && (!str_contains($visible, '*') || !str_contains($visible, '-'))) || str_starts_with($visible, '>') || str_starts_with($visible, '|');
            if (($block && (!$heading || $headingMatch[1] !== $headingMarker)) || $attributeLine || $reference) {
                $headingMarker = '';
            }
            if ($blank && !$block && !$attributeLine && !$reference) {
                foreach ($lists as $list) {
                    if ($indent > $list->column) {
                        $list->loose = true;
                    }
                }
            }
            if ($paragraph && $block) {
                $out[] = $quote . str_repeat(' ', $indent) . '\\' . $text;
                $blank = false;

                continue;
            }
            if (!$paragraph && preg_match('/^\|`+\|$/', $text) === 1) {
                $leadingBlanks = 0;
                while (($out[0] ?? null) === '') {
                    array_shift($out);
                    $leadingBlanks++;
                }
                if ($leadingBlanks > 0) {
                    foreach ($lists as $list) {
                        $list->start = max(0, $list->start - $leadingBlanks);
                    }
                }
                $out[] = $quote . str_repeat(' ', $indent) . '\\' . $text;
                $paragraph = true;
                $blank = false;

                continue;
            }
            if ($heading) {
                $headingMarker = $headingMatch[1];
            }
            if ($heading && $lists === []) {
                $body = substr($text, strlen($headingMatch[1]));
                // Carve whitespace is space and tab only, so a vertical tab is heading content.
                $out[] = $quote . $headingMatch[1] . (trim($body, " \t") !== '' ? ' ' . ltrim($body, " \t") : '');
            } elseif ($rule && $lists === [] && !$paragraph) {
                $out[] = $quote . '***';
            } else {
                $parent = $lists === [] ? null : $lists[array_key_last($lists)];
                $out[] = $parent !== null && $indent > $parent->column
                    ? $quote . str_repeat(' ', $parent->target + $parent->content + max(0, $indent - $parent->column - $parent->content)) . $text
                    : ($dedent > 0 ? $quote . substr($raw, $dedent) : $original);
            }
            $fence = preg_match('/^[`~]{3,}/', $text) === 1 && trim($visible) === '';
            $paragraph = !($heading || $headingMarker !== '' || $rule || $attributeLine || $reference || $fence || $text === '+' || preg_match('/^:[ \t]/', $visible) === 1 || $quoteDepth > $depth);
            if (!$paragraph && $quoteDepth > $depth) {
                $depth = $quoteDepth;
            }
            if ($blank && !$item && !$this->djotListContains($lists, $indent) && !$attributeLine) {
                $lists = [];
            }
            $blank = false;
        }
        $newline = ($out !== [] ? $out[array_key_last($out)] : null) === '';
        if ($divs !== [] && $newline) {
            array_pop($out);
        }
        if ($divs !== []) {
            $this->dropOrphanDjotAttributeLine($out);
        }
        while ($divs !== []) {
            $div = array_pop($divs);
            if (!str_contains($div['prefix'], '>')) {
                $out[] = $div['prefix'] . str_repeat(':', $div['width']);
            }
        }
        if ($newline && ($out !== [] ? $out[array_key_last($out)] : null) !== '') {
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * Djot attaches an attribute line with no block after it to nothing, so drop it.
     *
     * @param list<string> $out
     */
    private function dropOrphanDjotAttributeLine(array &$out): void
    {
        if ($out === []) {
            return;
        }
        $last = rtrim($out[array_key_last($out)]);
        $at = strspn($last, " \t");
        if (($last[$at] ?? '') !== '{') {
            return;
        }
        $attrs = $this->readDjotWordAttributes($last, $at);
        if ($attrs !== null && $attrs['end'] === strlen($last)) {
            array_pop($out);
        }
    }

    /**
     * @param list<\MarkupCarve\Carve\Converter\DjotListContext> $lists
     * @param int $indent
     */
    private function djotListContains(array $lists, int $indent): bool
    {
        foreach ($lists as $list) {
            if ($indent > $list->column) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDjotInlineSpellings(string $source): string
    {
        $comments = [];
        $this->maskCodeAndDestinations($source, opaqueOptions: [

            'onComment' => static function (int $start, int $end) use (&$comments): void {
                $comments[$start] = $end;
            },
        ]);
        foreach (array_reverse($comments, true) as $start => $end) {
            if ($source[$end - 2] !== '%' && str_contains(substr($source, $start + 2, $end - $start - 2), '{')) {
                $source = substr($source, 0, $start) . '{%%}' . substr($source, $end);
            }
        }
        $mask = $this->djotEmphasisMask($source);
        $source = preg_replace_callback('/(?:\{\})+|[ \t]+\\\\[ \t]*(?=\n)|\{[\'\"]|[\'\"]\}/', function (array $match) use ($mask, $source): string {
            [$text, $at] = $match[0];
            if ($mask[$at] !== $source[$at] || trim(substr($mask, $at, strlen($text))) === '' || ($this->isDjotEscaped($source, $at) && !str_contains($text, '\\'))) {
                return $text;
            }
            if (str_contains($text, '\\')) {
                $start = strrpos(substr($source, 0, $at), "\n");
                $start = $start === false ? 0 : $start + 1;
                if (preg_match('/^(?:[ \t]*>[ ]?)*[ \t]*:{3,}$/', substr($source, $start, $at - $start)) === 1) {
                    return $text;
                }
                $end = $at + strcspn($text, '\\');
                while ($end > $at && !$this->isDjotEscaped($source, $end - 1)) {
                    $end--;
                }

                return substr($text, 0, $end - $at) . '\\';
            }
            if (str_starts_with($text, '{}')) {
                return $text;
            }

            return match ($text) {
                "{'" => '‘',
                "'}" => '’',
                '{"' => '“',
                '"}' => '”',
                default => '\\',
            };
        }, $source, -1, $count, PREG_OFFSET_CAPTURE) ?? $source;

        return $source;
    }

    private function padDjotCodeSpans(string $source): string
    {
        $mask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['code' => false], unclosedCode: false);
        preg_match_all('/`+/', $mask, $matches, PREG_OFFSET_CAPTURE);
        $next = $closers = [];
        for ($n = count($matches[0]) - 1; $n >= 0; $n--) {
            [$ticks, $at] = $matches[0][$n];
            $width = strlen($ticks);
            $closers[$at] = $next[$width] ?? null;
            if ($width > 1) {
                $closers[$at + 1] = $next[$width - 1] ?? null;
            }
            $next[$width] = $at;
        }
        preg_match_all('/\n[ \t]*(?:>[ \t]*)*\n/', $source, $boundaries, PREG_OFFSET_CAPTURE);
        $breaks = array_column($boundaries[0], 1);
        if (str_contains($source, "\n")) {
            foreach ($this->djotInlineBoundaries($source, $mask, true) as $boundary) {
                if ($boundary > 0 && $boundary < strlen($source) && $source[$boundary - 1] === "\n" && $source[$boundary] !== '|') {
                    $breaks[] = $boundary - 1;
                }
            }
            sort($breaks, SORT_NUMERIC);
        }
        $paragraph = 0;
        $sourceLength = strlen($source);
        $out = '';
        $copied = 0;
        $skip = 0;
        foreach ($matches[0] as [$ticks, $at]) {
            if ($at < $skip) {
                continue;
            }
            $width = strlen($ticks);
            if ($this->isDjotEscaped($source, $at)) {
                $at++;
                $width--;
            }
            if ($width === 0 || ($mask[$at] ?? '') !== '`') {
                continue;
            }
            while (($breaks[$paragraph] ?? $sourceLength) <= $at) {
                $paragraph++;
            }
            $paragraphEnd = $breaks[$paragraph] ?? $sourceLength;
            $close = $closers[$at] ?? null;
            if ($close === null || $close + $width > $paragraphEnd) {
                $skip = $paragraphEnd;

                continue;
            }
            $ticks = str_repeat('`', $width);
            $body = substr($source, $at + $width, $close - $at - $width);
            $skip = $close + $width;
            if (str_starts_with($body, ' ') && str_ends_with($body, ' ') && trim($body) !== '') {
                $before = str_starts_with($body, ' `') ? '' : ' ';
                $after = str_ends_with($body, '` ') ? '' : ' ';
                $out .= substr($source, $copied, $at - $copied) . $ticks . $before . $body . $after . $ticks;
                $copied = $skip;
            }
        }

        return $out . substr($source, $copied);
    }

    /**
     * @param list<string> $parts
     *
     * @return array<string, string>
     */
    private function djotReferenceAttributes(array $parts): array
    {
        $slots = [];
        foreach ($parts as $part) {
            foreach (AttributeParser::parse($part) as $key => $value) {
                $value = is_array($value) ? implode(' ', $value) : $value;
                if ($part[0] === '.') {
                    if (isset($slots[$key])) {
                        $slots[$key] .= ' ' . $value;
                    } else {
                        $slots[$key] = $value;
                    }
                } else {
                    $slots[$key] = $value;
                }
            }
        }

        return $slots;
    }

    private function normalizeDjotReferenceUses(string $source): string
    {
        $mask = $this->maskCodeAndDestinations($source);
        $definitions = [];
        $removed = [];
        $lines = explode("\n", $source);
        $offset = 0;
        foreach ($lines as $n => $line) {
            if (preg_match('/^\[([^\]\n]+)\]:[ \t]*(\S*)[ \t]*$/', $line, $definition) === 1 && ($mask[$offset] ?? '') === '[' && ($n === 0 || trim($lines[$n - 1]) === '' || str_starts_with($lines[$n - 1], '{') || preg_match('/^\[[^\]]*\]:/', $lines[$n - 1]) === 1)) {
                $attrs = [];
                $attributeParts = [];
                for ($k = $n - 1; $k >= 0; $k--) {
                    $parsed = $this->readDjotWordAttributes($lines[$k], 0);
                    if ($parsed === null || $parsed['end'] !== strlen($lines[$k])) {
                        break;
                    }
                    $attributeParts[] = $parsed['parts'];
                    if ($parsed['parts'] !== []) {
                        $removed[$k] = true;
                    }
                }
                foreach (array_reverse($attributeParts) as $part) {
                    foreach ($part as $value) {
                        $attrs[] = $value;
                    }
                }
                $attrs = $this->djotReferenceAttributes($attrs);
                $definitions[$definition[1]] = ['url' => $definition[2], 'attrs' => $attrs];
            }
            $offset += strlen($line) + 1;
        }
        if ($definitions === []) {
            return $source;
        }
        foreach ($removed as $n => $unused) {
            $lines[$n] = '';
        }
        $source = implode("\n", $lines);
        $mask = $this->maskCodeAndDestinations($source);
        $inlined = [];
        $retained = [];
        $matches = [];
        $length = strlen($source);
        $escaped = str_repeat("\0", $length);
        $closers = [];
        $brackets = [];
        $labelClosers = [];
        $slashes = 0;
        for ($at = 0; $at < $length; $at++) {
            $escaped[$at] = $slashes % 2 === 0 ? "\0" : "\1";
            $slashes = $source[$at] === '\\' ? $slashes + 1 : 0;
            if ($mask[$at] !== $source[$at] || $escaped[$at] !== "\0") {
                continue;
            }
            if ($source[$at] === '[') {
                $brackets[] = $at;
            } elseif ($source[$at] === ']' && $brackets !== []) {
                $closers[array_pop($brackets)] = $at;
                if (($source[$at + 1] ?? '') === '[') {
                    $labelClosers[$at + 2] = -1;
                }
            }
        }
        $next = -1;
        for ($at = $length - 1; $at >= 0; $at--) {
            if ($source[$at] === ']' && $escaped[$at] === "\0") {
                $next = $at;
            }
            if (isset($labelClosers[$at])) {
                $labelClosers[$at] = $next;
            }
        }
        for ($open = 0; $open < $length; $open++) {
            $close = $closers[$open] ?? null;
            if ($close === null || ($source[$close + 1] ?? '') !== '[') {
                continue;
            }
            $labelEnd = $labelClosers[$close + 2] ?? -1;
            if ($labelEnd < 0) {
                continue;
            }
            $at = $open > 0 && $source[$open - 1] === '!' && $escaped[$open - 1] === "\0" ? $open - 1 : $open;
            $end = $labelEnd + 1;
            $own = '';
            while (($source[$end] ?? '') === '{') {
                $attrs = $this->readDjotWordAttributes($source, $end);
                if ($attrs === null) {
                    break;
                }
                $own .= substr($source, $end, $attrs['end'] - $end);
                $end = $attrs['end'];
            }
            $matches[] = [
                [substr($source, $at, $end - $at), $at],
                [substr($source, $at, $close + 1 - $at), $at],
                [substr($source, $open + 1, $close - $open - 1), $open + 1],
                [substr($source, $close + 2, $labelEnd - $close - 2), $close + 2],
                [$own, $labelEnd + 1],
            ];
            $open = $end - 1;
        }
        $tableAt = [];
        $lineOffset = 0;
        $matchIndex = 0;
        foreach (explode("\n", $source) as $line) {
            $lineEnd = $lineOffset + strlen($line);
            while (isset($matches[$matchIndex]) && $matches[$matchIndex][0][1] <= $lineEnd) {
                $tableAt[$matches[$matchIndex][0][1]] = str_starts_with(ltrim($line), '|');
                $matchIndex++;
            }
            $lineOffset = $lineEnd + 1;
        }
        $rewrite = function (array $match) use ($definitions, $mask, $source, $tableAt, &$inlined, &$retained): string {
            [$text, $at] = $match[0];
            if (($mask[$at] ?? '') === ' ' || $this->isDjotEscaped($source, $at)) {
                return $text;
            }
            $label = $match[3][0] === '' ? $match[2][0] : $match[3][0];
            $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
            $formatted = $match[3][0] === '' && preg_match('/[_*`{~^]/', $label) === 1;
            if ($formatted) {
                $label = rtrim((new CarveConverter(smartTypography: false, renderer: new PlainTextRenderer()))->convert($this->convert($label)), "\n");
            }
            $key = preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $label) ?? $label;
            $definition = $definitions[$key] ?? null;
            if ($definition === null) {
                return $text;
            }
            if ($definition['url'] !== '' && ($formatted || $definition['attrs'] !== [])) {
                $inlined[$key] = true;
                $attrs = '';
                $ownSource = $match[4][0] ?? '';
                $ownParts = [];
                for ($start = 0, $ownLength = strlen($ownSource); $start < $ownLength;) {
                    $parsed = $this->readDjotWordAttributes($ownSource, $start);
                    if ($parsed === null) {
                        break;
                    }
                    foreach ($parsed['parts'] as $part) {
                        $ownParts[] = $part;
                    }
                    $start = $parsed['end'];
                }
                $own = $this->djotReferenceAttributes($ownParts);
                foreach (array_replace($definition['attrs'], $own) as $key => $value) {
                    $quoted = preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1;
                    $value = QuotedSlotEscaper::escape($value);
                    if ($tableAt[$at] ?? false) {
                        $value = str_replace('|', '\\|', $value);
                    }
                    $attrs .= ($attrs === '' ? '' : ' ') . $key . '=' . ($quoted ? '"' . $value . '"' : $value);
                }

                return $match[1][0] . '(' . $definition['url'] . ')' . ($attrs === '' ? '' : '{' . $attrs . '}');
            }

            $retained[$key] = true;

            return $match[1][0] . '[' . ($match[3][0] === '' ? '' : $label) . ']' . ($match[4][0] ?? '');
        };
        $output = '';
        $copied = 0;
        foreach ($matches as $match) {
            $output .= substr($source, $copied, $match[0][1] - $copied) . $rewrite($match);
            $copied = $match[0][1] + strlen($match[0][0]);
        }
        $source = $output . substr($source, $copied);

        if ($inlined !== []) {
            $lines = explode("\n", $source);
            $mask = $this->maskCodeAndDestinations($source);
            $offset = 0;
            $removed = [];
            $firstKept = 0;
            foreach ($lines as $n => $line) {
                $visible = $mask[$offset] ?? '';
                $offset += strlen($line) + 1;
                if ($visible !== '[' || preg_match('/^\[([^\]\n]+)\]:[ \t]*(\S*)[ \t]*$/', $line, $definition) !== 1 || !isset($inlined[$definition[1]]) || isset($retained[$definition[1]])) {
                    continue;
                }
                $removed[$n] = true;
                for ($k = $n - 1; $k >= 0 && $lines[$k] === ''; $k--) {
                    $removed[$k] = true;
                }
                while (isset($removed[$firstKept])) {
                    $firstKept++;
                }
                if ($firstKept > $n && ($lines[$n + 1] ?? null) === '' && isset($lines[$n + 2])) {
                    $removed[$n + 1] = true;
                }
            }
            $source = implode("\n", array_filter($lines, static fn (int $n): bool => !isset($removed[$n]), ARRAY_FILTER_USE_KEY));
        }

        return $source;
    }
}
