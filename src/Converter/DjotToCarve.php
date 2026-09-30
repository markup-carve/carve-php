<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HeadingId\PreservesHeadingIds;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;

/**
 * Converts Djot markup to Carve markup.
 *
 * Several inline delimiters mean different things in Djot and Carve, so a Djot
 * document fed to a Carve processor renders wrong with no error. This converter
 * rewrites exactly those constructs to their Carve equivalents:
 *
 *   _x_ -> /x/ (Djot emphasis is underline in Carve)
 *   ~x~ -> {,x,} (Djot subscript is strikethrough in Carve; forced brace form)
 *   {~x~} -> {,x,} (Djot spells subscript braced too, and means the same by it)
 *   {=x=} -> {=x=} (highlight is the same braced form in Carve)
 *   **x** -> *x* (Markdown bold; Carve bold is a single *)
 *   ~~x~~ -> ~x~ (Markdown strikethrough; Carve strike is a single ~)
 *
 * Constructs that mean the same in both languages ($math$, {+ins+},
 * {-del-}, {^x^}, reference links) are left untouched. Delimiters inside code (fenced
 * or inline) and link/image destinations are never rewritten. Only the
 * delimiters are replaced, never the inner text, so nested constructs of
 * different families compose correctly.
 *
 * An intraword `_x_` converts too, to the braced `{/x/}`. Djot's spec puts no
 * word boundary on emphasis, so `snake_case_name` IS emphasis in the source
 * language and an author who wanted the literal characters had to escape them;
 * an unescaped run is therefore what the author saw and kept. The braced form
 * is required because a bare `/` is literal intraword in Carve.
 *
 * Ported from the carve-js djot-migrate linter (the canonical list of
 * Djot/Carve delimiter collisions). Operates byte-wise so offsets from the
 * masked scan splice into the original UTF-8 string unchanged.
 */
class DjotToCarve
{
    use EscapesCarveConstructs;
    use PreservesHeadingIds;
    use ReportsMigrationFidelity;

    /**
     * @var array<array{id: string, family: string, pattern: string, open: string, close: string}>
     */
    protected array $rules = [
        [
            'id' => 'markdown-strong-double-star',
            'family' => '*',
            'pattern' => '/\*\*(?!\s)((?:(?!\n[ \t]*\n)[^*])+?)(?<!\s)\*\*/',
            'open' => '*',
            'close' => '*',
        ],
        [
            'id' => 'markdown-strikethrough-double-tilde',
            'family' => '~',
            'pattern' => '/~~(?!\s)((?:(?!\n[ \t]*\n)[^~])+?)(?<!\s)~~/',
            'open' => '~',
            'close' => '~',
        ],
        [
            // Djot spells subscript both bare and braced, and means the same
            // thing by each. The braced spelling has to be matched here, ahead
            // of the bare rule and in the same family, so it claims the range
            // first: the bare rule's match sits inside this one, and the
            // overlap check then rejects it. Without this the braced form
            // reached the escaper instead and was written out as literal text,
            // losing the subscript.
            'id' => 'djot-subscript-tilde-braced',
            'family' => '~',
            'pattern' => '/\{~(?!\s)((?:(?!\n[ \t]*\n)[^~])+?)(?<!\s)~\}/',
            'open' => '{,',
            'close' => ',}',
        ],
        [
            'id' => 'djot-subscript-tilde',
            'family' => '~',
            'pattern' => '/~(?!\s)((?:(?!\n[ \t]*\n)[^~])+?)(?<!\s)~/',
            'open' => '{,',
            'close' => ',}',
        ],
        [
            // Braced superscript is spelled identically in both languages, so
            // the conversion is the identity. It still needs a rule: claiming
            // the range is what stops the bare rule below from matching the
            // `^x^` inside the braces and wrapping it a second time, into
            // `{{^x^}}`.
            'id' => 'djot-superscript-caret-braced',
            'family' => '^',
            'pattern' => '/\{\^(?!\s)((?:(?!\n[ \t]*\n)[^^])+?)(?<!\s)\^\}/',
            'open' => '{^',
            'close' => '^}',
        ],
        [
            // Carve has no bare superscript at all (a `^` outside the braced
            // form is literal), so every Djot `^x^` needs the braced form.
            'id' => 'djot-superscript-caret',
            'family' => '^',
            'pattern' => '/\^(?!\s)((?:(?!\n[ \t]*\n)[^^])+?)(?<!\s)\^/',
            'open' => '{^',
            'close' => '^}',
        ],
        [
            'id' => 'djot-emphasis-underscore',
            'family' => '_',
            'pattern' => '/(?<![A-Za-z0-9_])_(?!\s)((?:(?!\n[ \t]*\n)(?:\\\\(?!\n[ \t]*\n)[\s\S]|[^_\\\\]))+?)(?<!\s)_(?![A-Za-z0-9_])/',
            'open' => '/',
            'close' => '/',
        ],
        [
            // The complement of the rule above, and it CONVERTS rather than
            // leaving the run literal. The input is a DJOT document: Djot
            // emphasizes an intraword `_`, and an author who wanted the literal
            // characters had to escape them. `snake\_case\_name` renders as
            // `snake_case_name` in Djot and arrives here already escaped, so an
            // UNESCAPED `snake_case_name` is emphasis the author saw in their
            // own renderer and kept.
            //
            // The braced form is required, not stylistic: a bare `/` is literal
            // intraword in Carve, so only `snake{/case/}name` gives back
            // `snake<em>case</em>name`.
            //
            // This does not transfer to `MarkdownToCarve`, whose flanking rules
            // leave an intraword `_` literal - there the identifier reading is
            // the correct one.
            'id' => 'djot-intraword-underscore',
            'family' => '_',
            'pattern' => '/(?<=[A-Za-z0-9])_(?!\s)((?:(?!\n[ \t]*\n)(?:\\\\(?!\n[ \t]*\n)[\s\S]|[^_\\\\]))+?)(?<!\s)_(?=[A-Za-z0-9])/',
            'open' => '{/',
            'close' => '/}',
        ],
        [
            'id' => 'djot-highlight-braces',
            'family' => '{',
            'pattern' => '/\{=(?!\s)((?:(?!\n[ \t]*\n)[\s\S])+?)(?<!\s)=\}/',
            'open' => '{=',
            'close' => '=}',
        ],
    ];

