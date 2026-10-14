<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\NonBreakingSpace;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;

trait ReportsDjotLosses
{
    /**
     * @param string $text
     * @param int $depth
     * @param \Closure(string, int): string $childText
     */
    private function djotImageTrailingWhitespaceLoss(string $text, int $depth, Closure $childText): bool
    {
        $label = $this->djotImageLabelAttributes($this->projectDjotReferenceText($text, $depth), true);
        if (preg_match('/([ \t]+)((?:(?:\{%%\}|\[\]\{\}|\{\}|[\]}`_*~^+=-])[ \t]*)*)(\n)/', $label) !== 1) {
            return false;
        }
        preg_match_all('/DJOTTRAILSPACE([0-9]+)Z/', (preg_replace('/[^A-Za-z0-9]/', '', $label) ?? $label) . '\n' . $childText($label, 0), $matches);
        $reserved = array_fill_keys($matches[1], true);
        $serial = 0;
        while (isset($reserved[$serial])) {
            $serial++;
        }
        $marker = 'DJOTTRAILSPACE' . $serial . 'Z';
        $mask = $this->maskCodeAndDestinations($label);
        $probe = preg_replace_callback('/([ \t]+)((?:(?:\{%%\}|\[\]\{\}|\{\}|[\]}`_*~^+=-])[ \t]*)*)(\n)/', function (array $match) use ($label, $mask, $marker): string {
            $at = $match[0][1];
            if ($at > 0 && ($mask[$at - 1] ?? '') === '\\' && !$this->isDjotEscaped($label, $at - 1)) {
                return $match[0][0];
            }

            return $match[1][0] . $marker . $match[2][0] . $match[3][0];
        }, $label, flags: PREG_OFFSET_CAPTURE) ?? $label;
        $child = str_replace($marker, '', $childText($probe, 0));

        $original = $childText($label, 0);
        if (preg_replace('/[ \t]+\n/', "\n", $child) !== preg_replace('/[ \t]+\n/', "\n", $original)) {
            return preg_match('/[ \t]+\n/', $original) === 1;
        }

        return preg_match('/[ \t]+\n/', $child) === 1;
    }

