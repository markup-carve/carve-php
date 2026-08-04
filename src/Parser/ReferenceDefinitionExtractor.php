<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

class ReferenceDefinitionExtractor
{
    /**
     * Extract reference link definitions from the document.
     *
     * @param array<string> $lines
     *
     * @return array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>
     */
    public function extract(array $lines): array
    {
        $references = [];
        $i = 0;
        $count = count($lines);
        $pendingAttrs = [];
        $pendingAttrsInQuote = false;
        $pendingAttrsInList = false;
        $fenceChar = null;
        $fenceLen = 0;
        $fenceContentCol = 0;
        $fenceQuoted = false;
        // A LINE BLOCK is verse: its body is inline content, so a definition
        // written there is text and registers nothing (PART 9 §23, carve#574).
        // Registering it made the line render AND resolve elsewhere - the one
        // place in the language where a construct did both (carve#557).
        // Tracked like a code fence, closing on its own width.
        $verseFence = 0;
        $listContentCols = [];
        $prevBlank = true;

        while ($i < $count) {
            $line = $lines[$i];
            $wasPrevBlank = $prevBlank;
            $prevBlank = trim($line) === '';

            if ($fenceChar === null) {
                $this->updateListContentColumns($line, $wasPrevBlank, $listContentCols);
            }

            $contentCol = $listContentCols === [] ? 0 : $listContentCols[array_key_last($listContentCols)];
            $fenceView = $this->fenceView($line, $contentCol);

            if ($verseFence > 0) {
                if (preg_match('/^(:{3,})\s*$/', trim($line), $vm) && strlen($vm[1]) >= $verseFence) {
                    $verseFence = 0;
                }
                $i++;

                continue;
            }
            if (preg_match('/^(:{3,})[ \t]*\|(?:[ \t]*\{.*\})?[ \t]*$/', trim($line), $vo) === 1) {
                $verseFence = strlen($vo[1]);
                $i++;

                continue;
            }

            if ($fenceChar !== null) {
                if ($this->isFenceCloser($line, $fenceQuoted, $fenceContentCol, $fenceChar, $fenceLen)) {
                    $fenceChar = null;
                    $fenceLen = 0;
                    $fenceContentCol = 0;
                    $fenceQuoted = false;
                }
                $i++;

                continue;
            }

            if ($this->matchFenceOpener($fenceView['line']) !== null) {
                $match = $this->matchFenceOpener($fenceView['line']);
                $fenceChar = $match['char'];
                $fenceLen = $match['length'];
                $fenceContentCol = $contentCol;
                $fenceQuoted = $fenceView['quoted'];
                $i++;

                continue;
            }

            $referenceLine = $this->referenceLineView($line, $contentCol);
            $bare = $referenceLine['line'];

            // An attribute line above a definition belongs to the next VISIBLE
            // block (§15 A2a), not to the definition: it is SKIPPED here rather
            // than collected, and the block parser keeps it pending. Collecting
            // it put the attributes on every link that used the label and took
            // them away from the block the author wrote them for
            // (carve-php#702). A trailing `{...}` ON the definition line is a
            // different construct and still applies.
            $refAttrStr = $this->parseSingleLineBlockAttributePayload($bare);
            if ($refAttrStr !== null && $refAttrStr !== '') {
                $i++;

                continue;
            }

            $definition = $this->parseReferenceDefinition($bare, $pendingAttrs, $pendingAttrsInQuote, $pendingAttrsInList, $referenceLine);
            if ($definition !== null) {
                $references[$definition['label']] = new ReferenceDefinition(
                    $definition['url'],
                    $definition['attrs'],
                    $i,
                    $definition['title'],
                );
                $pendingAttrs = [];
                $pendingAttrsInQuote = false;
                $pendingAttrsInList = false;
                $i++;

                continue;
            }

            if (!IndentationHelper::isBlankLine($line)) {
                $pendingAttrs = [];
                $pendingAttrsInQuote = false;
                $pendingAttrsInList = false;
            }

            $i++;
        }

        return $references;
    }