    /**
     * Convert Djot markup to Carve markup.
     */
    public function convert(string $djot): string
    {
        $source = str_replace(["\r\n", "\r"], "\n", $djot);
        [$frontmatter, $separator, $source] = $this->splitSiteFrontmatter($source);
        $source = $this->foldHeadingContinuations($source);
        $collapsedMask = $this->maskCodeAndDestinations($source);
        $collapsedMask = preg_replace_callback('/<[^<>\s]+>/', static fn (array $match): string => preg_match('/[^:]@|[A-Za-z]:/', $match[0]) === 1 ? str_repeat(' ', strlen($match[0])) : $match[0], $collapsedMask) ?? $collapsedMask;
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            if ($collapsedMask[$at] !== '{') {
                continue;
            }
            $attrs = $this->readDjotWordAttributes($source, $at);
            if ($attrs === null) {
                continue;
            }
            for ($i = $at; $i < $attrs['end']; $i++) {
                if ($collapsedMask[$i] !== "\n") {
                    $collapsedMask[$i] = ' ';
                }
            }
            $at = $attrs['end'] - 1;
        }
        preg_match_all('/^[ \t]*(?:>[ ]?)*(?:(?:[-*+]|[0-9]+[.)])[ \t]+)?\[([^\[\]\n]*)\]:[ \t]/m', $source, $definitionMatches, PREG_OFFSET_CAPTURE);
        $definitions = [];
        $previousDefinitionLines = $this->previousSourceLines($source);
        foreach ($definitionMatches[0] as $index => [$value, $at]) {
            $previous = trim(preg_replace('/^[ \t]*(?:>[ ]?)*/', '', $previousDefinitionLines[$at] ?? '') ?? '');
            $bracket = strpos($value, '[');
            if ($bracket !== false && $collapsedMask[$at + $bracket] === '[' && ($previous === '' || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{[.#A-Za-z]|\[(?!\^)[^\]]*\]:)/', $previous) === 1)) {
                $definitions[$definitionMatches[1][$index][0]] = true;
            }
        }
        $source = preg_replace_callback('/(!?\[([^\[\]\n]*)\])\[\]/', static fn (array $match): string => $collapsedMask[$match[0][1]] !== ' ' && isset($definitions[$match[2][0]]) ? $match[1][0] . '[' . $match[2][0] . ']' : $match[0][0], $source, -1, $collapsedCount, PREG_OFFSET_CAPTURE) ?? $source;
        $source = $this->convertDjotBlockMarkers($source);
        $emptyTerm = "\x00DJOTEMPTYTERM\x00";
        while (str_contains($source, $emptyTerm)) {
            $emptyTerm .= "\x00";
        }
        $source = $this->convertDefinitionLists($source, $emptyTerm);
        $djotBody = $source;
        $strongSpans = [];
        $altPrefix = "\x00DJOTALT\x00";
        while (str_contains($source, $altPrefix)) {
            $altPrefix .= "\x00";
        }
        $imageMask = $this->maskCodeAndDestinations($source);
        $source = preg_replace_callback('/!\[([^\[\]\n]*)\](?=[([])/', function (array $match) use (&$strongSpans, $altPrefix, $source, $imageMask): string {
            [$image, $at] = $match[0];
            if ($imageMask[$at] !== '!' || $this->isDjotEscaped($source, $at) || str_contains($match[1][0], '\\')) {
                return $image;
            }
            if (preg_match('/[_*`{^~]/', $match[1][0]) !== 1) {
                $token = $altPrefix . count($strongSpans) . "\x00";
                $strongSpans[$token] = $match[1][0];

                return '![' . $token . ']';
            }
            $label = preg_replace_callback('/(\\\\+)$/', static fn (array $tail): string => strlen($tail[0]) % 2 !== 0 ? $tail[0] . '\\' : $tail[0], $match[1][0]) ?? $match[1][0];
            $token = $altPrefix . count($strongSpans) . "\x00";
            $strongSpans[$token] = substr(rtrim((new CarveConverter(smartTypography: false, renderer: new PlainTextRenderer()))->convert($this->convert('DJOTALT ' . $label . ' DJOTEND')), "\n"), 8, -8);

            return '![' . $token . ']';
        }, $source, -1, $imageCount, PREG_OFFSET_CAPTURE) ?? $source;
        $source = $this->protectAttributedStrong($source, $strongSpans);
        $source = $this->protectAttributedWords($source, $strongSpans);
        [$source, $orphanSpans] = $this->consumeOrphanDjotAttributes($source);
        $carve = DjotEmphasis::convert($source, $this->djotEmphasisMask($source), fn (string $plain): string => $this->rewriteDjotInline($plain));

        $carve = str_replace($emptyTerm, '%%', $carve);
        $carve = strtr($carve, $orphanSpans);
        $carve = strtr($carve, $strongSpans);
        $carve = $this->applyHeadingIdPreservation($carve, $djotBody);

        return $frontmatter === '' ? $carve : $frontmatter . $separator . $carve;
    }

    /**
     * @return array<int, string>
     */
    private function previousSourceLines(string $source): array
    {
        $lines = [];
        $offset = 0;
        $previous = '';
        foreach (explode("\n", $source) as $line) {
            $lines[$offset] = $previous;
            $offset += strlen($line) + 1;
            $previous = $line;
        }

        return $lines;
    }

    private function isDjotEscaped(string $source, int $at): bool
    {
        $start = $at;
        while ($start > 0 && $source[$start - 1] === '\\') {
            $start--;
        }

        return ($at - $start) % 2 !== 0;
    }

    private function rewriteDjotInline(string $source): string
    {
        $source = $this->escapeInvalidAttributeHashes($source);
        $masked = $this->djotEmphasisMask($source, false);
        $source = $this->escapePlainDjotText($source, $masked);
        $masked = $this->djotEmphasisMask($source, false);

        // Accepted [start, end] delimiter ranges per family, kept sorted by start
        // and disjoint, so the overlap check is a binary search instead of a
        // linear scan of every prior match (which was O(n^2) in match count).
        /** @var array<string, array<array{0: int, 1: int}>> $takenByFamily */
        $takenByFamily = [];
        /** @var array<array{0: int, 1: int, 2: string}> $edits */
        $edits = [];

        foreach ($this->rules as $rule) {
            if (!preg_match_all($rule['pattern'], $masked, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }
            /** @var array<array{0: string, 1: int}> $match */
            foreach ($found as $match) {
                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);

                $backslashes = 0;
                for ($k = $start - 1; $k >= 0 && $masked[$k] === '\\'; $k--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 1) {
                    continue;
                }

                if (!isset($takenByFamily[$rule['family']])) {
                    $takenByFamily[$rule['family']] = [];
                }
                // Bind the per-family bucket by reference so the overlap check
                // and the in-place insert mutate the stored array directly. A
                // by-value copy here would trigger copy-on-write duplication of
                // the growing bucket on every match, reintroducing O(n^2) cost.
                $familyTaken = &$takenByFamily[$rule['family']];
                if ($this->familyOverlaps($familyTaken, $start, $end)) {
                    continue;
                }

                $contentStart = $match[1][1];
                $contentEnd = $contentStart + strlen($match[1][0]);

                $this->insertInterval($familyTaken, $start, $end);
                // Replace only the delimiters; leave inner bytes untouched.
                $edits[] = [$start, $contentStart, $rule['open']];
                $edits[] = [$contentEnd, $end, $rule['close']];
            }
            // Break the reference into the bucket so a later iteration cannot
            // accidentally clobber it via the still-bound $familyTaken alias.
            unset($familyTaken);
        }

        usort($edits, fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$editStart, $editEnd, $replacement]) {
            $source = substr($source, 0, $editStart) . $replacement . substr($source, $editEnd);
        }

        return $this->collapseFalseListBoundaries($this->normalizePlusBullets($source, $masked));
    }

    private function djotEmphasisMask(string $source, bool $attributes = true): string
    {
        $masked = $this->maskCodeAndDestinations($source);
        $previousLines = $this->previousSourceLines($source);
        $masked = preg_replace_callback('/<[^<>\s]+>/', static fn (array $match): string => preg_match('/[^:]@|[A-Za-z]:/', $match[0]) === 1 ? str_repeat(' ', strlen($match[0])) : $match[0], $masked) ?? $masked;
        $masked = preg_replace_callback('/\[\^[^\]\n]*\]|(?<=\])\[[^\]\n]*\]/m', static fn (array $match): string => str_repeat(' ', strlen($match[0])), $masked) ?? $masked;
        $masked = preg_replace_callback('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9]+[.)])[ \t]+)?\[(?!\^)[^\]\n]*\]:[^\n]*/m', function (array $match) use ($previousLines): string {
            [$value, $at] = $match[0];
            $previous = $previousLines[$at] ?? '';
            $previous = trim(preg_replace('/^[ \t]*(?:>[ \t]*)*/', '', $previous) ?? '');

            return $previous === '' || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{[.#A-Za-z]|\[(?!\^)[^\]]*\]:)/', $previous) === 1 ? $this->blanks($value) : $value;
        }, $masked, -1, $referenceCount, PREG_OFFSET_CAPTURE) ?? $masked;
        $masked = preg_replace_callback('/!\[[^\[\]\n]*\](?=[([])/', fn (array $match): string => $this->isDjotEscaped($source, $match[0][1]) || $this->isDjotEscaped($source, $match[0][1] + strlen($match[0][0]) - 1) ? $match[0][0] : $this->blanks($match[0][0]), $masked, -1, $imageCount, PREG_OFFSET_CAPTURE) ?? $masked;
        for ($i = 0, $length = strlen($source); $attributes && $i < $length; $i++) {
            if ($masked[$i] !== '{' || preg_match('/[.#A-Za-z]/', $source[$i + 1] ?? '') !== 1) {
                continue;
            }
            $attrs = $this->readDjotWordAttributes($source, $i);
            if ($attrs === null) {
                continue;
            }
            for ($at = $i; $at < $attrs['end']; $at++) {
                if ($masked[$at] !== "\n") {
                    $masked[$at] = ' ';
                }
            }
            $i = $attrs['end'] - 1;
        }

        return $masked;
    }

    private function foldHeadingContinuations(string $source): string
    {
        $lines = explode("\n", $source);
        $masked = explode("\n", $this->maskCodeAndDestinations($source));
        $block = '/^(?:[#>|{]|[-*+][ \t]|[0-9]+[.)][ \t]|:[ \t]|:{2,}|\([0-9a-zA-Z]+\)[ \t]|[`~]{3,}|\^[ \t]|%{3,}|\[[^\]\n]*\]:|(?:\*[ \t]*){3,}$|(?:-[ \t]*){3,}$)/';
        $result = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (($i > 0 && trim($lines[$i - 1]) !== '') || ($masked[$i][0] ?? '') !== '#' || preg_match('/^(#{1,6}) +\S/', $line, $heading) !== 1) {
                $result[] = $line;

                continue;
            }
            $prefix = $heading[1] . ' ';
            while ($i + 1 < $count) {
                if ((strlen($line) - strlen(rtrim($line, '\\'))) % 2 === 1) {
                    break;
                }
                $next = ltrim($lines[$i + 1], " \t");
                if (str_starts_with($next, $prefix)) {
                    $part = ltrim(substr($next, strlen($prefix)), ' ');
                    if (trim($part) === '') {
                        break;
                    }
                } else {
                    if (trim($next) === '' || preg_match($block, $next) === 1) {
                        break;
                    }
                    $part = $next;
                }
                $line .= ' ' . $part;
                $i++;
            }
            $result[] = $line;
        }

        return implode("\n", $result);
    }

    /**
     * @param string $source
     * @param array<string, string> $spans
     */
    private function protectAttributedStrong(string $source, array &$spans): string
    {
        if (!str_contains($source, '{')) {
            return $source;
        }
        $masked = $this->maskCodeAndDestinations($source);
        $attribute = '\{(?:\s*(?:[.#][^\s{}"=]+|[\w:-]+=(?:"(?:\\\\.|[^"\\\\])*"|[^\s{}"]+)))+\s*\}';
        $pattern = '~(?<![\\\\*])\*(?![\s*])([^*\n{}]+)(' . $attribute . ')([^*\n{}]*)(?<!\s)\*(?!\*)~u';
        $prefix = "\0DJOTSTRONG";
        while (str_contains($source, $prefix)) {
            $prefix .= "\0";
        }

        $token = "\0DJOTATTR\0";
        while (str_contains($source, $token)) {
            $token .= "\0";
        }

        return preg_replace_callback($pattern, function (array $match) use ($masked, $token, $prefix, &$spans): string {
            if (
                ($masked[$match[0][1]] ?? '') !== '*'
                || ($masked[$match[0][1] + strlen($match[0][0]) - 1] ?? '') !== '*'
                || str_ends_with($match[3][0], '\\')
            ) {
                return $match[0][0];
            }
            if (!preg_match('/[^\s*{}\[\]`_~^]+$/u', $match[1][0], $word, PREG_OFFSET_CAPTURE)) {
                return $match[0][0];
            }
            $body = $this->convert(substr($match[1][0], 0, $word[0][1]) . '[' . $word[0][0] . ']' . $token . $match[3][0]);
            $key = $prefix . count($spans) . "\0";
            $spans[$key] = '{*' . str_replace($token, $match[2][0], $body) . '*}';

            return $key;
        }, $source, flags: PREG_OFFSET_CAPTURE) ?? $source;
    }

    /**
     * @return array{end: int, source: string}|null
     */
    private function readDjotWordAttributes(string $source, int $start): ?array
    {
        $parts = [];
        $length = strlen($source);
        $i = $start + 1;
        $quoteValue = static fn (string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        while ($i < $length) {
            while ($i < $length && str_contains(" \t\n\r", $source[$i])) {
                if ($source[$i] === "\n" && preg_match('/\G[ \t]*\n/', $source, offset: $i + 1) === 1) {
                    return null;
                }
                $i++;
            }
            if (($source[$i] ?? '') === '}') {
                return $parts !== [] ? ['end' => $i + 1, 'source' => '{' . implode(' ', $parts) . '}'] : null;
            }
            if (($source[$i] ?? '') === '%') {
                $end = $i + 1;
                while ($end < $length && $source[$end] !== '%' && $source[$end] !== '}') {
                    $end++;
                }
                if ($end === $length || preg_match('/\n[ \t]*\n/', substr($source, $i, $end - $i)) === 1) {
                    return null;
                }
                $i = $source[$end] === '%' ? $end + 1 : $end;

                continue;
            }
            if (($source[$i] ?? '') === '#' || ($source[$i] ?? '') === '.') {
                $kind = $source[$i++];
                $from = $i;
                while ($i < $length && preg_match('/[\s{}%"\'=<>]/', $source[$i]) !== 1) {
                    $i++;
                }
                if ($i === $from) {
                    return null;
                }
                $value = substr($source, $from, $i - $from);
                $parts[] = preg_match('/^[A-Za-z0-9_][\w-]*$/', $value) === 1
                    ? $kind . $value
                    : ($kind === '#' ? 'id' : 'class') . '=' . $quoteValue($value);
            } else {
                if (preg_match('/\G[A-Za-z][A-Za-z0-9_-]*=/', $source, $key, offset: $i) !== 1) {
                    return null;
                }
                $i += strlen($key[0]);
                $from = $i;
                if (($source[$i] ?? '') === '"') {
                    $i++;
                    while ($i < $length && $source[$i] !== '"') {
                        if ($source[$i] === "\n" || $source[$i] === "\r") {
                            return null;
                        }
                        if ($source[$i] === '\\') {
                            $i++;
                        }
                        $i++;
                    }
                    if (($source[$i] ?? '') !== '"') {
                        return null;
                    }
                    $i++;
                    $parts[] = $key[0] . substr($source, $from, $i - $from);
                } else {
                    while ($i < $length && preg_match('/[\s{}%"\'=<>]/', $source[$i]) !== 1) {
                        $i++;
                    }
                    if ($i === $from) {
                        return null;
                    }
                    $parts[] = $key[0] . $quoteValue(substr($source, $from, $i - $from));
                }
            }
            if ($i < $length && preg_match('/[\s}%]/', $source[$i]) !== 1) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param string $source
     * @param array<string, string> $spans
     */
    private function protectAttributedWords(string $source, array &$spans): string
    {
        $masked = $this->maskCodeAndDestinations($source);
        $prefix = "\0DJOTWORD";
        while (str_contains($source, $prefix)) {
            $prefix .= "\0";
        }
        $output = '';
        $cursor = 0;
        $lastClose = strrpos($source, '}');
        $lastDelimiters = [];
        foreach (str_split('_*~^') as $delimiter) {
            $lastDelimiters[$delimiter] = strrpos($source, $delimiter);
        }
        for ($i = 0; $lastClose !== false && $i <= $lastClose; $i++) {
            if ($source[$i] !== '{' || $masked[$i] !== '{') {
                continue;
            }
            $slashes = 0;
            for ($at = $i - 1; $at >= 0 && $source[$at] === '\\'; $at--) {
                $slashes++;
            }
            if ($slashes % 2 !== 0) {
                continue;
            }
            $attrs = $this->readDjotWordAttributes($source, $i);
            if ($attrs === null) {
                continue;
            }
            $start = $i;
            if ($i > 0 && $masked[$i - 1] === $source[$i - 1] && !str_contains('`*_~^]}>', $source[$i - 1])) {
                while ($start > $cursor && $masked[$start - 1] === $source[$start - 1] && !str_contains(" \t\n\r\v\f\"'{}[]`\0>|", $source[$start - 1])) {
                    $start--;
                }
                if ($start < $i && preg_match('/[^\s]+$/u', substr($source, $start, $i - $start), $word, PREG_OFFSET_CAPTURE) === 1) {
                    $start += $word[0][1];
                } else {
                    $start = $i;
                }
                $closer = $source[$attrs['end']] ?? '';
                if ($closer !== '' && str_contains('_*~^', $closer)) {
                    for ($at = $i - 1; $at >= $start; $at--) {
                        if ($source[$at] !== $closer) {
                            continue;
                        }
                        $escapes = 0;
                        for ($back = $at - 1; $back >= 0 && $source[$back] === '\\'; $back--) {
                            $escapes++;
                        }
                        if ($escapes % 2 === 0) {
                            $start = $at + 1;

                            break;
                        }
                    }
                } elseif ($start < $i && str_contains('_*~^', $source[$start]) && $lastDelimiters[$source[$start]] >= $attrs['end']) {
                    $start = $i;
                }
                if ($start > 0 && $source[$start - 1] === '{' && str_contains('+-=', $source[$start] ?? '')) {
                    $start++;
                }
            }
            if ($start < $i) {
                $token = $prefix . count($spans) . "\0";
                $body = substr($this->convert('x ' . substr($source, $start, $i - $start)), 2);
                if (str_starts_with($body, '^')) {
                    $body = '\\' . $body;
                }
                $spans[$token] = '[' . $body . ']' . $attrs['source'];
                $output .= substr($source, $cursor, $start - $cursor) . $token;
                $cursor = $attrs['end'];
            }
            $i = $attrs['end'] - 1;
        }

        return $output . substr($source, $cursor);
    }

    public function convertWithFidelityReport(string $djot): MigrationResult
    {
        return $this->assessedMigrationResult($djot, $this->convert($djot), 'djot');
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function splitSiteFrontmatter(string $source): array
    {
        $lines = explode("\n", $source);
        if (!preg_match('/^--- ?\w*[ \t]*$/', $lines[0])) {
            return ['', '', $source];
        }
        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            if (preg_match('/^---[ \t]*$/', $lines[$i])) {
                $frontmatter = implode("\n", array_slice($lines, 0, $i + 1));
                $offset = strlen($frontmatter);
                $separator = str_starts_with(substr($source, $offset), "\n\n")
                    ? "\n\n"
                    : (str_starts_with(substr($source, $offset), "\n") ? "\n" : '');

                return [$frontmatter, $separator, substr($source, $offset + strlen($separator))];
            }
        }

        return ['', '', $source];
    }

    /**
     * Rewrite Djot block spellings that Carve does not recognize.
     */
    private function convertDjotBlockMarkers(string $source): string
    {
        $lines = explode("\n", $source);
        $maskedLines = explode("\n", $this->maskCodeAndDestinations($source));
        foreach ($lines as $i => $line) {
            $masked = $maskedLines[$i] ?? $line;
            if (trim($masked) === '') {
                continue;
            }
            if (preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)\(([0-9A-Za-z]+)\)([ \t]+\S.*)$/', $masked, $match)) {
                if (!preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)\(([0-9A-Za-z]+)\)([ \t]+\S.*)$/', $line, $authored)) {
                    continue;
                }
                [$columns] = $this->leadingIndent($match[2]);
                $indent = $this->isNestedBlock($maskedLines, $i, $match[1], $columns) ? $authored[2] : '';
                $lines[$i] = $authored[1] . $indent . $authored[3] . '.' . $authored[4];

                continue;
            }
            if (!preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)([*-])(?:[ \t]*\3){2,}[ \t]*$/', $masked, $rule)) {
                continue;
            }
            [$columns] = $this->leadingIndent($rule[2]);
            $indent = $this->isNestedBlock($maskedLines, $i, $rule[1], $columns) ? $rule[2] : '';
            $lines[$i] = $rule[1] . $indent . '***';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     * @param int $line
     * @param string $quote
     * @param int $columns
     */
    private function isNestedBlock(array $lines, int $line, string $quote, int $columns): bool
    {
        for ($i = $line - 1; $i >= 0; $i--) {
            if (!str_starts_with($lines[$i], $quote)) {
                break;
            }
            $candidate = substr($lines[$i], strlen($quote));
            if (trim($candidate) === '') {
                continue;
            }
            [$candidateColumns] = $this->leadingIndent($candidate);
            if ($candidateColumns >= $columns) {
                continue;
            }
            if (preg_match('/^(?:([*-])[ \t]*){3,}$/', trim($candidate))) {
                return false;
            }

            return (bool)preg_match('/^(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+\S/', ltrim($candidate));
        }

        return false;
    }

    /**
     * Translate Djot's list-item definition shape to Carve's term/body markers.
     */
    private function convertDefinitionLists(string $source, string $emptyTerm): string
    {
        $lines = explode("\n", $source);
        $maskedLines = explode("\n", $this->maskCode($source));
        /** @var list<array{source: int, target: int, body: bool, ready: bool}> $stack */
        $stack = [];

        foreach ($lines as $i => $line) {
            $masked = $maskedLines[$i] ?? $line;
            $fenceCandidate = preg_match('/^([ \t]*):[ \t]+(`{3,}|~{3,})(.*)$/', $line, $fenceParts) === 1;
            $rawFence = $fenceCandidate && !($fenceParts[2][0] === '`' && str_contains($fenceParts[3], '`'));
            $termLine = $fenceCandidate && str_starts_with(ltrim($masked), ':') ? $line : $masked;
            if (preg_match('/^([ \t]*):[ \t]+(\S.*)$/', $termLine, $term, PREG_OFFSET_CAPTURE)) {
                [$indent] = $this->leadingIndent($term[1][0]);
                while ($stack !== [] && $indent < $stack[array_key_last($stack)]['source']) {
                    array_pop($stack);
                }
                $top = $stack === [] ? null : $stack[array_key_last($stack)];
                $mayStart = $top !== null || $i === 0 || trim($lines[$i - 1]) === '';
                if ($mayStart) {
                    $termText = substr($line, $term[2][1]);
                    if ($top === null || $indent === $top['source']) {
                        $target = $top['target'] ?? $indent;
                        $startsList = $top === null;
                        if ($top === null) {
                            $stack[] = ['source' => $indent, 'target' => $target, 'body' => false, 'ready' => false];
                        } else {
                            $stack[array_key_last($stack)] = [
                                'source' => $top['source'],
                                'target' => $top['target'],
                                'body' => false,
                                'ready' => false,
                            ];
                        }
                        $prefix = str_repeat(' ', $target);
                        $lines[$i] = ($startsList ? $prefix . "{loose}\n" : '') . $prefix . ':: ' . ($rawFence ? $emptyTerm : $termText);
                        if ($rawFence) {
                            $lines[$i] .= "\n" . $prefix . ':  ' . $termText;
                            $frame = array_key_last($stack);
                            $stack[$frame] = ['source' => $indent, 'target' => $target, 'body' => true, 'ready' => true];
                        }

                        continue;
                    }
                    if ($top['ready'] && $indent >= $top['source'] + 2) {
                        $target = $top['target'] + 3;
                        $parentIndex = array_key_last($stack);
                        $lead = $top['body'] ? str_repeat(' ', $target) : str_repeat(' ', $top['target']) . ':  ';
                        $stack[$parentIndex] = [
                            'source' => $top['source'],
                            'target' => $top['target'],
                            'body' => true,
                            'ready' => $top['ready'],
                        ];
                        $lines[$i] = $lead . "{loose}\n" . str_repeat(' ', $target) . ':: ' . $termText;
                        $stack[] = ['source' => $indent, 'target' => $target, 'body' => false, 'ready' => false];

                        continue;
                    }
                }
            }
            if ($stack === []) {
                continue;
            }
            if (trim($line) === '') {
                $currentIndex = array_key_last($stack);
                $current = $stack[$currentIndex];
                $stack[$currentIndex] = [
                    'source' => $current['source'],
                    'target' => $current['target'],
                    'body' => $current['body'],
                    'ready' => true,
                ];

                continue;
            }
            if (!$stack[array_key_last($stack)]['ready']) {
                continue;
            }
            [$indent] = $this->leadingIndent($line);
            while ($stack !== [] && $indent < $stack[array_key_last($stack)]['source'] + 2) {
                array_pop($stack);
            }
            if ($stack === []) {
                continue;
            }
            $contextIndex = array_key_last($stack);
            $context = $stack[$contextIndex];
            $payload = substr($line, $this->bytesThroughColumns($line, $context['source'] + 2));
            $extra = max(0, $indent - ($context['source'] + 2));
            $lines[$i] = $context['body']
                ? str_repeat(' ', $context['target'] + 3 + $extra) . $payload
                : str_repeat(' ', $context['target']) . ':  ' . str_repeat(' ', $extra) . $payload;
            $stack[$contextIndex] = [
                'source' => $context['source'],
                'target' => $context['target'],
                'body' => true,
                'ready' => $context['ready'],
            ];
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function consumeOrphanDjotAttributes(string $source): array
    {
        $maskedSource = preg_replace_callback('/<[A-Za-z][A-Za-z0-9+.-]*:[^<>\\s]*>/', static fn (array $match): string => str_repeat(' ', strlen($match[0])), $this->maskCodeAndDestinations($source)) ?? $source;
        $masked = explode("\n", $maskedSource);
        $item = '(?:[.#][A-Za-z0-9_][A-Za-z0-9_-]*|[A-Za-z][A-Za-z0-9_-]*=(?:"(?:\\\\.|[^"\\\\\n])*"|[A-Za-z0-9_:-]+))';
        $pattern = '/\{[ \t]*' . $item . '(?:[ \t]+' . $item . ')*[ \t]*\}/';
        $lines = explode("\n", $source);
        $prefix = "\x00DJOTORPHAN\x00";
        while (str_contains($source, $prefix)) {
            $prefix .= "\x00";
        }
        $out = [];
        $spaces = [];
        foreach ($lines as $index => $line) {
            preg_match('/^(?:(?:[ \t]*>)+[ \t]*)?[ \t]*/', $line, $container);
            $first = strlen($container[0] ?? '');
            $last = strlen(rtrim($line));
            preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE);
            $written = '';
            $cursor = 0;
            $dropLine = false;
            $strippedLine = preg_replace($pattern, '', $line) ?? $line;
            $markerOnly = preg_match('/^(?:(?:[ \t]*>)+[ \t]*)?[ \t]*(?:[-*+]|[0-9]+[.)]|#{1,6}|:{1,2}|\[\^[^\]]+\]:)[ \t]+(?:\[[ xX-]\][ \t]+)?$/', $strippedLine) === 1;
            foreach ($matches[0] as [$attrs, $at]) {
                $before = $at;
                while ($before > 0 && $line[$before - 1] === '\\') {
                    $before--;
                }
                if ($masked[$index][$at] !== '{' || ($at - $before) % 2 !== 0) {
                    continue;
                }
                if ($at > 0 && $at !== $cursor && (str_contains(']*_}^~', $line[$at - 1]) || ($masked[$index][$at - 1] === ' ' && $line[$at - 1] !== ' '))) {
                    continue;
                }
                if ($markerOnly) {
                    continue;
                }
                $alone = $at === $first && $at + strlen($attrs) === $last;
                $previous = trim(preg_replace('/^(?:(?:[ \t]*>)+[ \t]*)?/', '', $lines[$index - 1] ?? '') ?? '');
                if ($alone && trim($lines[$index + 1] ?? '') !== '' && ($index === 0 || $previous === '' || preg_match('/^\{.*\}$/', $previous) === 1 || preg_match('/^(?:`{3,}|~{3,}|:{3,}|#{1,6} |[-*+] |[0-9]+[.)] |> |:{1,2} |(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|\|.*\||\[[^\]]+\]:)/', $previous) === 1)) {
                    continue;
                }
                $dropLine = $dropLine || $alone;
                $written .= substr($line, $cursor, $at - $cursor) . ($prefix . ($at === $first ? 'L' : 'I'));
                $cursor = $at + strlen($attrs);
            }
            if ($dropLine) {
                continue;
            }
            $written .= substr($line, $cursor);
            $out[] = preg_replace_callback('/' . preg_quote($prefix, '/') . '([LI])([ \t]*)/', static function (array $match) use (&$spaces, $prefix): string {
                $token = $prefix . count($spaces) . "\x00";
                $spaces[$token] = $match[1] === 'I' ? $match[2] : ($match[2] === '' ? '' : '!`' . $match[2] . '`');

                return $token;
            }, $written) ?? $written;
        }

        return [implode("\n", $out), $spaces];
    }

    /**
     * @return array{0: int, 1: int} visual columns and bytes
     */
    private function leadingIndent(string $line): array
    {
        $columns = 0;
        $bytes = 0;
        $length = strlen($line);
        while ($bytes < $length && ($line[$bytes] === ' ' || $line[$bytes] === "\t")) {
            $columns = $line[$bytes] === "\t" ? $columns + (4 - ($columns % 4)) : $columns + 1;
            $bytes++;
        }

        return [$columns, $bytes];
    }

    private function bytesThroughColumns(string $line, int $wanted): int
    {
        $columns = 0;
        $bytes = 0;
        $length = strlen($line);
        while ($bytes < $length && $columns < $wanted && ($line[$bytes] === ' ' || $line[$bytes] === "\t")) {
            $columns = $line[$bytes] === "\t" ? $columns + (4 - ($columns % 4)) : $columns + 1;
            $bytes++;
        }

        return $bytes;
    }

    private function escapeInvalidAttributeHashes(string $source): string
    {
        $masked = preg_replace_callback('~<[A-Za-z][A-Za-z0-9+.-]*:[^<>\s]*>~', static fn (array $match): string => str_repeat(' ', strlen($match[0])), $this->maskCodeAndDestinations($source)) ?? $source;
        $escapes = [];
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] !== '{' || $masked[$i] !== '{') {
                continue;
            }
            $slashes = 0;
            for ($before = $i - 1; $before >= 0 && $source[$before] === '\\'; $before--) {
                $slashes++;
            }
            if ($slashes % 2 !== 0) {
                continue;
            }
            $quote = false;
            $comment = false;
            $invalid = false;
            for ($end = $i + 1; $end < $length; $end++) {
                $char = $source[$end];
                if ($char === "\n" && preg_match('/\G[ \t]*\n/', $source, offset: $end + 1) === 1) {
                    break;
                }
                if ($quote) {
                    if ($char === '\\' && ($source[$end + 1] ?? '') !== "\n") {
                        $end++;
                    } elseif ($char === '"') {
                        $quote = false;
                    }

                    continue;
                }
                if ($masked[$end] !== $source[$end]) {
                    $invalid = true;

                    continue;
                }
                if ($char === '\\') {
                    $invalid = true;
                    if (($source[$end + 1] ?? '') !== "\n") {
                        $end++;
                    }

                    continue;
                }
                if ($char === '}') {
                    break;
                }
                if ($char === '%') {
                    $comment = !$comment;

                    continue;
                }
                if ($comment) {
                    continue;
                }
                if ($char === '"') {
                    $quote = true;

                    continue;
                }
                if ($char === '{') {
                    break;
                }
                if ($char === '<' || $char === '>') {
                    $invalid = true;
                }
            }
            if (($source[$i + 1] ?? '') === '#' && (($source[$end] ?? '') !== '}' || $invalid)) {
                $escapes[] = $i;
            }
            $i = max($i, $end - (($source[$end] ?? '') === '{' ? 1 : 0));
        }
        $output = '';
        $cursor = 0;
        foreach ($escapes as $at) {
            $output .= substr($source, $cursor, $at - $cursor) . '\\{\\#';
            $cursor = $at + 2;
        }

        return $output . substr($source, $cursor);
    }

    protected function escapePlainDjotText(string $source, string $masked): string
    {
        $result = '';
        $plain = '';
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            if ($masked[$i] === ' ' && $source[$i] !== "\n") {
                if ($plain !== '') {
                    $result .= $this->escapePlainCarveInlineSyntax($plain, self::HANDLED_DJOT);
                    $plain = '';
                }
                $result .= $source[$i];

                continue;
            }

            $plain .= $source[$i];
        }

        if ($plain !== '') {
            $result .= $this->escapePlainCarveInlineSyntax($plain, self::HANDLED_DJOT);
        }

        return $result;
    }

    /**
     * Rewrite Djot `+` bullet markers to `-`.
     *
     * Djot allows `-`, `*` and `+` as bullets; Carve does not have a `+` bullet
     * (it is the list-continuation marker), so a Djot `+` list would otherwise
     * convert to a plain paragraph. The code mask is used to skip lines inside
     * fenced blocks. Inline delimiter edits never cross newlines, so the masked
     * string stays line-aligned with the edited source.
     *
     * @param string $source The delimiter-converted source
     * @param string $masked The code-masked original (line-aligned)
     *
     * @return string
     */
    protected function normalizePlusBullets(string $source, string $masked): string
    {
        $lines = explode("\n", $source);
        $maskedLines = explode("\n", $masked);
        $continuationLines = preg_match('/^[ \t]*\+[ \t][^\n]*\|/m', $masked)
            ? $this->tableContinuationLines($source)
            : [];
        foreach ($lines as $i => $line) {
            if (!isset($maskedLines[$i]) || !preg_match('/^(\s*)\+(\s)/', $maskedLines[$i])) {
                continue;
            }
            if (isset($continuationLines[$i + 1])) {
                continue;
            }
            $lines[$i] = preg_replace('/^(\s*)\+(\s)/', '$1-$2', $line) ?? $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, true> One-based lines consumed after a table row's opener
     */
    private function tableContinuationLines(string $source): array
    {
        $lines = [];
        $visit = function (Node $node) use (&$visit, &$lines): void {
            if ($node instanceof TableRow) {
                $pos = $node->getPos();
                if ($pos !== null) {
                    for ($line = $pos->startLine + 1; $line <= $pos->endLine; $line++) {
                        $lines[$line] = true;
                    }
                }
            }
            foreach ($node->getChildren() as $child) {
                $visit($child);
            }
        };
        $visit((new BlockParser(trackPositions: true))->parse($source));

        return $lines;
    }

    /**
     * Does [start, end) overlap any interval in a sorted, disjoint list?
     *
     * Binary-searches for the last interval starting at or before `start` and
     * checks it plus its successor (the only two that can overlap a disjoint
     * sorted set), so the check is O(log n) rather than O(n).
     *
     * @param array<array{0: int, 1: int}> $sorted intervals sorted by start, disjoint
     * @param int $end
     * @param int $start
     */
    protected function familyOverlaps(array $sorted, int $start, int $end): bool
    {
        $count = count($sorted);
        if ($count === 0) {
            return false;
        }

        // Largest index whose interval start <= $start.
        $lo = 0;
        $hi = $count - 1;
        $idx = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($sorted[$mid][0] <= $start) {
                $idx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        // Predecessor (starts at/before $start): overlaps iff it ends after $start.
        if ($idx >= 0 && $sorted[$idx][1] > $start) {
            return true;
        }

        // Successor (starts after $start): overlaps iff it starts before $end.
        $next = $idx + 1;

        return $next < $count && $sorted[$next][0] < $end;
    }

    /**
     * Insert [start, end) into a sorted-by-start interval list, preserving order.
     *
     * @param array<array{0: int, 1: int}> $sorted
     * @param int $end
     * @param int $start
     */
    protected function insertInterval(array &$sorted, int $start, int $end): void
    {
        $count = count($sorted);
        // Fast path: appending in source order (the common case) is O(1).
        if ($count === 0 || $sorted[$count - 1][0] <= $start) {
            $sorted[] = [$start, $end];

            return;
        }

        $lo = 0;
        $hi = $count;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($sorted[$mid][0] < $start) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        array_splice($sorted, $lo, 0, [[$start, $end]]);
    }

    /**
     * Replace every code character (fenced blocks, inline code spans) and
     * link/image destinations with spaces, preserving newlines so byte offsets
     * stay aligned with the original source.
     */
    protected function maskCode(string $source): string
    {
        // Stage 4: constructs whose inner delimiters already mean something
        // else in Carve/Djot and must not be migrated again. Math spans need
        // no handling here: both languages write math as `$` plus a code
        // span, which stage 1 code masking already protects.
        return $this->maskProtectedInlineForms($this->maskCodeAndDestinations($source));
    }

    /**
     * Stages 1 to 3 alone: code and destinations masked, the protected inline
     * forms left visible.
     *
     * The escape pass needs this narrower mask. It runs BEFORE any Carve form
     * exists in the source, so masking those forms would hide the plain text it
     * has to escape.
     */
    protected function maskCodeAndDestinations(string $source): string
    {
        // Stage 1: fenced blocks, line by line.
        $lines = explode("\n", $source);
        $heldFence = null;
        $previousBlock = true;
        $ancestors = [];
        foreach ($lines as $i => $line) {
            [$depth, $content] = $this->quoted($line);
            $nested = false;
            $ancestors = array_slice($ancestors, 0, $depth + 1);
            if (trim($content) !== '') {
                $view = $line;
                for ($level = 0; $level <= $depth; $level++) {
                    $ancestors[$level] ??= [];
                    $indent = strlen($view) - strlen(ltrim($view, " \t"));
                    while ($ancestors[$level] !== [] && $ancestors[$level][array_key_last($ancestors[$level])]['indent'] >= $indent) {
                        array_pop($ancestors[$level]);
                    }
                    if ($level === $depth) {
                        $nested = $ancestors[$level] !== [] && $ancestors[$level][array_key_last($ancestors[$level])]['marker'];
                    }
                    $marker = preg_match('/^(?:([*-])[ \t]*){3,}$/', trim($view)) !== 1 && preg_match('/^[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+\S/', $view) === 1;
                    $ancestors[$level][] = ['indent' => $indent, 'marker' => $marker];
                    if ($level < $depth && preg_match('/^[ \t]*>[ ]?/', $view, $prefix) === 1) {
                        $view = substr($view, strlen($prefix[0]));
                    }
                }
            }
            if ($heldFence !== null && trim($content) !== '' && $depth < $heldFence['depth']) {
                $heldFence = null;
            }
            if ($heldFence !== null && $heldFence['container'] !== null && trim($content) !== '' && strlen($content) - strlen(ltrim($content, " \t")) < $heldFence['container'] && $depth === $heldFence['depth']) {
                $heldFence = null;
            }
            if ($heldFence !== null) {
                $lines[$i] = $this->blanks($line);
                if ($depth === $heldFence['depth'] && preg_match('/^[ \t]{0,' . $heldFence['indent'] . '}(' . $heldFence['char'] . '{' . $heldFence['length'] . ',})[ \t]*$/', $content) === 1) {
                    $heldFence = null;
                    $previousBlock = true;
                }

                continue;
            }
            if (preg_match('/^([ \t]*)(?:(:[ \t]+|[-*+][ \t]+|[0-9]+[.)][ \t]+))?(`{3,}|~{3,})[ \t]*=?[A-Za-z0-9_+#.-]*[ \t]*$/', $content, $open) === 1) {
                if (str_starts_with($open[2], ':') && !$previousBlock) {
                    continue;
                }
                $container = $open[2] !== '' ? strlen($open[1]) + strlen($open[2]) : ($open[1] !== '' && $nested ? strlen($open[1]) : null);
                $heldFence = ['indent' => $open[2] !== '' ? $container : max(3, strlen($open[1])), 'container' => $container, 'char' => $open[3][0], 'length' => strlen($open[3]), 'depth' => $depth];
                $start = strpos($line, $open[3]);
                if ($start === false) {
                    continue;
                }
                $lines[$i] = substr($line, 0, $start) . $this->blanks(substr($line, $start));
            } else {
                $previousBlock = trim($content) === '' || preg_match('/^[ \t]*(?:[-*+] |[0-9]+[.)] |:{1,2} |#{1,6} |\{[.#A-Za-z])/', $content) === 1;
            }
        }
        $masked = implode("\n", $lines);

        // Stage 2: inline code spans. A run of N backticks closes at the next run of exactly N.
        $length = strlen($masked);
        preg_match_all('/\n[ \t]*\n/', $masked, $paragraphBreaks, PREG_OFFSET_CAPTURE);
        $paragraphEnds = array_column($paragraphBreaks[0], 1);
        $paragraphIndex = 0;
        $i = 0;
        while ($i < $length) {
            if ($masked[$i] !== '`') {
                $i++;

                continue;
            }
            $run = $this->backtickRun($masked, $i);
            while (isset($paragraphEnds[$paragraphIndex]) && $paragraphEnds[$paragraphIndex] <= $i) {
                $paragraphIndex++;
            }
            $paragraphEnd = $paragraphEnds[$paragraphIndex] ?? $length;
            $j = $i + $run;
            $closed = -1;
            while ($j < $paragraphEnd) {
                if ($masked[$j] === '`' && $this->backtickRun($masked, $j) === $run) {
                    $closed = $j;

                    break;
                }
                $j++;
            }
            if ($closed === -1) {
                for ($k = $i; $k < $paragraphEnd; $k++) {
                    if ($masked[$k] !== "\n") {
                        $masked[$k] = ' ';
                    }
                }
                $i = $paragraphEnd;

                continue;
            }
            for ($k = $i; $k < $closed + $run; $k++) {
                if ($masked[$k] !== "\n") {
                    $masked[$k] = ' ';
                }
            }
            $i = $closed + $run;
        }

        // Stage 3: link/image destinations.
        $masked = preg_replace_callback(
            '/(?<=\])\([^()\n]*\)/',
            fn (array $group): string => $this->blanks($group[0]),
            $masked,
        );

        $previousLines = $this->previousSourceLines($source);
        $masked = preg_replace_callback('/^(?:[ \t]*>)*[ \t]*(?:(?:[-*+]|[0-9]+[.)])[ \t]+)?:{3,}[ \t]+([A-Za-z_][A-Za-z0-9_.-]*)/m', function (array $match) use ($previousLines): string {
            [$value, $at] = $match[0];
            $name = $match[1][0];

            return trim($previousLines[$at] ?? '') === '' || preg_match('/(?:[-*+]|[0-9]+[.)])[ \t]+:{3,}/', $value) === 1 ? substr($value, 0, -strlen($name)) . $this->blanks($name) : $value;
        }, $masked ?? $source, -1, $classCount, PREG_OFFSET_CAPTURE);

        return $masked ?? $source;
    }

    /**
     * Which lines belong to a fenced block, opener and closer included?
     *
     * Shared by the code mask and the blank-run pass. The mask cannot answer
     * this question after the fact: it replaces fence content with SPACES and
     * keeps the newlines, so a masked code line and a blank line look the same.
     * Anything that has to reason about blankness must consult this map first.
     *
     * Every line is read THROUGH its block-quote prefix. A fence written inside
     * a quote starts its line with the quote marker, not with the delimiter
     * run, so a test on the raw line recognizes neither the opener nor the
     * closer and reports the whole block as ordinary text - and the blank-run
     * pass then rewrites lines that are a code block's own content.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, bool>
     */
    protected function fencedLineMap(array $lines): array
    {
        $fenced = [];
        $fenceChar = null;
        $fenceLen = 0;
        $fenceIndent = 0;
        $fenceContainer = null;
        foreach ($lines as $i => $line) {
            [, $content] = $this->quoted($line);
            if ($fenceChar !== null && $fenceContainer !== null && trim($content) !== '' && strlen($content) - strlen(ltrim($content, " \t")) < $fenceContainer) {
                $fenceChar = null;
            }
            if ($fenceChar !== null) {
                if (
                    preg_match('/^[ \t]{0,' . $fenceIndent . '}([`~]{3,})[ \t]*$/', $content, $close)
                    && $close[1][0] === $fenceChar
                    && strlen($close[1]) >= $fenceLen
                ) {
                    $fenceChar = null;
                    $fenceLen = 0;
                }
                $fenced[$i] = true;

                continue;
            }
            if (preg_match('/^([ \t]*)(?::[ \t]+)?(`{3,}|~{3,})[ \t]*=?[a-zA-Z0-9_+#.-]*[ \t]*$/', $content, $open)) {
                $fenceChar = $open[2][0];
                $fenceLen = strlen($open[2]);
                $fenceContainer = str_contains($content, ':') ? strlen($open[1]) + 2 : null;
                $fenceIndent = $fenceContainer ?? max(3, strlen($open[1]));
                $fenced[$i] = true;

                continue;
            }
            $fenced[$i] = false;
        }

        return $fenced;
    }

    /**
     * Collapse a blank-line run that only Carve reads as a list boundary.
     */
    protected function collapseFalseListBoundaries(string $source): string
    {
        $lines = explode("\n", $source);
        $fenced = $this->fencedLineMap($lines);
        $count = count($lines);

        /** @var array<int, int> $depth */
        $depth = [];
        /** @var array<int, string> $content */
        $content = [];
        foreach ($lines as $i => $line) {
            [$depth[$i], $content[$i]] = $this->quoted($line);
        }

        $result = [];
        for ($i = 0; $i < $count; $i++) {
            if ($fenced[$i] || trim($content[$i]) !== '') {
                $result[] = $lines[$i];

                continue;
            }

            $here = $depth[$i];
            $end = $i;
            while (
                $end + 1 < $count
                && !$fenced[$end + 1]
                && trim($content[$end + 1]) === ''
                && $depth[$end + 1] === $here
            ) {
                $end++;
            }

            $next = $end + 1;
            if (
                $end - $i + 1 >= 3
                && $next < $count
                && !$fenced[$next]
                && $depth[$next] === $here
                && $this->isMarkerLine($content[$next])
            ) {
                $above = -1;
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (trim($content[$k]) !== '') {
                        $above = $k;

                        break;
                    }
                }
                if (
                    $above >= 0
                    && $depth[$above] === $here
                    && ($this->isMarkerLine($content[$above]) || preg_match('/^[ \t]/', $content[$above]))
                ) {
                    $result[] = $lines[$i];
                    $i = $end;

                    continue;
                }
            }

            for ($k = $i; $k <= $end; $k++) {
                $result[] = $lines[$k];
            }
            $i = $end;
        }

        return implode("\n", $result);
    }

    /**
     * Split a line into its block-quote depth and the content inside it.
     *
     * A prefix is a run of `>` markers, each optionally followed by one space
     * and repeatable for nesting, so `> > text` is depth 2 holding `text` and a
     * lone `>` is a blank line one level in.
     *
     * @return array{0: int, 1: string}
     */
    protected function quoted(string $line): array
    {
        $depth = 0;
        while (preg_match('/^[ \t]*>[ ]?/', $line, $prefix)) {
            $depth++;
            $line = substr($line, strlen($prefix[0]));
        }

        return [$depth, $line];
    }

    /**
     * Does the line open a list item: a bullet, or an ordered marker, followed
     * by a space and content?
     */
    protected function isMarkerLine(string $line): bool
    {
        return (bool)preg_match('/^[ \t]*(?:[-*+]|[0-9A-Za-z]+[.)])[ \t]+\S/', $line);
    }

    protected function maskProtectedInlineForms(string $masked): string
    {
        $patterns = [
            '/\\\\\{([' . $this->bracedDelimiterClass() . '])(?!\s)[^\n]+?(?<!\s)\1\}/',
            '/\[\^[^\]\n]+\]:?/',
            '/\{\^(?!\s)((?:(?!\n[ \t]*\n)[^^])+?)(?<!\s)\^\}/',
            '/\{,(?!\s)((?:(?!\n[ \t]*\n)[^,])+?)(?<!\s),\}/',
        ];

        foreach ($patterns as $pattern) {
            $masked = preg_replace_callback(
                $pattern,
                fn (array $group): string => $this->blanks($group[0]),
                $masked,
            ) ?? $masked;
        }

        return $masked;
    }

    protected function backtickRun(string $text, int $offset): int
    {
        $count = 0;
        $length = strlen($text);
        while ($offset + $count < $length && $text[$offset + $count] === '`') {
            $count++;
        }

        return $count;
    }

    protected function blanks(string $text): string
    {
        return preg_replace('/[^\n]/', ' ', $text) ?? $text;
    }
}