    /**
     * Assess the original input so paths survive folding and inserted lines.
     *
     * @param string $source
     * @param bool $definitionsOnly
     * @param array<string, string> $referenceDefinitions
     *
     * @return list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    private function djotLosses(string $source, bool $definitionsOnly = false, array &$referenceDefinitions = []): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $keyCache = [];
        $childText = function (string $label, int $depth = 0) use (&$keyCache): string {
            if (strlen($label) > 256) {
                return $this->djotCollapsedReferenceText($label, $depth);
            }
            $key = $depth . ':' . $label;

            return $keyCache[$key] ??= $this->djotCollapsedReferenceText($label, $depth);
        };
        $imageChildText = function (string $label, int $depth) use (&$keyCache): string {
            $key = 'image:' . $depth . ':' . $label;
            $projected = $this->djotImageLabelAttributes($this->projectDjotReferenceText($label, $depth), true);
            if (strlen($label) > 256) {
                return $this->djotCollapsedReferenceText($projected);
            }

            return $keyCache[$key] ??= $this->djotCollapsedReferenceText($projected);
        };
        $rawFormatMessage = 'A Djot raw-format identifier containing U+0085 cannot be spelled in Carve; its raw-output behavior is lost.';
        $rawFormats = $rawFormatIgnored = $rawFenceLines = [];
        $maskSource = $source;
        if (str_contains($source, "\u{0085}")) {
            $maskLines = explode("\n", $source);
            $maskOffset = 0;
            foreach ($maskLines as $n => &$line) {
                $lineStart = $maskOffset;
                $maskOffset += strlen($line) + 1;
                $line = preg_replace_callback('/(`{3,}|~{3,})([ \t]*=)([^\x09-\x0d\x20\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}{}%"\'`~\\\\]+)([ \t]*)$/u', static function (array $match) use ($n, $lineStart, &$rawFenceLines): string {
                    if (!str_contains($match[3], "\u{0085}")) {
                        return $match[0];
                    }
                    $rawFenceLines[$n] = $lineStart;

                    return $match[1] . $match[2] . (preg_replace('/[^A-Za-z0-9_+#.-]/', 'x', $match[3]) ?? $match[3]) . $match[4];
                }, $line) ?? $line;
            }
            unset($line);
            $maskSource = implode("\n", $maskLines);
        }
        $accepted = [];
        $this->foldDjotReferences($source, $accepted);
        $mask = $this->djotEmphasisMask($maskSource);
        $findings = [];
        $add = static function (int $at, string $message) use (&$findings): void {
            $findings[] = [$at, $message];
        };
        if ($this->hasDjotCodeLayoutCandidates($source)) {
            $this->closeDjotTableCode($source, static function (int $at) use ($add): void {
                $add($at, 'Multiline inline code uses raw HTML; plain-text export omits it.');
            });
        }
        DjotEmphasis::convert($source, $mask, static fn (string $text): string => $text, [], static function (int $at) use ($add): void {
            $add($at, 'Emphasis exceeding the native nesting budget is flattened; its text is preserved.');
        }, $this->djotTableCellBoundaries($source));
        $lines = explode("\n", $source);
        $lineCount = count($lines);
        $destinations = [];
        $linkMask = $this->maskCodeAndDestinations($maskSource, false, opaqueOptions: [
            'onFenceOpen' => static function (int $n) use ($rawFenceLines, $add, $rawFormatMessage): void {
                if (isset($rawFenceLines[$n])) {
                    $add($rawFenceLines[$n], $rawFormatMessage);
                }
            },
            'onRawFormat' => function (int $start, int $end, string $format) use ($source, &$rawFormats): void {
                if (str_contains($format, "\u{0085}") && !(($source[$start - 1] ?? '') === '$' && !$this->isDjotEscaped($source, $start - 1))) {
                    $rawFormats[] = [$start, $end];
                }
            },
            'destinations' => true,
            'onDestination' => static function (int $start, int $end) use (&$destinations): void {
                $destinations[$start] = $end;
            },
        ]);
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            if ($linkMask[$at] !== '{' || $this->isDjotEscaped($source, $at)) {
                continue;
            }
            $attrs = $this->readDjotWordAttributes($source, $at);
            if ($attrs === null) {
                continue;
            }
            for ($k = $at; $k < $attrs['end']; $k++) {
                if ($linkMask[$k] !== "\n") {
                    $linkMask[$k] = ' ';
                }
            }
            $at = $attrs['end'] - 1;
        }
        $visible = explode("\n", $linkMask);
        $definitions = [];
        $definitionLines = $headingLines = [];
        $tableRows = $this->djotTableRows($source, $this->maskCodeAndDestinations($source, false));
        $offsets = [];
        $quoteDepths = [];
        $offset = 0;
        foreach ($lines as $n => $line) {
            $offsets[$n] = $offset;
            $quoteDepths[$n] = substr_count(substr($line, 0, $this->djotContentStart($line)), '>');
            $offset += strlen($line) + 1;
        }
        if (max($quoteDepths) > 0) {
            $this->normalizeDjotStructure($source, onLiteralQuote: static function (int $line, int $depth) use (&$quoteDepths): void {
                $quoteDepths[$line] = $depth;
            });
        }
        if (str_contains($source, '`') && str_contains($source, ':::')) {
            $codeSource = $this->replaceImageLabels($source, $this->maskCodeAndDestinations($source), fn (array $match): string => $this->blanks($match[0][0]));
            foreach ($lines as $line => $value) {
                $depth = 0;
                $prefixEnd = $this->djotContentStart($value);
                for ($i = 0; $i < $prefixEnd; $i++) {
                    if ($value[$i] === '>' && ++$depth > ($quoteDepths[$line] ?? 0)) {
                        $codeSource[$offsets[$line] + $i] = ' ';
                    }
                }
            }
            $codeSpans = [];
            $spanEnd = 0;
            $this->maskCodeAndDestinations($codeSource, false, opaqueOptions: [
                'destinations' => true,
                'onCode' => static function (int $at, int $end, bool $closed) use (&$codeSpans, &$spanEnd): void {
                    if ($at >= $spanEnd) {
                        $codeSpans[] = [$at, $end, $closed];
                        $spanEnd = $end;
                    }
                },
            ]);
            $codeLine = 0;
            foreach ($codeSpans as [$at, $end, $closed]) {
                while (isset($offsets[$codeLine + 1]) && $offsets[$codeLine + 1] <= $at) {
                    $codeLine++;
                }
                if (($quoteDepths[$codeLine] ?? 0) > 0) {
                    continue;
                }
                $width = $this->backtickRun($source, $at);
                $content = substr($source, $at + $width, max(0, $end - $at - $width - ($closed ? $width : 0)));
                if (preg_match('/\n[ \t]*:{3,}(?:[ \t]+[^\n]*)?(?:\n|$)/', $content) === 1) {
                    $add($at, 'A multiline code span contains a Carve container opener on a continuation line.');
                }
            }
        }
        foreach ($lines as $n => $line) {
            if (isset($definitionLines[$n])) {
                continue;
            }
            $at = $this->djotContentStart($line);
            $content = substr($line, $at);
            $contentMask = substr($visible[$n], $at);
            $previous = $lines[$n - 1] ?? '';
            $previousAt = $this->djotContentStart($previous);
            $previousContent = trim(substr($previous, $previousAt));
            $item = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', $this->djotItemPrefix($line, $at)) === 1;
            $boundary = $previousContent === '' || ($tableRows[$n - 1] ?? false) || $item || isset($definitionLines[$n - 1])
                || preg_match('/^(?:#{1,6}(?: |$)|:{3,}|[`~]{3,}|\{|\[[^\]]+\]:)/', $previousContent) === 1
                || preg_match('/^(?:[*-][ \t]*){3,}$/', $previousContent) === 1
                || ($previousAt > $at && preg_match('/(?:>|[-*+] |[0-9A-Za-z]+[.)] |\([0-9A-Za-z]+\) )/', $this->djotItemPrefix($previous, $previousAt)) === 1);
            if (isset($accepted[$n])) {
                $rawFormatIgnored[] = [$offsets[$n], $offsets[$accepted[$n]['end']] + strlen($lines[$accepted[$n]['end']])];
                for ($next = $n + 1; $next <= $accepted[$n]['end']; $next++) {
                    $definitionLines[$next] = true;
                }
                $definitions[$this->djotReferenceDefinitionKey($accepted[$n]['label'])] = $accepted[$n]['target'];
                $definitionLines[$n] = true;
                $lineEnd = $offsets[$n] + strlen($line);
                for ($k = $offsets[$n]; $k < $lineEnd; $k++) {
                    $linkMask[$k] = ' ';
                }
            }
            if (str_starts_with($contentMask, '#') && preg_match('/^(#{1,6})(?:[ \t]+|$)/', $content, $heading) === 1 && $boundary && !isset($headingLines[$n])) {
                $text = substr($content, strlen($heading[0]));
                $lastPart = $text;
                $marker = '/^' . preg_quote($heading[1], '/') . '[ \t]+/';
                for ($next = $n + 1; $next < $lineCount; $next++) {
                    $following = $lines[$next];
                    $nextAt = $this->djotContentStart($following);
                    $prefix = substr($following, 0, $nextAt);
                    if (substr_count($prefix, '>') !== substr_count(substr($line, 0, $at), '>') || preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', $this->djotItemPrefix($following, $nextAt)) === 1) {
                        break;
                    }
                    $part = substr($following, $nextAt);
                    if (trim($part) === '' || preg_match('/(?:^|[^\\\\])(?:\\\\\\\\)*\\\\$/', $lastPart) === 1) {
                        break;
                    }
                    if (preg_match($marker, $part) === 1) {
                        $lastPart = preg_replace($marker, '', $part) ?? $part;
                        $text .= "\n" . $lastPart;
                    } else {
                        if (preg_match('/^(?:[#>|{]|[-*+][ \t]|[0-9A-Za-z]+[.)][ \t]|:[ \t]|:{2,}|\([0-9a-zA-Z]+\)[ \t]|[`~]{3,}|\[[^\]\n]*\]:|(?:[*-][ \t]*){3,}$)/', $part) === 1) {
                            break;
                        }
                        $lastPart = $part;
                        $text .= "\n" . $part;
                    }
                    $headingLines[$next] = true;
                }
                $key = $this->djotReferenceDefinitionKey($childText($text));
                $definitions[$key] ??= '#' . $key;
            }
            if (preg_match('/^#{1,6}[ \t]*$/', $contentMask) === 1 && trim($childText(substr($content, strlen($heading[0] ?? '')))) === '' && $boundary && !isset($headingLines[$n])) {
                $next = trim($lines[$n + 1] ?? '');
                if ($next === '' || preg_match('/^(?:[#>|{]|[-*+] |:{3,}|`{3,}|~{3,})/', $next) === 1) {
                    $add($offsets[$n], 'An empty heading has no Carve spelling.');
                }
            }
            if (preg_match('/:[ \t]+$/', substr($visible[$n], 0, $at), $term, PREG_OFFSET_CAPTURE) === 1 && ($term[0][1] === 0 || str_contains(" \t", $visible[$n][$term[0][1] - 1])) && preg_match('/^\S/', $contentMask) === 1 && ($previousContent === '' || $n === 0 || $item)) {
                $quoteDepth = substr_count(substr($line, 0, $at), '>');
                $inContainer = fn (string $next): bool => substr_count(substr($next, 0, $this->djotContentStart($next)), '>') === $quoteDepth;
                $blank = fn (string $next): bool => trim(substr($next, $this->djotContentStart($next))) === '';
                $withoutQuotes = function (string $next): string {
                    $lastQuote = strrpos(substr($next, 0, $this->djotContentStart($next)), '>');

                    return $lastQuote === false ? $next : (preg_replace('/^[ \t]/', '', substr($next, $lastQuote + 1)) ?? $next);
                };
                $minimum = strlen($withoutQuotes(substr($line, 0, $term[0][1]))) + 2;
                $continuesTerm = static function (string $next) use ($withoutQuotes, $minimum): bool {
                    $content = $withoutQuotes($next);

                    return preg_match('/^[ \t]*(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', $content) !== 1 || strspn($content, " \t") > $minimum - 2;
                };
                $end = $n + 1;
                while ($end < $lineCount && $inContainer($lines[$end]) && !$blank($lines[$end]) && $continuesTerm($lines[$end])) {
                    $end++;
                }
                while ($end < $lineCount && $blank($lines[$end])) {
                    $end++;
                }
                if ($end === $lineCount || !$inContainer($lines[$end]) || strspn($withoutQuotes($lines[$end]), " \t") < $minimum) {
                    $add($offsets[$n], 'An empty definition description has no Carve spelling.');
                }
            }
        }
        if ($definitionsOnly) {
            $referenceDefinitions = $definitions;

            return [];
        }
        $rows = [];
        $offset = 0;
        foreach ($lines as $n => $line) {
            $view = trim($visible[$n]);
            if ($tableRows[$n]) {
                $view = trim(substr($line, $this->djotContentStart($line)));
                $separator = preg_match('/^\|(?:[ \t]*:?-+:?[ \t]*\|)+[ \t]*$/', $view) === 1;
                if ($separator && count($rows) >= 2) {
                    $add($offset, 'A separator inside a table promotes the preceding row to a header; Carve cannot spell that structure.');
                }
                // A row of blank cells is a Carve row (CARVE-P9-082), but Carve
                // reads a tab between the pipes as content where Djot reads padding.
                if (str_contains($view, "\t") && preg_match('/^\|(?:[ \t]*\|)+$/', $view) === 1) {
                    $add($offset, 'A blank table cell holding a tab reads as tab content in Carve.');
                }
                $rows[] = ['at' => $offset, 'separator' => $separator];
            } else {
                $this->reportDjotTableLosses($rows, $add);
                $rows = [];
            }
            $offset += strlen($line) + 1;
        }
        $this->reportDjotTableLosses($rows, $add);
        $mask = $linkMask;
        $boundaryOffsets = $this->djotInlineBoundaries($source, $mask);
        $boundaries = array_fill_keys($boundaryOffsets, true);
        if (str_contains($source, ':::')) {
            $divBoundaries = array_fill_keys($this->djotInlineBoundaries($source, $mask, true), true);
            foreach ($lines as $n => $line) {
                $at = $this->djotContentStart($line);
                if (
                    isset($divBoundaries[$offsets[$n]]) && ($mask[$offsets[$n] + $at] ?? '') === ':'
                    && preg_match('/^:{3,}[ \t]*([A-Za-z0-9_-]+)[ \t]*$/', substr($line, $at), $div) === 1
                    && in_array($div[1], Div::ADMONITION_TYPES, true)
                ) {
                    $add($offsets[$n] + $at, 'Carve PHP renders the Djot DIV class "' . $div[1] . '" as an admonition; it cannot preserve the generic DIV wrapper.');
                }
            }
        }
        $referenceEnds = [];
        $next = -1;
        for ($at = strlen($source) - 1; $at >= 0; $at--) {
            if (isset($boundaries[$at])) {
                $next = -1;
            } elseif ($mask[$at] === ']' && !$this->isDjotEscaped($source, $at)) {
                $next = $at;
            } elseif ($mask[$at] === '[' && !$this->isDjotEscaped($source, $at)) {
                $next = -1;
            }
            if ($at >= 2 && $source[$at - 1] === '[' && $source[$at - 2] === ']') {
                $referenceEnds[$at] = $next;
            }
        }
        $starts = $images = $innerLinks = $depths = [];
        $boundary = 0;
        $sourceLine = 0;
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            while (($offsets[$sourceLine + 1] ?? $length) <= $at) {
                $sourceLine++;
            }
            while (($boundaryOffsets[$boundary] ?? $length) <= $at) {
                $starts = $images = $innerLinks = $depths = [];
                $boundary++;
            }
            if ($mask[$at] === '\\') {
                $at++;

                continue;
            }
            if ($mask[$at] === '[') {
                $starts[] = $at;
                $depths[$at] = $quoteDepths[$sourceLine];
                $images[] = $at > 0 && $source[$at - 1] === '!' && !$this->isDjotEscaped($source, $at - 1);
                $innerLinks[] = false;
            } elseif ($mask[$at] === ']' && $starts !== []) {
                $start = array_pop($starts);
                $depth = $depths[$start];
                unset($depths[$start]);
                $image = array_pop($images);
                $innerLink = array_pop($innerLinks);
                $footnote = ($source[$start + 1] ?? '') === '^' && $at > $start + 2;
                if ($footnote) {
                    $rawFormatIgnored[] = [$start, $at + 1];
                }
                $validForm = isset($destinations[$at + 1]) || ($source[$at + 1] ?? '') === '[' && ($referenceEnds[$at + 2] ?? -1) >= 0;
                $isLink = !$image && !$footnote && $validForm;
                $referenceEnd = $referenceEnds[$at + 2] ?? -1;
                if (!$footnote && ($source[$at + 1] ?? '') === '[' && $referenceEnd >= 0) {
                    $rawFormatIgnored[] = [$at + 2, $referenceEnd];
                    $label = substr($source, $at + 2, $referenceEnd - $at - 2);
                    if ($label === '' && $definitions !== []) {
                        $label = $this->djotReferenceDefinitionKey($childText(substr($source, $start + 1, $at - $start - 1), $depth));
                    } else {
                        $label = $this->djotReferenceKey($label);
                    }
                    if (!array_key_exists($label, $definitions)) {
                        $add($start, $image ? 'An unresolved Djot image reference has no src; Carve cannot spell that image.' : 'An unresolved Djot reference renders a link without href; Carve has no spelling for it.');
                    } elseif ($definitions[$label] === '') {
                        $add($start, $image ? 'An image with an empty destination has no Carve spelling.' : 'A link with an empty destination has no Carve spelling.');
                    }
                }
                if (!$footnote && isset($destinations[$at + 1]) && $this->djotDestinationLines(substr($source, $at + 2, $destinations[$at + 1] - $at - 3), $depth) === '') {
                    $add($start, $image ? 'An image with an empty destination has no Carve spelling.' : 'A link with an empty destination has no Carve spelling.');
                }
                if ($image && !$footnote && $validForm) {
                    $rawFormatIgnored[] = [$start, $at + 1];
                    $text = substr($source, $start + 1, $at - $start - 1);
                    $destination = isset($destinations[$at + 1])
                        ? $this->djotDestinationLines(substr($source, $at + 2, $destinations[$at + 1] - $at - 3), $depth)
                        : ($definitions[$label ?? ''] ?? null);
                    if ($destination !== null && $destination !== '' && str_contains($text, "\n")) {
                        $child = $imageChildText($text, $depth);
                        if (preg_match('/\n[ \t]*\n/', $child) === 1) {
                            $add($start, 'An image alternative text with a blank line cannot be spelled in Carve.');
                        } elseif ($this->djotImageTrailingWhitespaceLoss($text, $depth, $imageChildText)) {
                            $add($start, 'An image alternative text loses trailing spaces before a line break.');
                        }
                    }
                }
                if ($isLink && $innerLink) {
                    $add($start, 'A link inside a link has no Carve spelling.');
                }
                if ($starts !== [] && ($isLink || $innerLink && !($image && $validForm))) {
                    $innerLinks[count($starts) - 1] = true;
                }
                if (!$footnote && ($source[$at + 1] ?? '') === '[' && $referenceEnd >= 0) {
                    $at = $referenceEnd;
                }
            }
        }

        usort($rawFormatIgnored, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $ignoredIndex = 0;
        $ignoredEnd = -1;
        $ignoredCount = count($rawFormatIgnored);
        foreach ($rawFormats as [$start, $end]) {
            while ($ignoredIndex < $ignoredCount && $rawFormatIgnored[$ignoredIndex][0] <= $start) {
                $ignoredEnd = max($ignoredEnd, $rawFormatIgnored[$ignoredIndex++][1]);
            }
            if ($ignoredEnd < $end) {
                $add($start, $rawFormatMessage);
            }
        }
        $positions = array_column($findings, 0);
        sort($positions, SORT_NUMERIC);
        $linesAt = [];
        $cursor = 0;
        $line = 1;
        foreach ($positions as $at) {
            if (isset($linesAt[$at])) {
                continue;
            }
            $line += substr_count(substr($source, $cursor, $at - $cursor), "\n");
            $linesAt[$at] = $line;
            $cursor = $at;
        }
        $losses = [];
        foreach ($findings as [$at, $message]) {
            $line = $linesAt[$at];
            $key = $line . ':' . $message;
            $losses[$key] = new MigrationDiagnostic('structure-unspellable', $message, 'warning', 'dropped', 'exact', 'line:' . $line);
        }
        $losses = array_values($losses);
        usort($losses, static fn (MigrationDiagnostic $left, MigrationDiagnostic $right): int => (int)substr($left->path ?? '', 5) <=> (int)substr($right->path ?? '', 5));

        return $losses;
    }

    private function djotReferenceKey(string $label): string
    {
        $label = preg_replace('/\\\\([ \t!-\/:-@\[-`{-~])/', '$1', $label) ?? $label;

        return $this->djotReferenceDefinitionKey($label);
    }

    private function djotReferenceRenderedLabel(string $label): string
    {
        $paragraph = new Paragraph();
        (new InlineParser(new BlockParser()))->parse($paragraph, $this->convert($label));

        $pending = [$paragraph];
        while ($pending !== []) {
            $node = array_pop($pending);
            $children = array_values(array_filter($node->getChildren(), static fn ($child): bool => !$child instanceof NonBreakingSpace));
            if (count($children) !== count($node->getChildren())) {
                $node->setChildren($children);
            }
            foreach ($children as $child) {
                $pending[] = $child;
            }
        }

        return (new HeadingIdTracker())->getPlainText($paragraph, SmartTypographyMode::Source);
    }

    private function djotReferenceDefinitionKey(string $label): string
    {
        return trim(preg_replace('/[ \t\r\n]+/', ' ', $label) ?? $label, " \t\r\n");
    }

    /**
     * @param list<array{at: int, separator: bool}> $rows
     * @param callable(int, string): void $add
     */
    private function reportDjotTableLosses(array $rows, callable $add): void
    {
        if ($rows === []) {
            return;
        }
        if (count(array_filter($rows, static fn (array $row): bool => !$row['separator'])) === 0) {
            $add($rows[0]['at'], 'A table containing only separator rows has no Carve spelling.');
        }
    }
}