    /**
     * @param string $line Current source line.
     * @param bool $wasPrevBlank Whether the previous line was blank.
     * @param list<int> $listContentCols List content columns, updated in place.
     */
    private function updateListContentColumns(string $line, bool $wasPrevBlank, array &$listContentCols): void
    {
        $indent = strlen($line) - strlen(ltrim($line, " \t"));
        $rawTrimmed = trim($line);
        $startsBlock = preg_match('/^#{1,6}([ \t]|$)/', $rawTrimmed) === 1
            || str_starts_with($rawTrimmed, '>')
            || preg_match('/^(`{3,}|~{3,})/', $rawTrimmed) === 1
            || preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $rawTrimmed) === 1;

        if (
            preg_match(
                '/^([ \t]*)(?:[-*]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)])(?:\{[^}]*\})? +/',
                $line,
                $lm,
            ) === 1
            && preg_match('/\S/', substr($line, strlen($lm[0]))) === 1
        ) {
            $markerIndent = strlen($lm[1]);
            while ($listContentCols !== [] && $listContentCols[array_key_last($listContentCols)] > $markerIndent) {
                array_pop($listContentCols);
            }
            $listContentCols[] = strlen($lm[0]);
        } elseif ($rawTrimmed !== '' && ($wasPrevBlank || $startsBlock)) {
            while ($listContentCols !== [] && $listContentCols[array_key_last($listContentCols)] > $indent) {
                array_pop($listContentCols);
            }
        }
    }

    /**
     * @return array{line: string, quoted: bool}
     */
    private function fenceView(string $line, int $contentCol): array
    {
        $afterMarker = preg_replace(
            '/^([ \t]*)(?:[-*]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)])(?:\{[^}]*\})? +(?:\[[ xX\-_>?]\] +)?/',
            '',
            $line,
        ) ?? $line;
        $rawIsQuoted = preg_match('/^(?:[^\S\x{00A0}]*>[^\S\x{00A0}]?)+/u', $line) === 1
            || preg_match('/^(?:[^\S\x{00A0}]*>[^\S\x{00A0}]?)+/u', $afterMarker) === 1;

        $fenceLine = $line;
        do {
            $previousFenceLine = $fenceLine;
            if (($fenceLine[0] ?? '') === '>' && preg_match('/^> ?/', $fenceLine)) {
                $fenceLine = preg_replace('/^> ?/', '', $fenceLine) ?? $fenceLine;
            }
            $fenceLine = $this->stripFenceListMarker($fenceLine);
        } while ($fenceLine !== $previousFenceLine);

        $keptIndent = strlen($fenceLine) - strlen(ltrim($fenceLine, " \t"));

        return [
            'line' => $keptIndent >= $contentCol ? substr($fenceLine, $contentCol) : $fenceLine,
            'quoted' => $rawIsQuoted,
        ];
    }

    private function stripFenceListMarker(string $line): string
    {
        $f0 = $line[0] ?? '';
        if (
            $f0 !== ' '
            && $f0 !== "\t"
            && $f0 !== '-'
            && $f0 !== '*'
            && ($f0 < '0' || $f0 > '9')
            && ($f0 < 'a' || $f0 > 'z')
            && ($f0 < 'A' || $f0 > 'Z')
        ) {
            return $line;
        }

        return preg_replace(
            '/^[ \t]*(?:[-*]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)])(?:\{[^}]*\})? +(?:\[[ xX\-_>?]\] +)?(?=\S)/',
            '',
            $line,
        ) ?? $line;
    }

    private function isFenceCloser(string $line, bool $fenceQuoted, int $fenceContentCol, string $fenceChar, int $fenceLen): bool
    {
        $closeLine = $fenceQuoted
            ? (preg_replace('/^(?:[^\S\x{00A0}]*>[^\S\x{00A0}]?)+/u', '', $line) ?? $line)
            : $line;
        $closeIndent = strlen($closeLine) - strlen(ltrim($closeLine, " \t"));
        $deIndentedCloseLine = $closeIndent >= $fenceContentCol
            ? substr($closeLine, $fenceContentCol)
            : $closeLine;

        return preg_match('/^([`~]{3,})\s*$/', $deIndentedCloseLine, $fm) === 1
            && $fm[1][0] === $fenceChar
            && strlen($fm[1]) >= $fenceLen;
    }

    /**
     * @return array{char: string, length: int}|null
     */
    private function matchFenceOpener(string $line): ?array
    {
        $c0 = $line[0] ?? '';
        if (($c0 !== '`' && $c0 !== '~') || preg_match('/^([`~]{3,})/', $line, $fm) !== 1) {
            return null;
        }

        return ['char' => $fm[1][0], 'length' => strlen($fm[1])];
    }

    /**
     * @return array{line: string, inQuote: bool, inList: bool}
     */
    private function referenceLineView(string $line, int $contentCol): array
    {
        $bare = $line;
        $inQuote = false;
        $inList = false;
        do {
            $previousBare = $bare;
            if (($bare[0] ?? '') === '>' && preg_match('/^> ?/', $bare)) {
                $inQuote = true;
                $bare = preg_replace('/^> ?/', '', $bare) ?? $bare;
            }
            $afterMarker = $this->stripReferenceListMarker($bare);
            if ($afterMarker !== $bare) {
                $inList = true;
                $bare = $afterMarker;
            }
        } while ($bare !== $previousBare);

        if (
            !$inList
            && $contentCol > 0
            && strlen($line) - strlen(ltrim($line, " \t")) >= $contentCol
        ) {
            $bare = substr($line, $contentCol);
            $inList = true;
        }

        return ['line' => $bare, 'inQuote' => $inQuote, 'inList' => $inList];
    }

    private function stripReferenceListMarker(string $line): string
    {
        $m0 = $line[0] ?? '';
        if ($m0 !== ' ' && $m0 !== "\t" && $m0 !== '-' && $m0 !== '*' && ($m0 < '0' || $m0 > '9')) {
            return $line;
        }

        return preg_replace(
            '/^[ \t]*(?:[-*]|[0-9]+[.)]) +(?:\[[ xX\-_>?]\] +)?(?=\S)/',
            '',
            $line,
        ) ?? $line;
    }

    /**
     * @param string $line Normalized line without leading container markers.
     * @param array<string, string> $pendingAttrs
     * @param bool $pendingAttrsInQuote Whether the pending attributes were found in a quote.
     * @param bool $pendingAttrsInList Whether the pending attributes were found in a list.
     * @param array{line: string, inQuote: bool, inList: bool} $referenceLine
     *
     * @return array{label: string, url: string, attrs: array<string, string>, title: string|null}|null
     */
    private function parseReferenceDefinition(
        string $line,
        array $pendingAttrs,
        bool $pendingAttrsInQuote,
        bool $pendingAttrsInList,
        array $referenceLine,
    ): ?array {
        // `[^…]:` with a NON-EMPTY label is a footnote definition and takes
        // precedence, so it is excluded here. `[^]:` is not: `footnote_label`
        // is one-or-more characters, so an empty label never forms a footnote
        // definition and the line falls through to a reference definition with
        // the label `^` - which `reference_label` admits, being neither `]`
        // nor `@`. Excluding every `[^` left that line as paragraph text, where
        // carve-js and carve-rs both render nothing.
        if (($line[0] ?? '') !== '[' || preg_match('/^\[(?!@)(?!\^[^\]]+\]:)([^\]]+)\]: [ \t]*(\S.*)$/', $line, $matches) !== 1) {
            return null;
        }

        $url = self::trimUnicodeWhitespace($matches[2]);
        if ($url === '') {
            return null;
        }

        $label = preg_replace('/\s+/', ' ', trim($matches[1])) ?? trim($matches[1]);
        // A trailing `{...}` block attributes the DEFINITION (PART 9 §16,
        // `[space, attributes]`), and PART 9R R1 transfers those attributes to
        // every link that resolves the label.
        [$url, $attrsToUse] = self::splitTrailingAttributes($url);
        $title = null;

        if (
            preg_match(
                '/^([^\p{Z}\x{0009}-\x{000D}\x{0085}]+)'
                . '(?:[\p{Z}\x{0009}-\x{000D}\x{0085}]+'
                . '(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'))?/u',
                $url,
                $tm,
                PREG_UNMATCHED_AS_NULL,
            )
        ) {
            $url = $tm[1];
            if (($tm[2] ?? null) !== null) {
                $title = AttributeParser::processEscapes($tm[2]);
            } elseif (($tm[3] ?? null) !== null) {
                $title = AttributeParser::processEscapes($tm[3]);
            }
        }

        return [
            'label' => $label,
            'url' => trim($url),
            'attrs' => $attrsToUse,
            'title' => $title,
        ];
    }

    /**
     * Split a definition's tail into destination-plus-title and the trailing
     * attribute block, if the line ends with one.
     *
     * The block is SCANNED, not regex-matched: an attribute value may hold a
     * `}` inside quotes, and a lazy `\{[^}]*\}` stops at that brace and drops
     * every attribute on the line silently. Only an UNQUOTED `}` closes the
     * block, it must be preceded by whitespace, and it must end the line - so
     * `[a]: /u{.x}` keeps the braces in the destination, which is what
     * `space, attributes` requires.
     *
     * @param string $tail
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function splitTrailingAttributes(string $tail): array
    {
        $length = strlen($tail);
        for ($i = 0; $i < $length; $i++) {
            if ($tail[$i] !== '{' || $i === 0) {
                continue;
            }
            $before = $tail[$i - 1];
            if ($before !== ' ' && $before !== "\t") {
                continue;
            }

            $quote = null;
            for ($j = $i + 1; $j < $length; $j++) {
                $char = $tail[$j];
                if ($char === '\\' && $j + 1 < $length) {
                    $j++;

                    continue;
                }
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;

                    continue;
                }
                if ($char === '}') {
                    if (trim(substr($tail, $j + 1)) !== '') {
                        break;
                    }
                    $payload = substr($tail, $i + 1, $j - $i - 1);
                    // The ORDERED parser: `parse()` hoists `class` to the front
                    // regardless of where the author wrote it, and these
                    // attributes are applied to a link in array order, so the
                    // hoist would reorder the rendered attributes of every link
                    // resolving the label. The inline path already preserves
                    // source order; this has to match it.
                    $parsed = AttributeParser::parseOrderedWithSlots($payload);
                    $attrs = $parsed['attributes'];
                    if ($attrs === []) {
                        return [$tail, []];
                    }
                    $ordered = [];
                    foreach ($parsed['order'] as $slot) {
                        $key = match ($slot) {
                            '.class' => 'class',
                            '#id' => 'id',
                            default => $slot,
                        };
                        if (isset($attrs[$key])) {
                            $ordered[$key] = $attrs[$key];
                        }
                    }
                    $attrs = $ordered === [] ? $attrs : $ordered;

                    return [rtrim(substr($tail, 0, $i)), $attrs];
                }
            }
        }

        return [$tail, []];
    }

    private function parseSingleLineBlockAttributePayload(string $line): ?string
    {
        $line = rtrim($line, " \t");
        $length = strlen($line);
        if ($length === 0 || $line[0] !== '{') {
            return null;
        }

        $parts = [];
        $pos = 0;
        while ($pos < $length) {
            if ($line[$pos] !== '{') {
                return null;
            }

            $end = $this->findSingleLineAttributeBlockEnd($line, $pos);
            if ($end === null) {
                return null;
            }

            $parts[] = trim(substr($line, $pos + 1, $end - $pos - 1));
            $pos = $end + 1;
        }

        return trim(implode(' ', $parts));
    }

    private function findSingleLineAttributeBlockEnd(string $line, int $start): ?int
    {
        $length = strlen($line);
        $quote = null;
        for ($i = $start + 1; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === "\n") {
                return null;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char === '}') {
                return $i;
            }
        }

        return null;
    }

    private static function trimUnicodeWhitespace(string $value): string
    {
        $trimmed = preg_replace(
            '/^[\p{Z}\x{0009}-\x{000D}\x{0085}]+|[\p{Z}\x{0009}-\x{000D}\x{0085}]+$/u',
            '',
            $value,
        );

        return $trimmed ?? trim($value);
    }
}
