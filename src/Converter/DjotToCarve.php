<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HeadingId\PreservesHeadingIds;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\Utility\QuotedSlotEscaper;

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
    use NormalizesDjotStructure;
    use ReportsDjotLosses;
    use MasksDjotOpaque;

    /**
     * @var string
     */
    private const DJOT_WORD_WHITESPACE = '\\x09-\\x0D\\x20\\x{00A0}\\x{1680}\\x{2000}-\\x{200A}\\x{2028}\\x{2029}\\x{202F}\\x{205F}\\x{3000}\\x{FEFF}';

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
            'pattern' => '/~(?!\s)((?:(?!\n[ \t]*\n)[^~])+?)(?<!\s)~(?!\})/',
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
            'pattern' => '/\^(?!\s)((?:(?!\n[ \t]*\n)[^^])+?)(?<!\s)\^(?!\})/',
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
        if (!str_contains($djot, "\0")) {
            return $this->convertDocument($djot);
        }
        $token = "\0U\0";

        $converted = str_replace($token, "\0", $this->convertDocument(str_replace("\0", $token, $djot), false));
        if ($this->headingIdSource !== null) {
            [, , $body] = $this->splitSiteFrontmatter($this->stripFootnoteDefinitionAttributes($djot)['source']);
            $converted = $this->applyHeadingIdPreservation($converted, $body);
        }

        return $converted;
    }

    private function convertDocument(string $djot, bool $preserveHeadingIds = true): string
    {
        $strippedDefinitions = $this->stripFootnoteDefinitionAttributes($djot);
        $source = $strippedDefinitions['source'];
        [$frontmatter, $separator, $source] = $this->splitSiteFrontmatter($source);
        [$source, $inherited] = $this->escapeInvalidDjotAttributes($source);
        $source = $this->normalizeDjotFences($this->normalizeDjotAttributeLines($source));
        $source = $this->normalizeDjotReferenceUses($this->normalizeDjotStructure($source));
        $source = $this->normalizeDjotFootnotes($this->foldDjotReferences($source), $strippedDefinitions['isBoundary'], $inherited);
        $source = $this->foldHeadingContinuations($this->padDjotCodeSpans($this->escapeDjotNonTableRows($this->normalizeDjotTablePipes($this->normalizeDjotAutolinks($this->normalizeDjotLinks($source, $inherited))))));
        $source = $this->normalizeDjotInlineSpellings($source);
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
            if ($bracket !== false && $collapsedMask[$at + $bracket] === '[' && ($previous === '' || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{[ \t.#A-Za-z}%]|\[(?!\^)[^\]]*\]:)/', $previous) === 1)) {
                $definitions[$definitionMatches[1][$index][0]] = true;
            }
        }
        $source = preg_replace_callback('/(!?\[([^\[\]\n]*)\])\[\]/', static fn (array $match): string => $collapsedMask[$match[0][1]] !== ' ' && isset($definitions[$match[2][0]]) ? $match[1][0] . '[' . $match[2][0] . ']' : $match[0][0], $source, -1, $collapsedCount, PREG_OFFSET_CAPTURE) ?? $source;
        $source = $this->convertDjotBlockMarkers($source);
        $emptyTerm = DjotPlaceholderPrefix::choose($source, "\x00DJOTEMPTYTERM\x00");
        $source = $this->convertDefinitionLists($source, $emptyTerm);
        $djotBody = $source;
        $strongSpans = [];
        $altPrefix = DjotPlaceholderPrefix::choose($source, "\x00DJOTALT\x00");
        $imageMask = $this->maskCodeAndDestinations($source);
        $source = preg_replace_callback('/!\[([^\[\]\n]*)\](?=[([])/', function (array $match) use (&$strongSpans, $altPrefix, $source, $imageMask): string {
            [$image, $at] = $match[0];
            if ($imageMask[$at] !== '!' || $this->isDjotEscaped($source, $at) || str_contains($match[1][0], '\\') || str_starts_with($match[1][0], '^')) {
                return $image;
            }
            if (preg_match('/[_*`{^~]/', $match[1][0]) !== 1) {
                $token = $altPrefix . count($strongSpans) . "\x00";
                $strongSpans[$token] = $match[1][0];

                return '![' . $token . ']';
            }
            $label = preg_replace_callback('/(\\\\+)$/', static fn (array $tail): string => strlen($tail[0]) % 2 !== 0 ? $tail[0] . '\\' : $tail[0], $match[1][0]) ?? $match[1][0];
            $token = $altPrefix . count($strongSpans) . "\x00";
            $strongSpans[$token] = substr(rtrim((new CarveConverter(smartTypography: false, renderer: new PlainTextRenderer()))->convert(str_replace("\0U\0", "\0", $this->convert('DJOTALT ' . $label . ' DJOTEND'))), "\n"), 8, -8);

            return '![' . $token . ']';
        }, $source, -1, $imageCount, PREG_OFFSET_CAPTURE) ?? $source;
        $source = $this->protectAttributedWords($source, $strongSpans, $inherited);
        [$source, $orphanSpans] = $this->consumeOrphanDjotAttributes($source);
        $wire = [];
        $mask = $this->djotEmphasisMask($source, true, $wire);
        $carve = DjotEmphasis::convert($source, $mask, fn (string $plain): string => $this->rewriteDjotInline($plain), $wire ?? [], cellBoundaries: $this->djotTableCellBoundaries($source));

        $carve = str_replace($emptyTerm, '%%', $carve);
        $carve = strtr($carve, $orphanSpans + $strongSpans);
        $dropInherited = array_fill_keys(array_keys($inherited), '');
        $carve = strtr($carve, $dropInherited);
        if ($preserveHeadingIds) {
            $carve = $this->applyHeadingIdPreservation($carve, strtr($djotBody, $dropInherited));
        }

        return ($strippedDefinitions['restore'])($frontmatter === '' ? $carve : $frontmatter . $separator . $carve);
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

    /**
     * @param string $masked
     * @param array{id: string, family: string, pattern: string, open: string, close: string} $rule
     * @param string $source
     *
     * @return iterable<array<array{0: string, 1: int}>>
     */
    private function migrationRuleMatches(string $masked, array $rule, string $source): iterable
    {
        $opener = match ($rule['id']) {
            'djot-subscript-tilde-braced' => '{~',
            'djot-superscript-caret-braced' => '{^',
            'djot-highlight-braces' => '{=',
            default => null,
        };
        if ($opener === null) {
            $candidate = match ($rule['id']) {
                'djot-subscript-tilde' => '~',
                'djot-superscript-caret' => '^',
                'djot-emphasis-underscore', 'djot-intraword-underscore' => '_',
                default => null,
            };
            if ($candidate !== null) {
                $length = strlen($masked);
                $intraword = $rule['id'] === 'djot-intraword-underscore';
                $word = $intraword ? 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' : 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';
                for ($cursor = 0; $cursor < $length;) {
                    $start = strpos($masked, $candidate, $cursor);
                    if ($start === false) {
                        break;
                    }
                    $cursor = $start + 1;
                    if ($this->isDjotEscaped($source, $start) || ($candidate !== '_' && ($source[$start + 1] ?? '') === '}') || ($candidate !== '_' && $start > 0 && ($source[$start - 1] ?? '') === '{' && !$this->isDjotEscaped($source, $start - 1))) {
                        continue;
                    }
                    if (str_contains(" \t\n\r\f\v", $masked[$start + 1] ?? "\0")) {
                        continue;
                    }
                    if ($candidate === '_' && (($start > 0 && str_contains($word, $masked[$start - 1])) !== $intraword)) {
                        continue;
                    }
                    $end = $start + 1;
                    for (; $end < $length; $end++) {
                        if ($masked[$end] === "\n") {
                            $next = $end + 1;
                            while (($source[$next] ?? '') === ' ' || ($source[$next] ?? '') === "\t") {
                                $next++;
                            }
                            while (($source[$next] ?? '') === '>') {
                                $next++;
                                while (($source[$next] ?? '') === ' ' || ($source[$next] ?? '') === "\t") {
                                    $next++;
                                }
                            }
                            if (($source[$next] ?? '') === "\n") {
                                break;
                            }
                        }
                        if ($masked[$end] === '\\' && ($masked[$end + 1] ?? '') !== "\n") {
                            $end++;

                            continue;
                        }
                        if ($masked[$end] === $candidate && ($candidate === '_' || ($source[$end + 1] ?? '') !== '}')) {
                            break;
                        }
                    }
                    $cursor = $end;
                    if ($end >= $length || $masked[$end] !== $candidate) {
                        $cursor++;

                        continue;
                    }
                    if ($end === $start + 1 || str_contains(" \t\n\r\f\v", $masked[$end - 1])) {
                        continue;
                    }
                    if ($candidate === '_' && (($end + 1 < $length && str_contains($word, $masked[$end + 1])) !== $intraword)) {
                        continue;
                    }
                    $cursor = $end + 1;

                    yield [[substr($masked, $start, $cursor - $start), $start], [substr($masked, $start + 1, $end - $start - 1), $start + 1]];
                }

                return;
            }
            preg_match_all($rule['pattern'], $masked, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($found as $match) {
                yield $match;
            }

            return;
        }
        $closer = $opener[1] . '}';
        $length = strlen($masked);
        for ($cursor = 0; $cursor < $length;) {
            $start = strpos($masked, $opener, $cursor);
            if ($start === false) {
                break;
            }
            $from = $start + 2;
            if ($this->isDjotEscaped($masked, $start)) {
                $cursor = $from;

                continue;
            }
            $end = $from;
            for (; $end < $length; $end++) {
                if (substr($masked, $end, 2) === $opener && !$this->isDjotEscaped($source, $end)) {
                    break;
                }
                if ($masked[$end] === "\n") {
                    $next = $end + 1;
                    while (($source[$next] ?? '') === ' ' || ($source[$next] ?? '') === "\t") {
                        $next++;
                    }
                    while (($source[$next] ?? '') === '>') {
                        $next++;
                        while (($source[$next] ?? '') === ' ' || ($source[$next] ?? '') === "\t") {
                            $next++;
                        }
                    }
                    if (($source[$next] ?? '') === "\n") {
                        break;
                    }
                }
                if (substr($masked, $end, 2) === $closer && !$this->isDjotEscaped($masked, $end)) {
                    break;
                }
            }
            if (substr($masked, $end, 2) === $closer) {
                $cursor = $end + 2;
                if ($end !== $from) {
                    yield [[substr($masked, $start, $cursor - $start), $start], [substr($masked, $from, $end - $from), $from]];
                }
            } else {
                $cursor = substr($masked, $end, 2) === $opener ? $end : $end + 1;
            }
        }
    }

    private function rewriteDjotInline(string $source): string
    {
        $masked = $this->djotEmphasisMask($source);
        $source = $this->escapePlainDjotText($source, $masked);
        $masked = $this->djotEmphasisMask($source);

        // Accepted [start, end] delimiter ranges per family, kept sorted by start
        // and disjoint, so the overlap check is a binary search instead of a
        // linear scan of every prior match (which was O(n^2) in match count).
        /** @var array<string, array<array{0: int, 1: int}>> $takenByFamily */
        $takenByFamily = [];
        /** @var array<array{0: int, 1: int, 2: string}> $edits */
        $edits = [];

        foreach ($this->rules as $rule) {
            $ruleTaken = [];
            /** @var array<array{0: string, 1: int}> $match */
            foreach ($this->migrationRuleMatches($masked, $rule, $source) as $match) {
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
                $familyTaken = $takenByFamily[$rule['family']];
                if ($this->familyOverlaps($familyTaken, $start, $end)) {
                    continue;
                }

                $contentStart = $match[1][1];
                $contentEnd = $contentStart + strlen($match[1][0]);

                $ruleTaken[] = [$start, $end];
                // Replace only the delimiters; leave inner bytes untouched.
                $edits[] = [$start, $contentStart, $rule['open']];
                $edits[] = [$contentEnd, $end, $rule['close']];
            }
            if ($ruleTaken !== []) {
                $merged = [];
                $old = 0;
                $added = 0;
                $bucket = $takenByFamily[$rule['family']];
                $oldCount = count($bucket);
                $addedCount = count($ruleTaken);
                while ($old < $oldCount && $added < $addedCount) {
                    $merged[] = $bucket[$old][0] <= $ruleTaken[$added][0] ? $bucket[$old++] : $ruleTaken[$added++];
                }
                $takenByFamily[$rule['family']] = array_merge($merged, array_slice($bucket, $old), array_slice($ruleTaken, $added));
            }
        }

        usort($edits, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $pieces = [];
        $cursor = 0;
        foreach ($edits as [$editStart, $editEnd, $replacement]) {
            if ($editStart < $cursor) {
                continue;
            }
            $pieces[] = substr($source, $cursor, $editStart - $cursor);
            $pieces[] = $replacement;
            $cursor = $editEnd;
        }
        $pieces[] = substr($source, $cursor);
        $source = implode('', $pieces);

        return $this->collapseFalseListBoundaries($this->normalizePlusBullets($source, $masked));
    }

    /**
     * @param string $source
     * @param bool $attributes
     * @param array<int, array{end: int, source: string}>|null $wire
     */
    private function djotEmphasisMask(string $source, bool $attributes = true, ?array &$wire = null): string
    {
        $readAttributes = $wire !== null
            ? $this->nativeAttributeReader($source)
            : fn (int $at): ?array => $this->readDjotWordAttributes($source, $at);
        $masked = $this->maskCodeAndDestinations($source, opaqueOptions: ['comments' => false]);
        $previousLines = $this->previousSourceLines($source);
        $masked = preg_replace_callback('/<[^<>\s]+>/', static fn (array $match): string => preg_match('/[^:]@|[A-Za-z]:/', $match[0]) === 1 ? str_repeat(' ', strlen($match[0])) : $match[0], $masked) ?? $masked;
        $masked = $this->maskFootnoteTokens($masked, '/\[\^[^\]\n]*\]/');
        $masked = preg_replace_callback('/(?<=\])\[[^\]\n]*\]/m', static fn (array $match): string => str_repeat(' ', strlen($match[0])), $masked) ?? $masked;
        $masked = preg_replace_callback('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9]+[.)])[ \t]+)?\[(?!\^)[^\]\n]*\]:[^\n]*/m', function (array $match) use ($previousLines): string {
            [$value, $at] = $match[0];
            $previous = $previousLines[$at] ?? '';
            $previous = trim(preg_replace('/^[ \t]*(?:>[ \t]*)*/', '', $previous) ?? '');

            return $previous === '' || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{[ \t.#A-Za-z}%]|\[(?!\^)[^\]]*\]:)/', $previous) === 1 ? $this->blanks($value) : $value;
        }, $masked, -1, $referenceCount, PREG_OFFSET_CAPTURE) ?? $masked;
        $masked = preg_replace_callback('/!\[[^\[\]\n]*\](?=[([])/', fn (array $match): string => $this->isDjotEscaped($source, $match[0][1]) || $this->isDjotEscaped($source, $match[0][1] + strlen($match[0][0]) - 1) ? $match[0][0] : $this->blanks($match[0][0]), $masked, -1, $imageCount, PREG_OFFSET_CAPTURE) ?? $masked;
        for ($i = 0, $length = strlen($source); $attributes && $i < $length; $i++) {
            if ($masked[$i] !== '{') {
                continue;
            }
            $attrs = $readAttributes($i);
            if ($attrs === null) {
                continue;
            }
            $firstAttributeEnd = $attrs['end'];
            while (($source[$attrs['end']] ?? '') === '{') {
                $next = $readAttributes($attrs['end']);
                if ($next === null) {
                    break;
                }
                $joined = ($attrs['source'] === '{}' ? '' : $attrs['source']) . ($next['source'] === '{}' ? '' : $next['source']);
                $attrs = ['end' => $next['end'], 'source' => $joined !== '' ? $joined : '{}'];
            }
            if ($wire !== null) {
                $wire[$i] = $attrs + ['single' => $attrs['end'] === $firstAttributeEnd];
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
        $block = '/^(?:[#>|{]|[-*+][ \t]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)][ \t]|:[ \t]|:{2,}|\([0-9a-zA-Z]+\)[ \t]|[`~]{3,}|\^[ \t]|%{3,}|\[[^\]\n]*\]:|(?:\*[ \t]*){3,}$|(?:-[ \t]*){3,}$)/';
        $result = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^([ \t]*(?:(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)?)(#{1,6})(?: +\S|[ \t]*$)/', $line, $heading) !== 1 || ($masked[$i][strlen($heading[1])] ?? '') !== '#') {
                $result[] = $line;

                continue;
            }
            $marker = '/^' . $heading[2] . ' +/';
            $column = strlen($heading[1]);
            while ($i + 1 < $count) {
                if ((strlen($line) - strlen(rtrim($line, '\\'))) % 2 === 1) {
                    break;
                }
                if ($column > 0 && strspn($lines[$i + 1], " \t") < $column) {
                    break;
                }
                $next = ltrim($lines[$i + 1], " \t");
                if (preg_match($marker, $next) === 1) {
                    $part = preg_replace($marker, '', $next) ?? $next;
                    if (trim($part) === '') {
                        break;
                    }
                } else {
                    if (trim($next) === '' || preg_match($block, $next) === 1) {
                        break;
                    }
                    $part = $next;
                }
                $line = rtrim($line) . ' ' . $part;
                $i++;
            }
            $result[] = $line;
        }

        return implode("\n", $result);
    }

    /**
     * @return array{depth: int, indent: ?int, minimum: int}
     */
    private function djotAttributeContext(string $source, int $start): array
    {
        $lineStart = $start;
        while ($lineStart > 0 && $source[$lineStart - 1] !== "\n") {
            $lineStart--;
        }
        $prefix = substr($source, $lineStart, $start - $lineStart);
        $depth = 0;
        while (preg_match('/^[ \t]*>(?:[ \t]|$)/', $prefix, $quote)) {
            $prefix = substr($prefix, strlen($quote[0]));
            $depth++;
        }
        preg_match('/^[ \t]*(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', $prefix, $marker);
        $block = preg_match('/^(?:[ \t]*(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+)?[ \t]*$/', $prefix) === 1;

        return ['depth' => $depth, 'indent' => $block ? strlen($prefix) : null, 'minimum' => strlen($marker[0] ?? '')];
    }

    private function djotAttributeLine(string $line, int $depth): ?string
    {
        for ($n = 0; $n < $depth; $n++) {
            if (!preg_match('/^[ \t]*>(?:[ \t]|$)/', $line, $quote)) {
                return null;
            }
            $line = substr($line, strlen($quote[0]));
        }

        return $line;
    }

    /**
     * @return array{end: int, source: string, parts: list<string>}|null
     */
    private function readDjotWordAttributes(string $source, int $start, bool $carve = false, bool $table = false): ?array
    {
        $parts = [];
        $length = strlen($source);
        $i = $start + 1;
        $context = null;
        $quoteValue = static fn (string $value): string => '"' . QuotedSlotEscaper::escape($value, $carve ? AttributeParser::ESCAPABLE_PUNCTUATION : '"') . '"';
        while ($i < $length) {
            while ($i < $length && str_contains(" \t\n\r", $source[$i])) {
                if ($source[$i] === "\n" && preg_match('/\G[ \t]*\n/', $source, offset: $i + 1) === 1) {
                    return null;
                }
                if ($source[$i] === "\n") {
                    $context ??= $this->djotAttributeContext($source, $start);
                    $i++;
                    for ($n = 0; $n < $context['depth']; $n++) {
                        if (!preg_match('/\G[ \t]*>(?:[ \t]|$)/', $source, $quote, offset: $i)) {
                            break;
                        }
                        $i += strlen($quote[0]);
                    }
                } else {
                    $i++;
                }
            }
            if (($source[$i] ?? '') === '}' && $parts !== [] && str_contains(substr($source, $start, $i + 1 - $start), "\n")) {
                $context = $this->djotAttributeContext($source, $start);
                foreach (array_slice(explode("\n", substr($source, $start, $i + 1 - $start)), 1) as $raw) {
                    $line = $this->djotAttributeLine($raw, $context['depth']);
                    if ($context['indent'] !== null && $line === null) {
                        return null;
                    }
                    $indent = strspn($line ?? $raw, " \t");
                    if ($context['indent'] !== null && ($indent < $context['minimum'] || $indent <= $context['indent'])) {
                        return null;
                    }
                }
            }
            if (($source[$i] ?? '') === '}') {
                if ($carve && $table) {
                    for ($at = $start; $at <= $i; $at++) {
                        if ($source[$at] === '\\') {
                            $at++;
                        } elseif ($source[$at] === '|') {
                            return null;
                        }
                    }
                }

                return ['end' => $i + 1, 'source' => '{' . implode(' ', $parts) . '}', 'parts' => $parts];
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
                if ($kind === '#') {
                    if (preg_match('/\G[^\]\[~!@#$%^&*(){}`,.<>\\\\|=+\/?\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', $source, $identifier, offset: $i) !== 1) {
                        return null;
                    }
                    $i += strlen($identifier[0]);
                } else {
                    $i += strspn($source, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_:-', $i);
                }
                if ($i === $from) {
                    return null;
                }
                $value = substr($source, $from, $i - $from);
                if ($kind === '#' ? preg_match('/[\]\[~!@#$%^&*(){}`,.<>\\\\|=+\/?\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', $value) === 1 : preg_match('/^[A-Za-z0-9_:-]+$/D', $value) !== 1) {
                    return null;
                }
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
                        if ($source[$i] === "\n" && preg_match('/\G[ \t]*\n/', $source, offset: $i + 1) === 1) {
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
                    $value = substr($source, $from + 1, $i - $from - 2);
                    if (str_contains($value, "\n")) {
                        $context = $this->djotAttributeContext($source, $start);
                        $value = preg_replace_callback('/\r?\n([^\n]*)/', fn (array $match): string => ' ' . ltrim($this->djotAttributeLine($match[1], $context['depth']) ?? $match[1], " \t"), $value) ?? $value;
                    }
                    $value = preg_replace('/[ \r\n]+/', ' ', $value) ?? $value;
                    $value = preg_replace_callback('/\\\\(.)/us', static fn (array $match): string => str_contains(".,\\/#!$%^&*;:{}=-_`~+[]()'\"?|", $match[1]) ? $match[1] : $match[0], $value) ?? $value;
                    $parts[] = $key[0] . $quoteValue($value);
                } else {
                    while ($i < $length && preg_match('/[A-Za-z0-9_:-]/', $source[$i]) === 1) {
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
     * @return \Closure(int): (array{end: int, source: string}|null)
     */
    private function nativeAttributeReader(string $source): Closure
    {
        $lineEnd = -1;
        $table = false;

        return function (int $start) use ($source, &$lineEnd, &$table): ?array {
            while ($lineEnd < $start) {
                $lineStart = $lineEnd + 1;
                $newline = strpos($source, "\n", $lineStart);
                $lineEnd = $newline === false ? strlen($source) : $newline;
                $line = substr($source, $lineStart, $lineEnd - $lineStart);
                $table = ($line[DjotEmphasis::structuralPrefixEnd($line)] ?? '') === '|';
            }

            return $this->readDjotWordAttributes($source, $start, true, $table);
        };
    }

    private function djotWordWhitespaceAt(string $source, int $at): bool
    {
        $ch = $source[$at] ?? '';
        if ($ch === '') {
            return false;
        }
        if (ctype_space($ch)) {
            return true;
        }

        return str_contains("\xC2\xE1\xE2\xE3\xEF", $ch)
            && preg_match('/\G(?:\xC2\xA0|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80|\xEF\xBB\xBF)/', $source, offset: $at) === 1;
    }

    /**
     * @param string $source
     * @param array<string, string> $spans
     * @param array<string, true> $inherited
     */
    private function protectAttributedWords(string $source, array &$spans, array $inherited): string
    {
        if (!str_contains($source, '{')) {
            return $source;
        }
        $paired = DjotEmphasis::pairedOpeners($source, $this->djotEmphasisMask($source), $this->djotTableCellBoundaries($source));
        $readNative = $this->nativeAttributeReader($source);
        $masked = $this->maskFootnoteTokens($this->maskCodeAndDestinations($source), '/\[\^[^\]\n]*\]/', true);
        $literalBraces = [];
        $pairedCloses = array_fill_keys(array_values($paired), true);
        $escapedBraceCloses = [];
        $wordAtoms = [];
        preg_match_all('/\[\^[^\]\n]*\]/', $source, $literalNotes, PREG_OFFSET_CAPTURE);
        foreach ($literalNotes[0] as [$note, $at]) {
            $begin = $at;
            while ($begin > 0 && $source[$begin - 1] === '\\') {
                $begin--;
            }
            $closeBegin = $at + strlen($note) - 1;
            while ($closeBegin > $at && $source[$closeBegin - 1] === '\\') {
                $closeBegin--;
            }
            if (($at - $begin) % 2 !== 0 && ($at + strlen($note) - 1 - $closeBegin) % 2 === 0 && preg_match('/^[^\s{}*_~`\\\\]+$/u', $note) === 1) {
                $close = $at + strlen($note) - 1;
                $literalBraces[$close] = $at - 1;
                $escapedBraceCloses[$close] = true;
            }
        }
        $braceStack = [];
        $readBraceAttributes = $this->nativeAttributeReader($source);
        $attributeEnd = 0;
        $lastSpace = -1;
        $lastAtomEscape = -1;
        $lastInlineEnd = -1;
        $lastEscaped = -1;
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            if ($this->djotWordWhitespaceAt($source, $at)) {
                $lastSpace = $at;
            }
            if (isset($pairedCloses[$at])) {
                $lastInlineEnd = $at;
            }
            if ($source[$at] === '\\') {
                if (isset($source[$at + 1]) && !$this->djotWordWhitespaceAt($source, $at + 1)) {
                    $wordAtoms[substr($source, $at + 1, 3) === "\0U\0" ? $at + 4 : $at + 2] = $at;
                }
                if ($masked[$at] !== $source[$at] || ($masked[$at + 1] ?? '') !== ($source[$at + 1] ?? '')) {
                    $lastInlineEnd = $at + 2;
                    $at++;

                    continue;
                }
                $lastEscaped = $at + 1;
                if ($this->djotWordWhitespaceAt($source, $at + 1)) {
                    $lastSpace = $at + 1;
                }
                $generated = $inherited !== [] && preg_match('/\G\x00DJOTINVALIDATTR\x00[0-9]+\x00/', $source, $marker, offset: $at + 2) === 1 && isset($inherited[$marker[0]]);
                if ($generated) {
                    $literalBraces[$at + 1 + strlen($marker[0])] = $at + 2;
                }
                if (!$generated && preg_match('/[!-\/:-@\[-`{-~]/', $source[$at + 1] ?? '') === 1) {
                    $lastAtomEscape = $at;
                }
                if (($source[$at + 1] ?? '') === '{' && $masked[$at + 1] === '{') {
                    $braceStack[] = ['begin' => $at, 'literal' => true, 'space' => $lastSpace];
                } elseif (($source[$at + 1] ?? '') === '}' || ($source[$at + 1] ?? '') === ']') {
                    $top = array_key_last($braceStack);
                    if ($source[$at + 1] === '}' && $top !== null && $braceStack[$top]['literal']) {
                        array_pop($braceStack);
                    }
                    $literalBraces[$at + 1] = $at;
                    $escapedBraceCloses[$at + 1] = true;
                }
                $at++;

                continue;
            }
            if ($masked[$at] !== $source[$at]) {
                $lastInlineEnd = $at + 1;

                continue;
            }
            if (substr($source, $at, 3) === "\0U\0") {
                $wordAtoms[$at + 3] = $at;
                $at += 2;

                continue;
            }
            if ($source[$at] === '{' && $at >= $attributeEnd) {
                $attributeEnd = $readBraceAttributes($at)['end'] ?? $at;
            }
            if ($at >= $attributeEnd && $source[$at] === '}' && $at > 0 && str_contains('+-=~^*_', $source[$at - 1]) && !isset($pairedCloses[$at + 1])) {
                $literalBraces[$at] = $at - 1 === $lastEscaped ? $at - 2 : $at - 1;
                if ($at - 1 === $lastEscaped) {
                    $escapedBraceCloses[$at] = true;
                }
            }
            if ($source[$at] === '{') {
                $braceStack[] = ['begin' => $at, 'literal' => $at >= $attributeEnd && preg_match('/[.#% \tA-Za-z]/', $source[$at + 1] ?? '') === 1, 'space' => $lastSpace];
            } elseif ($source[$at] === '}') {
                $open = array_pop($braceStack);
                if (($open['literal'] ?? false) && !isset($escapedBraceCloses[$at]) && !isset($pairedCloses[$at + 1])) {
                    $from = max($lastAtomEscape, $lastInlineEnd);
                    if ($from >= $open['begin'] && $from > $lastSpace) {
                        $literalBraces[$at] = $from;
                        $escapedBraceCloses[$at] = true;
                    } else {
                        $literalBraces[$at] = $lastSpace === $open['space'] ? $open['begin'] : $at;
                    }
                }
            }
        }
        $prefix = DjotPlaceholderPrefix::choose($source, "\0DJOTWORD");
        $output = '';
        $cursor = 0;
        $lastClose = strrpos($source, '}');
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
            $attrs = $readNative($i);
            if ($attrs === null) {
                continue;
            }
            while (($source[$attrs['end']] ?? '') === '{') {
                $next = $readNative($attrs['end']);
                if ($next === null) {
                    break;
                }
                $joined = ($attrs['source'] === '{}' ? '' : $attrs['source']) . ($next['source'] === '{}' ? '' : $next['source']);
                $attrs = ['end' => $next['end'], 'source' => $joined !== '' ? $joined : '{}'];
            }
            if ($attrs['source'] === '{}') {
                $i = $attrs['end'] - 1;

                continue;
            }
            $start = $i;
            if ($i > 0 && $masked[$i - 1] === $source[$i - 1] && (!str_contains('`*_~^]}>', $source[$i - 1]) || isset($literalBraces[$i - 1]) || isset($wordAtoms[$i]))) {
                while ($start > $cursor && $masked[$start - 1] === $source[$start - 1]) {
                    $literal = $literalBraces[$start - 1] ?? null;
                    if ($literal !== null && $literal >= $cursor) {
                        $start = $literal;

                        continue;
                    }
                    if (isset($wordAtoms[$start]) && $wordAtoms[$start] >= $cursor) {
                        $start = $wordAtoms[$start];

                        continue;
                    }
                    if (str_contains(" \t\n\r\v\f\"'{}[]`\0>|", $source[$start - 1])) {
                        break;
                    }
                    $start--;
                }
                if ($start < $i && preg_match('/[^' . self::DJOT_WORD_WHITESPACE . ']+$/u', substr($source, $start, $i - $start), $word, PREG_OFFSET_CAPTURE) === 1) {
                    $start += $word[0][1];
                } else {
                    $start = $i;
                }
                $pairedWord = null;
                for ($at = $i - 1; $at >= $start; $at--) {
                    if (($paired[$at] ?? -1) > $attrs['end']) {
                        $pairedWord = $at + 1;

                        break;
                    }
                }
                if ($pairedWord !== null) {
                    $start = $pairedWord;
                }
                if ($start > 0 && $source[$start - 1] === '{' && str_contains('+-=', $source[$start] ?? '')) {
                    $start++;
                }
            }
            if ($start < $i) {
                $token = $prefix . count($spans) . "\0";
                $body = substr($this->convert('x ' . substr($source, $start, $i - $start)), 2);
                if ($source[$i - 1] === ']' && isset($escapedBraceCloses[$i - 1]) && ($literalBraces[$i - 1] ?? null) !== $i - 2) {
                    $body = substr($body, 0, -1) . '\\]';
                }
                if (str_starts_with($body, '^')) {
                    $body = '\\' . $body;
                }
                $spans[$token] = (in_array($source[$start - 1] ?? '', [']', '^', '!'], true) ? '{%%}' : '') . '[' . $body . ']' . $attrs['source'];
                $output .= substr($source, $cursor, $start - $cursor) . $token;
                $cursor = $attrs['end'];
            }
            $i = $attrs['end'] - 1;
        }

        return $output . substr($source, $cursor);
    }

    /**
     * @return array{source: string, losses: list<int>, restore: \Closure(string): string, isBoundary: \Closure(string): bool}
     */
    private function stripFootnoteDefinitionAttributes(string $input): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $input);
        if (!str_contains($source, '{') || !str_contains($source, '[^')) {
            return ['source' => $source, 'losses' => [], 'restore' => static fn (string $text): string => $text, 'isBoundary' => static fn (string $line): bool => false];
        }
        [$frontmatter, $separator, $body] = $this->splitSiteFrontmatter($source);
        $header = $frontmatter === '' ? '' : $frontmatter . $separator;
        $headerLines = substr_count($header, "\n");
        $fenceLines = [];
        $this->maskDjotFences($body, static function (int $line) use (&$fenceLines): void {
            $fenceLines[$line] = true;
        }, [], true);
        $mask = $this->maskCodeAndDestinations($body);
        $lines = explode("\n", $body);
        $losses = [];
        preg_match_all('/\x00DJOTNOTEATTR\x00(\d+)\x00/', $source, $tokens);
        $reserved = array_fill_keys(array_map('intval', $tokens[1]), true);
        $comments = [];
        $serial = 0;
        $offset = 0;
        $boundary = true;
        $pending = [];
        $quoteDepth = 0;
        $listColumn = null;
        $listQuoteDepth = 0;
        $consumedUntil = -1;
        $dedent = null;
        $heading = false;
        $table = false;
        $noteParents = [];
        $metadataNote = false;
        $noteColumn = null;
        $referenceColumn = null;
        $divWidths = [];
        foreach ($lines as $n => $line) {
            if ($offset < $consumedUntil) {
                $offset += strlen($line) + 1;

                continue;
            }
            preg_match('/^(?:[ \t]*>[ \t]?|[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)*[ \t]*/', $line, $prefixMatch);
            $prefix = $prefixMatch[0] ?? '';
            $content = rtrim(substr($line, strlen($prefix)), " \t");
            $depth = substr_count($prefix, '>');
            $marker = preg_match('/[-*+.)]/', $prefix) === 1;
            $column = strlen(preg_replace('/^(?:[ \t]*>[ \t]?)*/', '', $prefix) ?? $prefix);
            if (($mask[$offset + strlen($prefix)] ?? '') !== ' ' && str_contains($prefix, "\t") && ($boundary || $pending !== [] || $listColumn !== null)) {
                if (!$marker && $listColumn !== null && $depth === $listQuoteDepth && $column === $listColumn - 1) {
                    $column = $listColumn;
                    preg_match('/^(?:[ \t]*>[ \t]?)*/', $prefix, $quotes);
                    $lines[$n] = str_replace("\t", ' ', ($quotes[0] ?? '')) . str_repeat(' ', $column) . substr($line, strlen($prefix));
                } else {
                    $lines[$n] = str_replace("\t", ' ', $prefix) . substr($line, strlen($prefix));
                }
            }
            if ($dedent !== null) {
                preg_match('/^(?:[ \t]*>[ \t]?)*/', $line, $quotes);
                preg_match('/^[ \t]*/', substr($line, strlen(($quotes[0] ?? ''))), $indent);
                if ($content !== '' && ($depth !== $dedent['depth'] || strlen(($indent[0] ?? '')) < $dedent['column'])) {
                    $dedent = null;
                } elseif ($content !== '') {
                    $lines[$n] = ($quotes[0] ?? '') . substr($line, strlen(($quotes[0] ?? '')) + $dedent['delta']);
                }
            }
            while ($noteColumn !== null && $content !== '' && $column + 1 < $noteColumn) {
                if ($metadataNote && $listColumn !== null && $column >= $listColumn && $n > 0 && preg_match('/^[ \t]*$/', $lines[$n - 1]) === 1) {
                    while (isset($reserved[$serial])) {
                        $serial++;
                    }
                    $comments[$serial] = true;
                    $lines[$n - 1] = str_repeat(' ', $listColumn) . "\x00DJOTNOTEATTR\x00" . $serial++ . "\x00";
                }
                $noteColumn = $noteParents === [] ? null : array_pop($noteParents);
                $metadataNote = false;
                $boundary = true;
            }
            if ($noteColumn !== null && $content !== '' && $column === $noteColumn - 1) {
                $raw = $lines[$n];
                preg_match('/^(?:[ \t]*>[ \t]?)*/', $raw, $quotes);
                $quoteText = $quotes[0] ?? '';
                $lines[$n] = $quoteText . ' ' . substr($raw, strlen($quoteText));
                $column++;
            }
            $parentNoteColumn = $noteColumn;
            if ($depth < $quoteDepth) {
                $boundary = true;
                $heading = false;
            }
            if ($listColumn !== null && ($depth !== $listQuoteDepth || $content !== '' && !$marker && $column < $listColumn)) {
                $listColumn = null;
                $boundary = true;
                $heading = false;
            }
            $opensItem = $marker && ($boundary || $pending !== [] || $listColumn !== null);
            $opensQuote = $depth > $quoteDepth && ($boundary || $pending !== [] || $opensItem);
            if ($opensItem || $opensQuote) {
                $pending = [];
                $heading = false;
            }
            if ($opensItem) {
                $listColumn = $column;
                $listQuoteDepth = $depth;
            }
            if ($opensQuote || $depth < $quoteDepth) {
                $quoteDepth = $depth;
            }
            if ($referenceColumn !== null) {
                if ($content !== '' && $column > $referenceColumn && !$marker && preg_match('/^\S+$/', $content) === 1) {
                    $offset += strlen($line) + 1;
                    $boundary = false;
                    $pending = [];

                    continue;
                }
                $referenceColumn = null;
                if ($content !== '') {
                    $boundary = true;
                }
            }
            $blockAllowed = $boundary || $pending !== [] || $opensItem || $opensQuote;
            $handledNote = false;
            $attrs = str_starts_with($content, '{') && ($mask[$offset + strlen($prefix)] ?? '') === '{' ? $this->readDjotWordAttributes($body, $offset + strlen($prefix)) : null;
            $trailingEnd = $attrs !== null ? strpos($body, "\n", $attrs['end']) : false;
            $standalone = $attrs !== null && preg_match('/^[ \t]*$/', substr($body, $attrs['end'], ($trailingEnd === false ? strlen($body) : $trailingEnd) - $attrs['end'])) === 1;
            if ($attrs !== null && $standalone && $blockAllowed) {
                $pending[] = ['line' => $n, 'end' => $attrs['end'], 'start' => $offset, 'wire' => $attrs['source']];
                $consumedUntil = $attrs['end'];
            } else {
                if ($pending !== [] && preg_match('/^\[\^[^\]\n]+\]:(?:[ \t]|$)/', $content) === 1 && ($mask[$offset + strlen($prefix)] ?? '') === '[') {
                    $handledNote = true;
                    preg_match('/^(?:[ \t]*>[ \t]?)*/', $prefix, $quotePrefix);
                    $targetColumn = max($listColumn ?? 0, $parentNoteColumn ?? 0);
                    $notePrefix = ($quotePrefix[0] ?? '') . str_repeat(' ', $targetColumn);
                    if ($column > $targetColumn) {
                        $dedent = ['column' => $column, 'delta' => $column - $targetColumn, 'depth' => $depth];
                    }
                    $lines[$n] = $notePrefix . substr($line, strlen($prefix));
                    foreach ($pending as $group) {
                        if ($group['wire'] !== '{}') {
                            $losses[] = $headerLines + $group['line'] + 1;
                        }
                        $at = $group['line'];
                        $position = $group['start'];
                        while ($at < $n && $position < $group['end']) {
                            $raw = $lines[$at];
                            if ($at === $group['line']) {
                                $start = strpos($raw, '{');
                                $lead = substr($raw, 0, $start === false ? 0 : $start);
                            } else {
                                preg_match('/^(?:[ \t]*>[ \t]?)*[ \t]*/', $raw, $continuation);
                                $lead = $continuation[0] ?? '';
                            }
                            while (isset($reserved[$serial])) {
                                $serial++;
                            }
                            $comments[$serial] = true;
                            $lines[$at] = $lead . "\x00DJOTNOTEATTR\x00" . $serial++ . "\x00";
                            $position += strlen($raw) + 1;
                            $at++;
                        }
                    }
                }
                $pending = [];
            }
            $reference = $blockAllowed && ($mask[$offset + strlen($prefix)] ?? '') === '[' && preg_match('/^\[(?!\^)[^\]\n]*\]:(?:[ \t]+\S*[ \t]*|)$/', $content) === 1;
            if ($reference) {
                $referenceColumn = $column;
                $heading = false;
            }
            $note = preg_match('/^\[\^[^\]\n]+\]:(?:[ \t]|$)/', $content) === 1;
            if ($note && $blockAllowed && ($mask[$offset + strlen($prefix)] ?? '') === '[') {
                if ($noteColumn !== null) {
                    $noteParents[] = $noteColumn;
                }
                $noteColumn = $column + 2;
                $metadataNote = $handledNote;
                $heading = false;
            }
            if ($standalone || isset($fenceLines[$n]) || $content === '') {
                $heading = false;
            } elseif ($blockAllowed && preg_match('/^#{1,6}(?:[ \t]|$)/', $content) === 1) {
                $heading = true;
            }
            $endAt = $offset + strlen(rtrim($line, " \t")) - 1;
            $slashes = 0;
            for ($at = $endAt - 1; $at >= 0 && $body[$at] === '\\'; $at--) {
                $slashes++;
            }
            $row = ($blockAllowed || $table) && str_starts_with($content, '|') && str_ends_with($content, '|') && ($mask[$offset + strlen($prefix)] ?? '') === '|' && $slashes % 2 === 0;
            $table = $row;
            $colon = preg_match('/^(:{3,})(?:[ \t].*)?$/', $content, $colonMatch) === 1;
            $div = false;
            if ($colon && ($blockAllowed || $divWidths !== [] && preg_match('/^:{3,}$/', $content) === 1)) {
                $div = true;
                $width = strlen($colonMatch[1]);
                if ($divWidths !== [] && preg_match('/^:{3,}$/', $content) === 1 && $width >= $divWidths[array_key_last($divWidths)]) {
                    array_pop($divWidths);
                } else {
                    $divWidths[] = $width;
                }
                $heading = false;
            }
            $boundary = $content === '' || $reference || isset($fenceLines[$n]) || $row || $div || $heading || $blockAllowed && preg_match('/^(?:[-*][ \t]*){3,}$/', $content) === 1;
            $offset += strlen($line) + 1;
        }

        return [
            'source' => $header . implode("\n", $lines),
            'losses' => $losses,
            'isBoundary' => static function (string $line) use ($comments): bool {
                return preg_match('/\x00DJOTNOTEATTR\x00(\d+)\x00$/', rtrim($line, " \t"), $token) === 1 && isset($comments[(int)$token[1]]);
            },
            'restore' => static fn (string $text): string => preg_replace_callback('/\x00DJOTNOTEATTR\x00(\d+)\x00/', static fn (array $match): string => isset($comments[(int)$match[1]]) ? '%%' : $match[0], $text) ?? $text,
        ];
    }

    public function convertWithFidelityReport(string $djot): MigrationResult
    {
        $stripped = $this->stripFootnoteDefinitionAttributes($djot);
        $losses = $this->djotLosses($djot);
        $result = $this->assessedMigrationResult($djot, $this->convert($djot), 'djot', $stripped['losses'] !== [] || $losses !== []);
        $diagnostics = array_merge($result->diagnostics, $losses);
        foreach ($stripped['losses'] as $line) {
            $diagnostics[] = new MigrationDiagnostic(
                'djot-footnote-definition-attributes-dropped',
                'Carve cannot represent attributes on a footnote definition; they were dropped instead of applying them to later content.',
                'warning',
                'dropped',
                'exact',
                'line:' . $line,
            );
        }

        return new MigrationResult($result->value, 'djot', $diagnostics);
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
        $nested = [];
        /** @var list<array{columns: int, marker: bool}> $ancestors */
        $ancestors = [];
        foreach ($maskedLines as $masked) {
            if (trim($masked) === '') {
                $nested[] = false;

                continue;
            }
            [$columns] = $this->leadingIndent($masked);
            while ($ancestors !== [] && $ancestors[array_key_last($ancestors)]['columns'] >= $columns) {
                array_pop($ancestors);
            }
            $nested[] = $ancestors === [] ? false : $ancestors[array_key_last($ancestors)]['marker'];
            $marker = !preg_match('/^(?:([*-])[ \t]*){3,}$/', trim($masked))
                && (bool)preg_match('/^(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+\S/', ltrim($masked));
            $ancestors[] = ['columns' => $columns, 'marker' => $marker];
        }
        $containers = [];
        $fenceParser = new FencedBlockParser();
        foreach ($lines as $i => $line) {
            $masked = $maskedLines[$i] ?? $line;
            if (trim($masked) === '') {
                continue;
            }
            $view = $line;
            while (preg_match('/^(?:[ \t]*> ?|[ \t]*(?:(?:[-*+]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-zA-Z])[.)]|\([0-9A-Za-z]+\)) +(?:\[[ xX]\] +)?|: |\[\^[^\]\r\n]+\]: +))/', $view, $host)) {
                $view = substr($view, strlen($host[0]));
            }
            $view = ltrim($view, " \t");
            $containerOffset = strlen($line) - strlen($view);
            if (str_starts_with($view, ':::') && str_starts_with(substr($masked, $containerOffset), ':::')) {
                $content = substr($line, $containerOffset);
                $opener = $fenceParser->parseDivFenceOpener($content);
                $top = $containers !== [] ? $containers[array_key_last($containers)] : null;
                $close = $opener !== null && preg_match('/^:{3,}[ \t]*$/', $content) === 1
                    && $top !== null && $top['width'] === $opener['length'];
                $invalid = $close ? array_pop($containers)['invalid'] : ($opener['invalidMetadata'] ?? false);
                if (!$close && $opener !== null) {
                    $containers[] = ['width' => $opener['length'], 'invalid' => $invalid];
                }
                if ($invalid) {
                    $at = $containerOffset;
                    $lines[$i] = substr($line, 0, $at) . '\\' . substr($line, $at);
                    $line = $lines[$i];
                }
            }
            if (preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)\(([0-9A-Za-z]+)\)([ \t]+\S.*)$/', $masked, $match)) {
                if (!preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)\(([0-9A-Za-z]+)\)([ \t]+\S.*)$/', $line, $authored)) {
                    continue;
                }
                $indent = $match[1] === '' && $nested[$i] ? $authored[2] : '';
                $lines[$i] = $authored[1] . $indent . $authored[3] . '.' . $authored[4];

                continue;
            }
            if (!preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)([*-])(?:[ \t]*\3){2,}[ \t]*$/', $masked, $rule)) {
                continue;
            }
            $indent = $rule[1] === '' && $nested[$i] ? $rule[2] : '';
            $lines[$i] = $rule[1] . $indent . '***';
        }

        return implode("\n", $lines);
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
                $lines[$i] = ltrim($line, " \t");

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
        $maskedSource = $this->maskFootnoteTokens($maskedSource, '/\[\^[^\]\n]*\]/', true);
        $masked = explode("\n", $maskedSource);
        $item = '(?:[.#][A-Za-z0-9_][A-Za-z0-9_-]*|[A-Za-z][A-Za-z0-9_-]*=(?:"(?:\\\\.|[^"\\\\\n])*"|[A-Za-z0-9_:-]+))';
        $pattern = '/\{[ \t]*' . $item . '(?:[ \t]+' . $item . ')*[ \t]*\}/';
        $lines = explode("\n", $source);
        $dangling = [];
        $wholeAttribute = '/^(?:' . substr($pattern, 1, -1) . ')$/';
        $followsBlank = true;
        $nextDepth = null;
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = $lines[$index];
            preg_match('/^(?:(?:[ \t]*>)+[ \t]*)?[ \t]*/', $line, $container);
            $first = strlen($container[0] ?? '');
            $depth = substr_count(substr($line, 0, $first), '>');
            if (trim(substr($line, $first)) === '') {
                $followsBlank = true;
                $nextDepth = $depth === 0 ? null : $depth;

                continue;
            }
            if (($masked[$index][$first] ?? '') === '{' && preg_match($wholeAttribute, rtrim(substr($line, $first))) === 1) {
                if ($nextDepth !== null && $depth !== $nextDepth) {
                    $followsBlank = false;
                }
                if ($followsBlank) {
                    $dangling[$index] = true;
                }
                $nextDepth = $depth;
            } else {
                $followsBlank = false;
                $nextDepth = null;
            }
        }
        $prefix = DjotPlaceholderPrefix::choose($source, "\x00DJOTORPHAN\x00");
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
                $block = ($index === 0 || $previous === '' || preg_match('/^\{.*\}$/', $previous) === 1 || preg_match('/^(?:`{3,}|~{3,}|:{3,}|#{1,6} |[-*+] |[0-9]+[.)] |> |:{1,2} |(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|\|.*\||\[[^\]]+\]:)/', $previous) === 1);
                if ($alone && $block) {
                    if (!isset($dangling[$index])) {
                        continue;
                    }
                    $dropLine = true;
                }
                $written .= substr($line, $cursor, $at - $cursor) . ($prefix . ($at === $first ? 'L' : 'I'));
                $cursor = $at + strlen($attrs);
            }
            if ($dropLine) {
                if (str_contains(substr($line, 0, $first), '>')) {
                    $out[] = rtrim(substr($line, 0, $first));
                }

                continue;
            }
            $written .= substr($line, $cursor);
            $out[] = preg_replace_callback('/' . preg_quote($prefix, '/') . '([LI])([ \t]*)/', static function (array $match) use (&$spaces, $prefix): string {
                $token = $prefix . count($spaces) . "\x00";
                $spaces[$token] = $match[1] === 'I' ? $match[2] : ($match[2] === '' ? '{%%}' : '!`' . $match[2] . '`');

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

    private function maskDjotAttributeSource(string $source): string
    {
        $codeMask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['comments' => false]);
        $mask = $this->maskCodeAndDestinations($source, opaqueOptions: ['comments' => false]);
        $rows = $this->djotTableRows($source, $codeMask);
        $definitionIndent = -1;
        $definitionOffset = 0;
        $previousContent = '';
        foreach (explode("\n", $source) as $definitionLine => $line) {
            $at = $this->djotContentStart($line);
            $content = substr($line, $at);
            $boundary = $previousContent === '' || ($rows[$definitionLine - 1] ?? false) || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{[ \t.#A-Za-z}%]|\[(?!\^)[^\]]*\]:)/', $previousContent) === 1;
            $definition = ($codeMask[$definitionOffset + $at] ?? '') === '[' && preg_match('/^\[(?!\^)[^\]\n]*\]:/', $content) === 1 && $boundary;
            $continuation = $definitionIndent >= 0 && $at > $definitionIndent && preg_match('/^\S+$/', $content) === 1;
            if ($definition || $continuation) {
                for ($i = 0, $lineLength = strlen($line); $i < $lineLength; $i++) {
                    $mask[$definitionOffset + $i] = ' ';
                }
                if ($definition) {
                    $definitionIndent = $at;
                }
            } else {
                $definitionIndent = -1;
            }
            $previousContent = trim($content);
            $definitionOffset += strlen($line) + 1;
        }

        return preg_replace_callback('/<[^<>\\s]+>/', static fn (array $match): string => preg_match('/[^:]@|[A-Za-z]:/', $match[0]) === 1 ? str_repeat(' ', strlen($match[0])) : $match[0], $mask) ?? $mask;
    }

    /**
     * @param string $source
     * @param array<string, true> $inherited
     */
    private function removeInheritedAttributeMarkers(string $source, array $inherited): string
    {
        return preg_replace_callback('/\x00DJOTINVALIDATTR\x00[0-9]+\x00/', static fn (array $match): string => isset($inherited[$match[0]]) ? '' : $match[0], $source) ?? $source;
    }

    /**
     * @return array{string, array<string, true>}
     */
    private function escapeInvalidDjotAttributes(string $source): array
    {
        $masked = $this->maskDjotAttributeSource($source);
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
            $attrs = $this->readDjotWordAttributes($source, $i);
            if ($attrs !== null) {
                $i = $attrs['end'] - 1;

                continue;
            }
            if (preg_match('/[.#% \tA-Za-z]/', $source[$i + 1] ?? '') === 1) {
                $escapes[] = $i;
                // Every brace inside the same rejected run is literal Djot text too, so a
                // later pass must not read one as a forced quote and swallow it.
                for ($inner = $i + 1; $inner < $length && $source[$inner] !== "\n" && $source[$inner] !== '}'; $inner++) {
                    if ($source[$inner] === '{' && $masked[$inner] === '{' && $this->readDjotWordAttributes($source, $inner) === null) {
                        $escapes[] = $inner;
                    }
                }
                $i = $escapes[array_key_last($escapes)];

                continue;
            }
        }
        preg_match_all('/\x00DJOTINVALIDATTR\x00[0-9]+\x00/', $source, $matches);
        $reserved = array_fill_keys($matches[0], true);
        $inherited = [];
        $serial = 0;
        $marker = static function () use (&$serial, $reserved, &$inherited): string {
            do {
                $value = "\0DJOTINVALIDATTR\x00" . $serial++ . "\0";
            } while (isset($reserved[$value]));
            $inherited[$value] = true;

            return $value;
        };
        $output = '';
        $cursor = 0;
        foreach ($escapes as $at) {
            $hash = ($source[$at + 1] ?? '') === '#';
            $output .= substr($source, $cursor, $at - $cursor) . '\\{' . $marker() . ($hash ? '\\#' . $marker() : '');
            $cursor = $at + ($hash ? 2 : 1);
        }

        return [$output . substr($source, $cursor), $inherited];
    }

    /**
     * Make an angle run that only LOOKS like an autolink visible to the
     * plain-text escape again.
     *
     * The emphasis mask hides every angle run shaped like an autolink, which
     * exempts its body from the rules that keep Carve text reading as text.
     * That is right while the body is one: an autolink body is opaque, so
     * `<a--@b.c>` keeps its `@` bare and its link. A body holding a lifted
     * construct is NOT one - the reader takes the comment out before it ever
     * looks for an autolink - so `<mailto:a{%%}@b.c>` is plain text whose `@`
     * opens a MENTION instead of belonging to the address
     * (markup-carve/carve-php#3037). Its Djot source `<mailto:a{ }@b.c>` is
     * plain text too, and carve-js writes the escape.
     *
     * A lifted construct is a NUL-delimited placeholder, and Carve source
     * carries no NUL of its own, so the byte is the whole test.
     */
    private function unmaskDjotAutolinkBodies(string $source, string $masked): string
    {
        if (!str_contains($source, '<') || !str_contains($source, "\0")) {
            return $masked;
        }
        $visible = $this->maskCodeAndDestinations($source, opaqueOptions: ['autolinks' => false, 'comments' => false]);
        preg_match_all('/<[^<>\s]+>/', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$value, $at]) {
            if (!str_contains($value, "\0") || $visible[$at] !== '<' || trim(substr($masked, $at, strlen($value))) !== '') {
                continue;
            }
            $masked = substr_replace($masked, $value, $at, strlen($value));
        }

        return $masked;
    }

    protected function escapePlainDjotText(string $source, string $masked): string
    {
        $masked = $this->unmaskDjotAutolinkBodies($source, $masked);
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
     *
     * @param string $source
     * @param callable|null $onFenceLine
     * @param array<int, bool> $rowBoundaries
     * @param bool $strict
     */
    private function maskDjotFences(string $source, ?callable $onFenceLine = null, array $rowBoundaries = [], bool $strict = true): string
    {
        // Stage 1: fenced blocks, line by line.
        $lines = explode("\n", $source);
        $heldFence = null;
        $previousBlock = true;
        $normalizeBoundary = true;
        $previousDepth = 0;
        $ancestors = [];
        foreach ($lines as $i => $line) {
            [$depth, $content] = $this->quoted($line);
            $canNormalize = $normalizeBoundary || ($rowBoundaries[$i - 1] ?? false) || $depth < $previousDepth;
            $previousDepth = $depth;
            if ($heldFence !== null && trim($content) !== '' && $depth < $heldFence['depth']) {
                $heldFence = null;
            }
            if ($heldFence !== null && $heldFence['container'] !== null && trim($content) !== '' && strlen($content) - strlen(ltrim($content, " \t")) < $heldFence['container'] && $depth === $heldFence['depth']) {
                $heldFence = null;
            }
            $nested = false;
            $ownerColumn = 0;
            $ownerIndent = 0;
            $ancestors = array_slice($ancestors, 0, $depth + 1);
            if ($heldFence === null && trim($content) !== '') {
                $viewOffset = 0;
                for ($level = 0; $level <= $depth; $level++) {
                    $ancestors[$level] ??= [];
                    $indent = strspn($line, " \t", $viewOffset);
                    while ($ancestors[$level] !== [] && $ancestors[$level][array_key_last($ancestors[$level])]['indent'] >= $indent) {
                        if ($level === $depth && $ancestors[$level][array_key_last($ancestors[$level])]['column'] > 0 && $indent <= $ancestors[$level][array_key_last($ancestors[$level])]['ownerIndent']) {
                            $canNormalize = true;
                        }
                        array_pop($ancestors[$level]);
                    }
                    if ($level === $depth) {
                        $ownerColumn = $ancestors[$level] === [] ? 0 : $ancestors[$level][array_key_last($ancestors[$level])]['column'];
                        $ownerIndent = $ancestors[$level] === [] ? 0 : $ancestors[$level][array_key_last($ancestors[$level])]['ownerIndent'];
                        $nested = $ownerColumn > 0;
                    }
                    $marker = false;
                    $prefix = [];
                    $footnote = false;
                    $note = [];
                    if ($level === $depth) {
                        $view = $content;
                        $marker = preg_match('/^(?:([*-])[ \t]*){3,}$/', trim($view)) !== 1 && preg_match('/^[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+\S/', $view) === 1;
                        preg_match('/^[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+/', $view, $prefix);
                        $footnote = preg_match('/^([ \t]*(?:(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+)*)\[\^[^\]\n]+\]:/', $view, $note) === 1;
                    }
                    $top = $ancestors[$level] === [] ? null : $ancestors[$level][array_key_last($ancestors[$level])];
                    $column = $footnote ? strlen(preg_replace('/\(([0-9A-Za-z]+)\)([ \t]+)/', '$1.$2', $note[1]) ?? $note[1]) + 2 : ($marker ? strlen(preg_replace('/\(([0-9A-Za-z]+)\)([ \t]+)$/', '$1.$2', $prefix[0] ?? '') ?? '') : ($top['column'] ?? 0));
                    $owningIndent = $footnote ? strlen($note[1]) : ($marker ? $indent : ($top['ownerIndent'] ?? 0));
                    if ($footnote && $marker) {
                        $ancestors[$level][] = ['indent' => $indent, 'column' => strlen(preg_replace('/\(([0-9A-Za-z]+)\)([ \t]+)$/', '$1.$2', $prefix[0] ?? '') ?? ''), 'ownerIndent' => $indent];
                    }
                    $ancestors[$level][] = ['indent' => $footnote ? strlen($note[1]) : $indent, 'column' => $column, 'ownerIndent' => $owningIndent];
                    if ($level < $depth) {
                        $viewOffset += $indent + 1;
                        if (($line[$viewOffset] ?? '') === ' ') {
                            $viewOffset++;
                        }
                    }
                }
            }

            if ($heldFence !== null) {
                $lines[$i] = $this->blanks($line);
                if ($depth === $heldFence['depth'] && preg_match('/^[ \t]*(' . $heldFence['char'] . '{' . $heldFence['length'] . ',})[ \t]*$/', $content) === 1) {
                    if ($onFenceLine !== null && $heldFence['normalize']) {
                        $onFenceLine($i, substr($line, 0, strlen($line) - strlen($content)) . $heldFence['target'] . ltrim($content, " \t"));
                    }
                    $normalizeBoundary = $heldFence['normalize'];
                    $heldFence = null;
                    $previousBlock = true;
                } elseif ($onFenceLine !== null && $heldFence['normalize'] && $depth === $heldFence['depth']) {
                    $indent = strlen($content) - strlen(ltrim($content, " \t"));
                    $onFenceLine($i, substr($line, 0, strlen($line) - strlen($content)) . $heldFence['target'] . substr($content, min($heldFence['dedent'], $indent)));
                }

                continue;
            }
            if (preg_match('/^([ \t]*)(?:(\[\^[^\]\n]+\]:[ \t]*|(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+))?(`{3,}|~{3,})[ \t]*=?[A-Za-z0-9_+#.-]*[ \t]*$/', $content, $open) === 1) {
                if ((str_starts_with($open[2], ':') && !$previousBlock) || ($strict && !$canNormalize && $open[2] === '')) {
                    continue;
                }
                $container = $open[2] !== '' ? strlen($open[1]) + 1 : ($nested ? $ownerIndent + 1 : null);
                $nativeMarker = preg_replace('/\(([0-9A-Za-z]+)\)([ \t]+)$/', '$1.$2', $open[2]) ?? $open[2];
                $targetColumn = str_starts_with($open[2], '[^') ? strlen($open[1]) + 2 : ($open[2] !== '' ? strlen($open[1]) + strlen($nativeMarker) : ($nested ? $ownerColumn : 0));
                $heldFence = ['container' => $container, 'char' => $open[3][0], 'length' => strlen($open[3]), 'depth' => $depth, 'target' => str_repeat(' ', $targetColumn), 'dedent' => strlen($open[1]) + strlen($open[2]), 'normalize' => $canNormalize || $open[2] !== ''];
                if ($onFenceLine !== null && $heldFence['normalize'] && ($open[2] === '' || $nativeMarker !== $open[2])) {
                    $openingPrefix = $open[2] === '' ? $heldFence['target'] : $open[1] . $nativeMarker;
                    $onFenceLine($i, substr($line, 0, strlen($line) - strlen($content)) . $openingPrefix . substr($content, $heldFence['dedent']));
                }
                $start = strpos($line, $open[3]);
                if ($start === false) {
                    continue;
                }
                $lines[$i] = substr($line, 0, $start) . $this->blanks(substr($line, $start));
            } else {
                $attributeStart = strlen($content) - strlen(ltrim($content));
                $lineAttributes = ($content[$attributeStart] ?? '') === '{' ? $this->readDjotWordAttributes($content, $attributeStart) : null;
                $attributeBoundary = $canNormalize && $lineAttributes !== null && $lineAttributes['end'] === strlen(rtrim($content));
                $normalizeBoundary = preg_match('/^(?:[ 	]*[-*]){3,}[ 	]*$|^[ 	]*\[(?!\^)[^\]]+\]:/', $content) === 1 || trim($content) === '' || preg_match('/^[ \t]*\[\^[^\]\n]+\]:[ \t]*$/', $content) === 1 || preg_match('/^[ \t]*(?:#{1,6} |:{3,})/', $content) === 1 || $attributeBoundary;
                $previousBlock = trim($content) === '' || preg_match('/^[ \t]*(?:[-*+] |[0-9]+[.)] |:{1,2} |#{1,6} )/', $content) === 1 || $attributeBoundary;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $source
     * @param bool $inlineForms
     * @param callable|null $onFenceLine
     * @param array<int, bool> $rowBoundaries
     * @param array{code?: bool, destinations?: bool, inlineDestinations?: bool, autolinks?: bool, attributeValues?: bool, comments?: bool, onComment?: callable(int, int): void, onDestination?: callable(int, int): void} $opaqueOptions
     * @param bool $unclosedCode
     */
    protected function maskCodeAndDestinations(
        string $source,
        bool $inlineForms = true,
        ?callable $onFenceLine = null,
        array $rowBoundaries = [],
        array $opaqueOptions = [],
        bool $unclosedCode = true,
    ): string {
        $masked = $this->maskDjotFences($source, $onFenceLine, $rowBoundaries);

        $masked = $this->maskDjotOpaque($masked, $unclosedCode, $opaqueOptions + (!$inlineForms ? ['inlineDestinations' => ($opaqueOptions['destinations'] ?? false) === true] : []));
        if (!$inlineForms) {
            return $masked;
        }

        $previousLines = $this->previousSourceLines($source);
        $masked = preg_replace_callback('/^(?:[ \t]*>)*[ \t]*(?:(?:[-*+]|[0-9]+[.)])[ \t]+)?:{3,}[ \t]+([A-Za-z_][A-Za-z0-9_.-]*)/m', function (array $match) use ($previousLines): string {
            [$value, $at] = $match[0];
            $name = $match[1][0];

            return trim($previousLines[$at] ?? '') === '' || preg_match('/(?:[-*+]|[0-9]+[.)])[ \t]+:{3,}/', $value) === 1 ? substr($value, 0, -strlen($name)) . $this->blanks($name) : $value;
        }, $masked, -1, $classCount, PREG_OFFSET_CAPTURE);

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
        $offset = 0;
        while (preg_match('/\G[ \t]*>[ ]?/', $line, $prefix, 0, $offset)) {
            $depth++;
            $offset += strlen($prefix[0]);
        }

        return [$depth, substr($line, $offset)];
    }

    /**
     * @param string $source
     *
     * @return list<int>
     */
    private function djotTableCellBoundaries(string $source): array
    {
        if (!str_contains($source, '|')) {
            return [];
        }
        $mask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['destinations' => false, 'autolinks' => false, 'attributeValues' => false]);

        return array_values(array_filter($this->djotInlineBoundaries($source, $mask), static fn (int $at): bool => ($source[$at] ?? '') === '|' && ($mask[$at] ?? '') === '|'));
    }

    /**
     * @return array<int, int>
     */
    private function djotInlineBoundaries(string $source, string $mask): array
    {
        $boundaries = [];
        $rows = $this->djotTableRows($source, $mask);
        $items = [];
        $offset = 0;
        $previousBlank = true;
        foreach (explode("\n", $source) as $row => $line) {
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $line, $quote);
            $prefix = $quote[0] ?? '';
            $depth = substr_count($prefix, '>');
            $content = substr($line, strlen($prefix));
            $trimmed = ltrim($content, " \t");
            $indent = strlen($content) - strlen($trimmed);
            preg_match('/^(?:\[\^[^\]\n]+\]:[ \t]*|(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+)/', $trimmed, $marker);
            $active = $items !== [] ? $items[array_key_last($items)] : null;
            $startsItem = $marker !== [] && ($previousBlank || ($active !== null && $depth <= $active['depth'] && $indent < $active['column']));
            $blank = $trimmed === '' || $trimmed === "\r";
            if ($previousBlank || $startsItem) {
                $boundaries[] = $offset;
                if (!$blank) {
                    while ($items !== []) {
                        $item = $items[array_key_last($items)];
                        if ($depth > $item['depth'] || ($depth === $item['depth'] && $indent >= $item['column'])) {
                            break;
                        }
                        array_pop($items);
                    }
                    if ($startsItem) {
                        $items[] = ['column' => $indent + (str_starts_with($marker[0], '[^') ? 2 : strlen($marker[0])), 'depth' => $depth];
                    }
                }
            }
            if ($rows[$row] ?? false) {
                for ($at = 0, $length = strlen($line); $at < $length; $at++) {
                    if ($line[$at] === '|' && $mask[$offset + $at] === '|' && ($line[$at - 1] ?? '') !== '\\') {
                        $boundaries[] = $offset + $at;
                    }
                }
            }
            $previousBlank = $blank;
            $offset += strlen($line) + 1;
        }

        return $boundaries;
    }

    /**
     * @return array<int, int>|null
     */
    private function djotSimpleDestinationRanges(string $source): ?array
    {
        $ranges = [];
        if (!str_contains($source, '](')) {
            return $ranges;
        }
        if (str_contains($source, '\\') || str_contains($source, '`') || str_contains($source, '][')) {
            return null;
        }
        preg_match_all('/\[[^\[\]\n]*\]\([^()\n]*\)/', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$value, $at]) {
            $labelEnd = strpos($value, '](');
            if ($labelEnd === false || ($value[1] ?? '') === '^' || str_contains($value, '|') || preg_match('/[{<"]/', substr($value, $labelEnd + 2, -1))) {
                return null;
            }
            $ranges[$at + $labelEnd + 1] = $at + strlen($value);
        }

        return count($ranges) === substr_count($source, '](') ? $ranges : null;
    }

    /**
     * @return array<int, int>
     */
    private function djotDestinationRanges(string $source, string $mask): array
    {
        $ranges = [];
        if (!str_contains($source, '](')) {
            return $ranges;
        }
        $boundaries = $this->djotInlineBoundaries($source, $mask);
        $length = strlen($source);
        $nextBracket = [];
        $close = -1;
        for ($at = $length - 1; $at >= 0; $at--) {
            if ($source[$at] === "\n") {
                $close = -1;
            } elseif ($source[$at] === ']' && !$this->isDjotEscaped($source, $at)) {
                $close = $at;
            }
            if ($at >= 2 && $source[$at - 1] === '[' && $source[$at - 2] === ']') {
                $nextBracket[$at] = $close;
            }
        }
        preg_match_all('/<[^<>\s]+>/', $source, $matches, PREG_OFFSET_CAPTURE);
        $angles = [];
        foreach ($matches[0] as [$value, $at]) {
            if (preg_match('/[^:]@|[A-Za-z]:/', $value)) {
                $angles[$at] = $at + strlen($value);
            }
        }
        $tableCode = [];
        $lineOffset = 0;
        foreach (explode("\n", $source) as $line) {
            if (preg_match('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9A-Za-z]+[.)])[ \t]+)?\|/', $line) === 1) {
                preg_match_all('/`+/', $line, $runs, PREG_OFFSET_CAPTURE);
                foreach ($runs[0] as [$run, $at]) {
                    $tableCode[$lineOffset + $at] = true;
                }
            }
            $lineOffset += strlen($line) + 1;
        }
        $labels = [];
        $owner = null;
        $boundary = 0;
        for ($at = 0; $at < $length; $at++) {
            while (isset($boundaries[$boundary]) && $boundaries[$boundary] <= $at) {
                $labels = [];
                $owner = null;
                $boundary++;
            }
            if (isset($tableCode[$at])) {
                $owner = null;
            }
            if ($mask[$at] !== $source[$at]) {
                continue;
            }
            if ($source[$at] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $source[$at + 1] ?? '') === 1) {
                $at++;

                continue;
            }
            if (isset($angles[$at])) {
                $at = $angles[$at] - 1;

                continue;
            }
            if ($source[$at] === '{') {
                $attrs = $this->readDjotWordAttributes($source, $at);
                if ($attrs !== null) {
                    $at = $attrs['end'] - 1;

                    continue;
                }
            }
            if ($source[$at] === '[') {
                $labels[] = ['at' => $at, 'parens' => 0];

                continue;
            }
            $tip = array_key_last($labels);
            if ($tip === null) {
                continue;
            }
            if ($source[$at] === ']') {
                if (($source[$labels[$tip]['at'] + 1] ?? '') === '^') {
                    array_pop($labels);
                    if ($owner === $tip) {
                        $owner = null;
                    }

                    continue;
                }
                if (($source[$at + 1] ?? '') === '[') {
                    $end = $nextBracket[$at + 2] ?? -1;
                    if ($end >= 0) {
                        array_pop($labels);
                        if ($owner === $tip) {
                            $owner = null;
                        }
                        $at = $end;
                    }

                    continue;
                }
                if (($source[$at + 1] ?? '') === '{' && $this->readDjotWordAttributes($source, $at + 1) !== null) {
                    array_pop($labels);
                    if ($owner === $tip) {
                        $owner = null;
                    }

                    continue;
                }
                if (($source[$at + 1] ?? '') === '(') {
                    $labels[$tip]['target'] = $at + 1;
                    $labels[$tip]['parens'] = 0;
                    $owner = $tip;
                    $at++;

                    continue;
                }
            }
            if ($owner === null) {
                continue;
            }
            if ($source[$at] === '(') {
                $labels[$owner]['parens']++;
            } elseif ($source[$at] === ')') {
                if ($labels[$owner]['parens'] > 0) {
                    $labels[$owner]['parens']--;

                    continue;
                }
                $ranges[$labels[$owner]['target']] = $at + 1;
                for ($remaining = count($labels); $remaining > $owner; $remaining--) {
                    array_pop($labels);
                }
                $owner = null;
            }
        }

        return $ranges;
    }

    /**
     * @param string $source
     * @param string $mask
     * @param array<int, int> $angles
     * @param array<int, array{end: int, text: string}> $edits
     * @param int $end
     * @param int $start
     */
    private function djotLinkLabel(string $source, string $mask, array $angles, array $edits, int $start, int $end): string
    {
        $label = '';
        $brackets = 0;
        for ($at = $start; $at < $end; $at++) {
            if (isset($edits[$at]) && $edits[$at]['end'] <= $end) {
                $label .= $edits[$at]['text'];
                $at = $edits[$at]['end'] - 1;

                continue;
            }
            if (isset($angles[$at])) {
                $label .= substr($source, $at, $angles[$at] - $at);
                $at = $angles[$at] - 1;

                continue;
            }
            if ($mask[$at] === ' ') {
                $label .= $source[$at];

                continue;
            }
            if ($source[$at] === '\\') {
                $label .= substr($source, $at, 2);
                $at++;

                continue;
            }
            if ($source[$at] === '[') {
                $brackets++;
            }
            if ($source[$at] === ']') {
                if ($brackets > 0) {
                    $brackets--;
                } else {
                    $label .= '\\';
                }
            }
            $label .= $source[$at];
        }

        return $label;
    }

    /**
     * @param string $source
     * @param array<string, true> $inherited
     */
    private function normalizeDjotLinks(string $source, array $inherited = []): string
    {
        if (!str_contains($source, '](')) {
            return $source;
        }
        $mask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['destinations' => false]);
        $rows = $this->djotTableRows($source, $mask);
        preg_match_all('/<[^<>\s]+>/', $source, $angleMatches, PREG_OFFSET_CAPTURE);
        $angles = [];
        foreach ($angleMatches[0] as [$value, $at]) {
            if (preg_match('/[^:]@|[A-Za-z]:/', $value)) {
                $angles[$at] = $at + strlen($value);
            }
        }
        $quoteDepths = array_map(static function (string $line): int {
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $line, $prefix);

            return substr_count($prefix[0] ?? '', '>');
        }, explode("\n", $source));
        $stack = $edits = $pendingNotes = [];
        $line = 0;
        $boundaries = array_fill_keys($this->djotInlineBoundaries($source, $mask), true);
        $destinationOwner = null;
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            if (isset($boundaries[$i])) {
                if ($destinationOwner !== null && isset($stack[$destinationOwner])) {
                    $at = $stack[$destinationOwner]['target'] - 1;
                    $edits[$at] = ['end' => $at + 1, 'text' => '\\('];
                }
                $stack = [];
                $destinationOwner = null;
                $pendingNotes = [];
            }
            if ($source[$i] === "\n") {
                $line++;
                if (preg_match('/\G\n[ \t]*(?:>[ \t]*)*\n/', $source, offset: $i)) {
                    if ($destinationOwner !== null && isset($stack[$destinationOwner])) {
                        $at = $stack[$destinationOwner]['target'] - 1;
                        $edits[$at] = ['end' => $at + 1, 'text' => '\\('];
                    }
                    $stack = [];
                    $destinationOwner = null;
                    $pendingNotes = [];
                    $literalNotes = [];

                    continue;
                }
            }
            if ($mask[$i] === ' ') {
                continue;
            }
            if ($source[$i] === '\\') {
                $i++;

                continue;
            }
            if (isset($angles[$i])) {
                $i = $angles[$i] - 1;

                continue;
            }
            $attrs = $source[$i] === '{' ? $this->readDjotWordAttributes($source, $i) : null;
            if ($attrs !== null) {
                $i = $attrs['end'] - 1;

                continue;
            }
            if (($rows[$line] ?? false) && $source[$i] === '|' && ($source[$i - 1] ?? '') !== '\\') {
                if ($destinationOwner !== null && isset($stack[$destinationOwner])) {
                    $at = $stack[$destinationOwner]['target'] - 1;
                    $edits[$at] = ['end' => $at + 1, 'text' => '\\('];
                }
                $stack = [];
                $destinationOwner = null;
                $pendingNotes = [];

                continue;
            }
            if ($source[$i] === '[') {
                if (count($stack) >= 200) {
                    return $source;
                }
                $stack[] = ['at' => $i, 'depth' => $quoteDepths[$line] ?? 0, 'parens' => 0];
                if (($source[$i + 1] ?? '') === '^') {
                    $pendingNotes[$i] = true;
                }

                continue;
            }
            $tip = array_key_last($stack);
            if ($tip === null) {
                continue;
            }
            if ($source[$i] === ']') {
                if (($source[$stack[$tip]['at'] + 1] ?? '') === '^') {
                    $literalNotes[$stack[$tip]['at']] = $i + 1;
                    unset($pendingNotes[$stack[$tip]['at']]);
                    array_pop($stack);

                    continue;
                }
                if (($source[$i + 1] ?? '') === '(') {
                    if ($destinationOwner !== null && $destinationOwner !== $tip) {
                        $at = $stack[$destinationOwner]['target'] - 1;
                        $edits[$at] = ['end' => $at + 1, 'text' => '\\('];
                    }
                    $stack[$tip]['labelEnd'] = $i;
                    $stack[$tip]['target'] = $i + 2;
                    $stack[$tip]['parens'] = 0;
                    $destinationOwner = $tip;
                    $i++;

                    continue;
                }
                if (($source[$i + 1] ?? '') === '[') {
                    $end = $i + 2;
                    while ($end < $length && $source[$end] !== ']' && $source[$end] !== '[') {
                        if ($source[$end] === '\\') {
                            $end++;
                        } $end++;
                    }
                    if (($source[$end] ?? '') === ']' && ($mask[$end] ?? '') === ']') {
                        if (isset($stack[$tip]['target'])) {
                            $at = $stack[$tip]['at'];
                            $edits[$at] = ['end' => $i + 1, 'text' => '[' . $this->djotLinkLabel($source, $mask, $angles, $edits, $at + 1, $i) . ']'];
                        }
                        if ($destinationOwner === $tip) {
                            $destinationOwner = null;
                        }
                        array_pop($stack);
                        $i = $end;
                    }

                    continue;
                }
                $rejected = ($source[$i + 1] ?? '') === '\\' && ($source[$i + 2] ?? '') === '{'
                    && preg_match('/\G\x00DJOTINVALIDATTR\x00[0-9]+\x00/', $source, $marker, offset: $i + 3) === 1
                    && isset($inherited[$marker[0]]);
                if (($source[$i + 1] ?? '') === '{' || $rejected) {
                    if (isset($stack[$tip]['target'])) {
                        $at = $stack[$tip]['at'];
                        $text = ($source[$i + 1] ?? '') === '{' && $this->readDjotWordAttributes($source, $i + 1) !== null
                            ? '[' . $this->djotLinkLabel($source, $mask, $angles, $edits, $at + 1, $i) . ']'
                            : '\\[' . substr($source, $at + 1, $i - $at);
                        $edits[$at] = ['end' => $i + 1, 'text' => $text];
                    }
                    if ($destinationOwner === $tip) {
                        $destinationOwner = null;
                    }
                    array_pop($stack);
                }
            }
            if ($destinationOwner !== null && $source[$i] === '(') {
                $stack[$destinationOwner]['parens']++;
            }
            if ($source[$i] !== ')' || $destinationOwner === null) {
                continue;
            }
            $owner = $stack[$destinationOwner];
            if ($owner['parens'] > 0) {
                $stack[$destinationOwner]['parens']--;

                continue;
            }
            if ($tip !== $destinationOwner) {
                $edits[$owner['target'] - 1] = ['end' => $owner['target'], 'text' => '\\('];
                $stack = [];
                $destinationOwner = null;
                $pendingNotes = [];

                continue;
            }
            $label = $this->djotLinkLabel($source, $mask, $angles, $edits, $owner['at'] + 1, $owner['labelEnd']);
            $rawDestination = '';
            for ($at = $owner['target']; $at < $i;) {
                $end = !($rows[$line] ?? false) ? ($literalNotes[$at] ?? null) : null;
                if ($end !== null && $end <= $i) {
                    $rawDestination .= preg_replace('/\n[ \t]*/', ' ', str_replace('\\', '%5C', substr($source, $at, $end - $at)));
                    $at = $end;
                } else {
                    $rawDestination .= $source[$at++];
                }
            }
            $destination = $this->djotDestinationLines($rawDestination, $owner['depth']);
            if ($rows[$line] ?? false) {
                $destination = preg_replace_callback('/\\\\+\|/', static fn (array $match): string => str_repeat('%5C', intdiv(strlen($match[0]) - 1, 2)) . '%7C', $destination) ?? $destination;
            }
            if ($owner['at'] > 0 && $source[$owner['at'] - 1] === '!' && !$this->isDjotEscaped($source, $owner['at'] - 1) && str_contains($label, '[')) {
                $label = (new CarveConverter(smartTypography: false, renderer: new PlainTextRenderer()))->convert(str_replace("\0U\0", "\0", $this->convert('DJOTALT ' . $this->removeInheritedAttributeMarkers($label, $inherited) . ' DJOTEND')));
                $label = substr(preg_replace('/ DJOTEND\n?$/', '', $label) ?? $label, 8);
                $label = str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $label);
            }
            $target = preg_replace_callback(($rows[$line] ?? false) ? '/\\\\([ \t!-\/:-@\[-`{-~])|[\\\\\s"<>`()|]/u' : '/\\\\([ \t!-\/:-@\[-`{-~])|[\\\\\s"<>`()]/u', static fn (array $match): string => isset($match[1]) ? (str_contains('()\\', $match[1]) ? '\\' . $match[1] : (str_contains(" \t\"<>`", $match[1]) ? rawurlencode($match[1]) : $match[1])) : ($match[0] === '\\' ? '\\\\' : rawurlencode($match[0])), $destination) ?? $destination;
            $target = preg_replace('/\](?=[\[{])/', '%5D', $target) ?? $target;
            $edits[$owner['at']] = ['end' => $i + 1, 'text' => '[' . $label . '](' . $target . ')'];
            array_pop($stack);
            $destinationOwner = null;
            foreach ($pendingNotes as $at => $_) {
                $edits[$at] = ['end' => $at + 1, 'text' => '\\['];
            } $pendingNotes = [];
        }
        if ($destinationOwner !== null && isset($stack[$destinationOwner])) {
            $at = $stack[$destinationOwner]['target'] - 1;
            $edits[$at] = ['end' => $at + 1, 'text' => '\\('];
        }
        $stack = [];
        $destinationOwner = null;
        $pendingNotes = [];
        $output = '';
        for ($i = 0; $i < $length;) {
            if (isset($edits[$i])) {
                $output .= $edits[$i]['text'];
                $i = $edits[$i]['end'];
            } else {
                $output .= $source[$i++];
            }
        }

        return $output;
    }

    private function normalizeDjotAttributeLines(string $source): string
    {
        if (!str_contains($source, '{')) {
            return $source;
        }
        $mask = $this->maskDjotAttributeSource($source);
        $closes = [];
        $close = null;
        for ($at = strlen($source) - 1; $at >= 0; $at--) {
            if ($source[$at] === "\n") {
                $close = null;
            } elseif ($source[$at] === '}') {
                $close = $at + 1;
            } elseif ($source[$at] === '{' && $close !== null) {
                $closes[$at] = $close;
            }
        }
        $output = '';
        $copied = 0;
        for ($i = 0, $length = strlen($source); $i < $length; $i++) {
            if ($mask[$i] !== '{' || $this->isDjotEscaped($source, $i)) {
                continue;
            }
            $attrs = $this->readDjotWordAttributes($source, $i);
            if ($attrs === null) {
                $end = $closes[$i] ?? null;
                if ($end !== null && preg_match('/\G[A-Za-z][A-Za-z0-9_-]*=/', $source, offset: $i + 1)) {
                    $raw = substr($source, $i, $end - $i);
                    $output .= substr($source, $copied, $i - $copied) . strtr($raw, ['{' => '\\{', '}' => '\\}', '[' => '\\[', ']' => '\\]']);
                    $copied = $end;
                    $i = $end - 1;
                }

                continue;
            }
            if (str_contains(substr($source, $i, $attrs['end'] - $i), "\n")) {
                $output .= substr($source, $copied, $i - $copied) . $attrs['source'];
                $copied = $attrs['end'];
            }
            $i = $attrs['end'] - 1;
        }

        return $output . substr($source, $copied);
    }

    private function foldDjotReferences(string $source): string
    {
        if (!str_contains($source, ']: ') && !str_contains($source, ']:')) {
            return $source;
        }
        $lines = explode("\n", $source);
        $mask = $this->maskCodeAndDestinations($source, false);
        $rows = $this->djotTableRows($source, $mask);
        $removed = [];
        $offset = 0;
        $previous = '';
        $previousDepth = 0;
        $previousAttributeBlock = false;
        for ($n = 0, $count = count($lines); $n < $count; $n++) {
            $line = $lines[$n];
            [$depth] = $this->quoted($line);
            $at = $this->djotContentStart($line);
            $content = substr($line, $at);
            $definition = preg_match('/^\[(?!\^)([^\]\n]*)\]:(?:[ \t]+(\S*)[ \t]*|)$/', $content, $match) === 1;
            $previousLine = $lines[$n - 1] ?? '';
            $previousAt = $this->djotContentStart($previousLine);
            $boundary = $previous === '' || $previousAttributeBlock || ($rows[$n - 1] ?? false) || $depth !== $previousDepth || preg_match('/^(?:[ \t]*>[ \t]*)*[ \t]*(?:[-*][ \t]*){3,}$/', $previousLine) === 1 || $at < $previousAt && preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', substr($previousLine, 0, $previousAt)) === 1 || preg_match('/^(?:#{1,6} |:{3,}|\[[^\]]*\]:|(?:[-*][ \t]*){3,}$)/', $previous) === 1;
            $marker = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', substr($line, 0, $at)) === 1;
            if ($definition && ($mask[$offset + $at] ?? '') === '[' && ($boundary || $marker)) {
                $target = $match[2] ?? '';
                $end = $n;
                while ($end + 1 < $count) {
                    $next = $lines[$end + 1];
                    [$nextDepth] = $this->quoted($next);
                    $nextAt = $this->djotContentStart($next);
                    if ($nextDepth !== $depth || $nextAt <= $at || preg_match('/^\S+$/', substr($next, $nextAt)) !== 1) {
                        break;
                    }
                    $target .= substr($next, $nextAt);
                    $end++;
                }
                if ($end > $n) {
                    $lines[$n] = substr($line, 0, $at) . '[' . $match[1] . ']: ' . $target;
                    for ($k = $n + 1; $k <= $end; $k++) {
                        $offset += strlen($lines[$k]) + 1;
                        $removed[$k] = true;
                    }
                    $n = $end;
                }
            }
            if ($definition && ($mask[$offset + $at] ?? '') === '[' && !($boundary || $marker)) {
                $colon = strpos($line, ']:', $at) + 1;
                $lines[$n] = substr($line, 0, $colon) . '\\' . substr($line, $colon);
            }
            $attrs = ($content[0] ?? '') === '{' ? $this->readDjotWordAttributes($content, 0) : null;
            $previousAttributeBlock = ($boundary || $marker) && $attrs !== null && $attrs['end'] === strlen(rtrim($content));
            $previous = trim($content);
            $previousDepth = $depth;
            $offset += strlen($line) + 1;
        }

        return implode("\n", array_filter($lines, static fn (int $n): bool => !isset($removed[$n]), ARRAY_FILTER_USE_KEY));
    }

    private function normalizeDjotParagraphFences(string $source): string
    {
        $rows = $this->djotTableRows($source, $this->maskCodeAndDestinations($source, false));
        $mask = $this->maskDjotFences($source, null, $rows, true);
        if (!str_contains($mask, '```')) {
            return $source;
        }
        preg_match_all('/`+/', $mask, $matches, PREG_OFFSET_CAPTURE);
        $runs = [];
        $next = [];
        $length = strlen($source);
        for ($k = count($matches[0]) - 1; $k >= 0; $k--) {
            [$run, $at] = $matches[0][$k];
            $width = strlen($run);
            $runs[$at] = [$width, $next[$width] ?? $length];
            $next[$width] = $at;
        }
        preg_match_all('/\n[ \t]*(?:>[ \t]*)*\n/', $mask, $breakMatches, PREG_OFFSET_CAPTURE);
        $breaks = array_column($breakMatches[0], 1);
        preg_match_all('/<[^<>\s]+>/', $mask, $angleMatches, PREG_OFFSET_CAPTURE);
        $angles = [];
        foreach ($angleMatches[0] as [$angle, $at]) {
            if (preg_match('/[^:]@|[A-Za-z]:/', $angle)) {
                $angles[$at] = $at + strlen($angle);
            }
        }
        $heads = [];
        $lineOffset = 0;
        foreach (explode("\n", $source) as $line) {
            preg_match('/^[ \t]*(?:>[ ]?[ \t]*)*/', $line, $head);
            $heads[$lineOffset + strlen($head[0] ?? '')] = true;
            $lineOffset += strlen($line) + 1;
        }
        $output = '';
        $copied = 0;
        $boundary = 0;
        for ($i = 0; $i < $length;) {
            if ($mask[$i] === ' ') {
                $i++;

                continue;
            }
            if ($source[$i] === '\\') {
                $i += 2;

                continue;
            }
            if (isset($angles[$i])) {
                $i = $angles[$i];

                continue;
            }
            while (($breaks[$boundary] ?? $length) <= $i && isset($breaks[$boundary])) {
                $boundary++;
            }
            if (isset($runs[$i])) {
                [$width, $closer] = $runs[$i];
                $end = min($closer, $breaks[$boundary] ?? $length);
                $closed = $closer < ($breaks[$boundary] ?? $length);
                $payload = substr($source, $i + $width, $end - $i - $width);
                if (!$closed && $end === $length) {
                    $payload = preg_replace('/\n[ \t]*(?:>[ \t]*)*$/', '', $payload) ?? $payload;
                }
                if ($width >= 3 && isset($heads[$i])) {
                    preg_match_all('/`+/', $payload, $payloadRuns);
                    $widths = array_fill_keys(array_map('strlen', $payloadRuns[0]), true);
                    $nativeWidth = 1;
                    while (isset($widths[$nativeWidth])) {
                        $nativeWidth++;
                    }
                    $ticks = str_repeat('`', $nativeWidth);
                    $output .= substr($source, $copied, $i - $copied) . ($payload === '' ? '`<code></code>`{=html}' : ($nativeWidth >= 3 ? '{%%}' : '') . $ticks . $payload . $ticks);
                    $copied = $end + ($closed ? $width : 0);
                }
                $i = $end + ($closed ? $width : 0);

                continue;
            }
            $i++;
        }

        return $output . substr($source, $copied);
    }

    /**
     * @param string $source
     * @param \Closure|null $isDefinitionBoundary
     * @param array<string, true> $inherited
     */
    private function normalizeDjotFootnotes(string $source, ?Closure $isDefinitionBoundary = null, array $inherited = []): string
    {
        if (!str_contains($source, '[^')) {
            return $source;
        }
        $mask = $this->maskCodeAndDestinations($source, false);
        $rows = $this->djotTableRows($source, $mask);
        $labels = $definitions = $defined = $used = [];
        $nonRows = [];
        $rowOffset = 0;
        foreach (explode("\n", $source) as $n => $line) {
            $at = $this->djotContentStart($line);
            if (!($rows[$n] ?? false) && ($line[$at] ?? '') === '|' && ($mask[$rowOffset + $at] ?? '') === '|') {
                $nonRows[$rowOffset + $at] = true;
            } $rowOffset += strlen($line) + 1;
        }
        preg_match_all('/carve-djot-note-(\d+)/i', $source, $reservedMatches);
        $reserved = array_fill_keys(array_map('intval', $reservedMatches[1]), true);
        $serial = 0;
        $keyOf = function (string $key) use ($inherited): string {
            $key = $this->removeInheritedAttributeMarkers($key, $inherited);
            $edge = '\x09-\x0D \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
            $key = preg_replace('/^[' . $edge . ']+|[' . $edge . ']+$/u', '', $key) ?? $key;

            return preg_replace('/[ \t\r\n]+/', ' ', $key) ?? $key;
        };
        $unsupported = static fn (string $key): bool => preg_match('/[\[\]`<>|\\\\\f\x00]/', $key) === 1;
        $alias = static function (string $key) use (&$labels, &$serial, $reserved): string {
            if (!isset($labels[$key])) {
                while (isset($reserved[$serial])) {
                    $serial++;
                }
                $labels[$key] = 'carve-djot-note-' . $serial++;
            }

            return $labels[$key];
        };
        $offset = 0;
        $lineHeads = [];
        $emptyDefinitions = [];
        $noteLines = explode("\n", $source);
        foreach ($noteLines as $n => $line) {
            preg_match('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+)*/', $line, $prefix);
            $prefixText = $prefix[0] ?? '';
            $at = strlen($prefixText);
            $lineHeads[$offset + $at] = true;
            $previousLine = $noteLines[$n - 1] ?? '';
            preg_match('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+)*/', $previousLine, $previousPrefix);
            $previousPrefixText = $previousPrefix[0] ?? '';
            $previous = trim(substr($previousLine, strlen($previousPrefixText)));
            $item = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', $prefixText) === 1;
            $boundary = ($isDefinitionBoundary !== null && $isDefinitionBoundary($previousLine)) || $previous === '' || ($rows[$n - 1] ?? false) || $item || preg_match('/^(?:[ \t]*>[ \t]*)*[ \t]*(?:[-*][ \t]*){3,}$/', $previousLine) === 1 || $at < strlen($previousPrefixText) && preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', $previousPrefixText) === 1 || substr_count($prefixText, '>') < substr_count($previousPrefixText, '>') || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{|\[[^\]]*\]:|(?:[-*][ \t]*){3,}$)/', $previous) === 1;
            if ($boundary && preg_match('/^\[\^([^\]\n]+)\]:(?:[ \t]|$)/', substr($line, $at), $head) && ($mask[$offset + $at] ?? '') === '[') {
                $key = $keyOf($head[1]);
                $defined[$key] = true;
                $definitions[$offset + $at] = [$key, $offset + $at + 2 + strlen($head[1])];
                if (trim(substr($line, $at + strlen($head[0]))) === '') {
                    $emptyDefinitions[$offset + $at] = true;
                }
                if ($unsupported($key)) {
                    $alias($key);
                }
            }
            $offset += strlen($line) + 1;
        }
        preg_match_all('/<[^<>\s]+>/', $source, $angleMatches, PREG_OFFSET_CAPTURE);
        $angles = [];
        foreach ($angleMatches[0] as [$angle, $at]) {
            if (preg_match('/[^:]@|[A-Za-z]:/', $angle)) {
                $angles[$at] = $at + strlen($angle);
            }
        }
        $length = strlen($source);
        $destinations = [];
        $acceptedDestinations = [];
        $destinationLine = 0;
        $parens = [];
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;

                continue;
            }
            if ($source[$i] === "\n") {
                $destinationLine++;
            }
            if (($rows[$destinationLine] ?? false) && $source[$i] === '|' && ($source[$i - 1] ?? '') !== '\\') {
                $parens = [];
            }
            if ($source[$i] === '(') {
                $parens[] = $i;
            } elseif ($source[$i] === ')' && $parens !== []) {
                $at = array_pop($parens);
                if ($at > 0 && $source[$at - 1] === ']') {
                    $destinations[$at] = $i + 1;
                }
            }
        }
        $malformedEnds = [];
        $nextBrace = null;
        for ($at = $length - 1; $at >= 0; $at--) {
            if ($source[$at] === "\n") {
                $nextBrace = null;
            } elseif ($source[$at] === '}') {
                $nextBrace = $at + 1;
            } elseif ($source[$at] === '{' && $nextBrace !== null && preg_match('/\G\{(\x00DJOTINVALIDATTR\x00[0-9]+\x00)?[A-Za-z][\w-]*=/', $source, $malformed, offset: $at) === 1 && (empty($malformed[1]) || isset($inherited[$malformed[1]]))) {
                $malformedEnds[$at] = $nextBrace;
            }
        }
        $brackets = $stack = [];
        $lineIndex = 0;
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] === "\n") {
                $lineIndex++;
                if (preg_match('/\G\n[ \t]*(?:>[ \t]*)*\n/', $source, offset: $i)) {
                    $stack = [];
                }
            }
            if ($source[$i] === '\\') {
                $i++;

                continue;
            }
            if ($source[$i] === '{') {
                $attrs = $this->readDjotWordAttributes($source, $i);
                if ($attrs !== null) {
                    $i = $attrs['end'] - 1;

                    continue;
                }
                if (isset($malformedEnds[$i])) {
                    $i = $malformedEnds[$i] - 1;

                    continue;
                }
            }
            if (isset($angles[$i])) {
                $i = $angles[$i] - 1;

                continue;
            }
            if ($mask[$i] === ' ') {
                continue;
            }
            if (($rows[$lineIndex] ?? false) && $source[$i] === '|' && ($source[$i - 1] ?? '') !== '\\') {
                $stack = [];

                continue;
            }
            if (isset($definitions[$i])) {
                $i = $definitions[$i][1];

                continue;
            }
            if ($source[$i] === '[') {
                $stack[] = $i;
            } elseif ($source[$i] === ']' && $stack !== []) {
                $open = $stack[array_key_last($stack)];
                if (($source[$open + 1] ?? '') === '^') {
                    $brackets[array_pop($stack)] = $i;
                } elseif (($source[$i + 1] ?? '') === '(' && isset($destinations[$i + 1])) {
                    $brackets[array_pop($stack)] = $i;
                    $acceptedDestinations[$i + 1] = true;
                    $i = $destinations[$i + 1] - 1;
                } elseif (($source[$i + 1] ?? '') === '[') {
                    $end = $i + 2;
                    while ($end < $length && $source[$end] !== ']') {
                        if ($source[$end] === '\\') {
                            $end++;
                        } $end++;
                    }
                    if (($source[$end] ?? '') === ']') {
                        $brackets[array_pop($stack)] = $i;
                        $i = $end;
                    }
                } elseif (($source[$i + 1] ?? '') === '{' && $this->readDjotWordAttributes($source, $i + 1) !== null) {
                    $brackets[array_pop($stack)] = $i;
                }
            }
        }
        $imageEnds = [];
        foreach ($brackets as $at => $close) {
            if ($at > 0 && $source[$at - 1] === '!' && str_contains('[(', $source[$close + 1] ?? "\0")) {
                $imageEnds[$at] = $close;
            }
        }
        $output = '';
        for ($i = 0; $i < $length;) {
            if (isset($destinations[$i], $acceptedDestinations[$i])) {
                $end = $destinations[$i];
                $output .= substr($source, $i, $end - $i);
                $i = $end;

                continue;
            }
            if (isset($nonRows[$i])) {
                $output .= '\\';
            }
            if (isset($imageEnds[$i])) {
                $end = $imageEnds[$i];
                $cursor = $i;
                for ($at = $i + 1; $at < $end; $at++) {
                    $close = $brackets[$at] ?? null;
                    if (substr($source, $at, 2) === '[^' && $close !== null && $close < $end) {
                        $output .= substr($source, $cursor, $at - $cursor);
                        $cursor = $close + 1;
                        $at = $close;
                    }
                }
                $output .= substr($source, $cursor, $end + 1 - $cursor);
                $i = $end + 1;

                continue;
            }
            if ($source[$i] === '{' && $this->readDjotWordAttributes($source, $i) === null && isset($malformedEnds[$i])) {
                $end = $malformedEnds[$i];
                $output .= substr($source, $i, $end - $i);
                $i = $end;

                continue;
            }
            $attrs = $source[$i] === '{' ? $this->readDjotWordAttributes($source, $i) : null;
            if ($attrs !== null) {
                $output .= substr($source, $i, $attrs['end'] - $i);
                $i = $attrs['end'];

                continue;
            }
            $definition = $definitions[$i] ?? null;
            $end = $definition[1] ?? $brackets[$i] ?? null;
            if (substr($source, $i, 2) === '[^' && ($definition !== null || $mask[$i] === '[') && $end !== null) {
                $key = $definition[0] ?? $keyOf(substr($source, $i + 2, $end - $i - 2));
                $rename = isset($labels[$key]) || $unsupported($key) || !isset($defined[$key]);
                $name = $rename ? $alias($key) : $key;
                $at = $i;
                if ($definition === null) {
                    $used[$key] = true;
                }
                $output .= $rename || $key !== substr($source, $i + 2, $end - $i - 2) ? '[^' . $name . ']' : substr($source, $i, $end + 1 - $i);
                $i = $end + 1;
                if ($definition !== null && isset($emptyDefinitions[$at])) {
                    $output .= ': %%%%';
                    $i++;
                }
                if ($definition === null && ($source[$i] ?? '') === ':' && isset($lineHeads[$at])) {
                    $output .= '\\:';
                    $i++;
                }

                continue;
            }
            $output .= $source[$i++];
        }
        $stubs = [];
        foreach ($used as $key => $_) {
            if (!isset($defined[$key])) {
                $stubs[] = '[^' . $alias((string)$key) . ']: %%%%';
            }
        }

        return ($stubs !== [] ? implode("\n\n", $stubs) . "\n\n" : '') . $output;
    }

    private function normalizeDjotFences(string $source): string
    {
        if (!str_contains($source, '```') && !str_contains($source, '~~~')) {
            return str_contains($source, '\\|') ? $this->closeDjotTableCode($source) : $source;
        }
        $lines = explode("\n", $source);
        $rows = $this->djotTableRows($source, $this->maskCodeAndDestinations($source, false));
        $this->maskCodeAndDestinations($source, false, static function (int $line, string $replacement) use (&$lines): void {
            $lines[$line] = $replacement;
        }, $rows);

        $normalized = $this->normalizeDjotParagraphFences(implode("\n", $lines));

        $inlineFence = false;
        foreach (explode("\n", $source) as $line) {
            [, $content] = $this->quoted($line);
            if (preg_match('/^[ \t]+`{3,}/', $content)) {
                $inlineFence = true;

                break;
            }
        }

        return str_contains($source, '\\|') || $inlineFence ? $this->closeDjotTableCode($normalized) : $normalized;
    }

    private function normalizeDjotAutolinks(string $source): string
    {
        if (!str_contains($source, '<')) {
            return $source;
        }
        $codeMask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['autolinks' => false]);
        $mask = $this->maskCodeAndDestinations($source, opaqueOptions: ['autolinks' => false]);
        $rows = $this->djotTableRows($source, $codeMask);
        $definitionIndent = -1;
        $definitionOffset = 0;
        $previousContent = '';
        foreach (explode("\n", $source) as $definitionLine => $line) {
            $at = $this->djotContentStart($line);
            $content = substr($line, $at);
            $boundary = $previousContent === '' || ($rows[$definitionLine - 1] ?? false) || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{[ \t.#A-Za-z}%]|\[(?!\^)[^\]]*\]:)/', $previousContent) === 1;
            $definition = ($codeMask[$definitionOffset + $at] ?? '') === '[' && preg_match('/^\[(?!\^)[^\]\n]*\]:/', $content) === 1 && $boundary;
            $continuation = $definitionIndent >= 0 && $at > $definitionIndent && preg_match('/^\S+$/', $content) === 1;
            if ($definition || $continuation) {
                for ($i = 0, $lineLength = strlen($line); $i < $lineLength; $i++) {
                    $mask[$definitionOffset + $i] = ' ';
                }
                if ($definition) {
                    $definitionIndent = $at;
                }
            } else {
                $definitionIndent = -1;
            }
            $previousContent = trim($content);
            $definitionOffset += strlen($line) + 1;
        }

        preg_match_all('/<[^<>\s]+>/', $source, $angleMatches, PREG_OFFSET_CAPTURE);
        $angleEnds = [];
        foreach ($angleMatches[0] as [$value, $at]) {
            if (preg_match('/[^:]@|[A-Za-z]:/', $value)) {
                $angleEnds[$at] = $at + strlen($value);
            }
        }
        $imageAutolinks = [];
        $parenEnds = [];
        $parens = [];
        $quote = '';
        for ($i = 0, $length = strlen($source); $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;

                continue;
            }
            if ($quote !== '') {
                if ($source[$i] === $quote || $source[$i] === "\n") {
                    $quote = '';
                }

                continue;
            }
            if ($parens !== [] && in_array($source[$i - 1] ?? '', [' ', "\t"], true) && in_array($source[$i], ['"', "'"], true)) {
                $quote = $source[$i];

                continue;
            }
            if ($source[$i] === '(') {
                $parens[] = $i;
            }
            if ($source[$i] === ')' && $parens !== []) {
                $parenEnds[array_pop($parens)] = $i;
            }
        }
        $bracketEnds = [];
        $nestedBrackets = [];
        $stack = [];
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;

                continue;
            }
            if (isset($angleEnds[$i])) {
                $i = $angleEnds[$i] - 1;

                continue;
            }
            if ($source[$i] === '[') {
                if ($stack !== []) {
                    $nestedBrackets[$stack[array_key_last($stack)]] = true;
                }
                $stack[] = $i;
            }
            if ($source[$i] === ']' && $stack !== []) {
                $bracketEnds[array_pop($stack)] = $i;
            }
        }
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;

                continue;
            }
            $end = null;
            if ($source[$i] === '{' && $mask[$i] === '{') {
                $end = $this->readDjotWordAttributes($source, $i)['end'] ?? null;
            }
            if ($source[$i] === '!' && $mask[$i] === '!' && ($source[$i + 1] ?? '') === '[') {
                $close = $bracketEnds[$i + 1] ?? null;
                if ($close !== null && in_array($source[$close + 1] ?? '', ['(', '['], true)) {
                    $angles = [];
                    $plain = true;
                    for ($at = $i + 2; $at < $close; $at++) {
                        $angleEnd = $angleEnds[$at] ?? null;
                        if ($angleEnd !== null && $angleEnd <= $close) {
                            $angles[] = $at;
                            $at = $angleEnd - 1;
                        } elseif (str_contains('`{_*~^\\[', $source[$at])) {
                            $plain = false;

                            break;
                        }
                    }
                    if ($plain) {
                        foreach ($angles as $at) {
                            $imageAutolinks[$at] = true;
                        }
                    }
                    $end = $close + 1;
                }
            }
            if ($source[$i] === ']' && ($source[$i + 1] ?? '') === '[') {
                $close = $bracketEnds[$i + 1] ?? null;
                if ($close !== null) {
                    $end = $close + 1;
                }
            }
            if ($source[$i] === ']' && ($source[$i + 1] ?? '') === '(') {
                $close = $parenEnds[$i + 1] ?? null;
                if ($close !== null) {
                    $end = $close + 1;
                }
            }
            if ($source[$i] === '[' && ($source[$i + 1] ?? '') === '^') {
                $close = $bracketEnds[$i] ?? null;
                if ($close !== null && !isset($nestedBrackets[$i])) {
                    $end = $close + 1;
                }
            }
            if ($end !== null) {
                for ($at = $i; $at < $end; $at++) {
                    if ($mask[$at] !== "\n") {
                        $mask[$at] = ' ';
                    }
                }
                $i = $end - 1;
            }
        }
        preg_match_all('/<([^<>\s]+)>/', $source, $matches, PREG_OFFSET_CAPTURE);
        $parts = [];
        $copied = 0;
        $line = 0;
        $offset = 0;
        foreach ($matches[0] as $index => [$value, $at]) {
            while ($offset < $at) {
                if ($source[$offset] === "\n") {
                    $line++;
                }
                $offset++;
            }
            $image = isset($imageAutolinks[$at]) && $codeMask[$at] === '<';
            if ((!$image && $mask[$at] !== '<') || $this->isDjotEscaped($source, $at)) {
                continue;
            }
            $body = $matches[1][$index][0];
            $angleAttributes = false;
            for ($brace = strpos($body, '{'); $brace !== false; $brace = strpos($body, '{', $brace + 1)) {
                if ($this->readDjotWordAttributes($body, $brace) !== null) {
                    $angleAttributes = true;

                    break;
                }
            }
            // A body that already carries a scheme is a URL, not a bare address: djot.js
            // prefixes it with a second "mailto:" (jgm/djot.js#162), which we do not copy.
            $email = preg_match('/[^:]@/', $body) === 1 && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $body) !== 1;
            // Such an address keeps its autolink, but only where Carve reads one back: a
            // dash run or an ellipsis inside it becomes punctuation instead.
            $written = $angleAttributes
                || strpbrk($body, '[]{}`|\\') !== false
                || ($email && str_contains($body, ':'))
                || (!$email && preg_match('/[^:]@/', $body) === 1 && preg_match('/--|\.\.\./', $body) === 1);
            if (!preg_match('/[^:]@|[A-Za-z]:/', $body) || (!$image && !$written)) {
                continue;
            }
            if ($rows[$line] && strpbrk($body, '|`')) {
                continue;
            }
            $label = preg_replace('/([!-\/:-@\[-`{-~])/', '\\\\$1', $body) ?? $body;
            $target = $email ? 'mailto:' . $body : $body;
            preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://[^/?#\\\\]*~', $target, $authorityMatch);
            $authority = strlen($authorityMatch[0] ?? '');
            $encoding = ['`' => '%60', '|' => '%7C', '\\' => '\\\\', '(' => '%28', ')' => '%29'];
            $target = strtr(substr($target, 0, $authority), $encoding) . strtr(substr($target, $authority), $encoding + ['[' => '%5B', ']' => '%5D']);
            $parts[] = substr($source, $copied, $at - $copied);
            $parts[] = $image ? $label : '[' . $label . '](' . $target . ')';
            $copied = $at + strlen($value);
        }
        $parts[] = substr($source, $copied);

        return implode('', $parts);
    }

    /**
     * Does the line open a list item: a bullet, or an ordered marker, followed
     * by a space and content?
     */
    protected function isMarkerLine(string $line): bool
    {
        return (bool)preg_match('/^[ \t]*(?:[-*+]|[0-9A-Za-z]+[.)])[ \t]+\S/', $line);
    }

    private function maskFootnoteTokens(string $source, string $pattern, bool $preserveEscaped = false): string
    {
        $lines = explode("\n", $source);
        foreach ($lines as &$line) {
            $close = strrpos($line, ']');
            if ($close === false) {
                continue;
            }
            $end = min(strlen($line), $close + 2);
            preg_match_all($pattern, substr($line, 0, $end), $matches, PREG_OFFSET_CAPTURE);
            $parts = [];
            $cursor = 0;
            foreach ($matches[0] as [$text, $at]) {
                $begin = $at;
                while ($begin > 0 && $line[$begin - 1] === '\\') {
                    $begin--;
                }
                $parts[] = substr($line, $cursor, $at - $cursor);
                $parts[] = $preserveEscaped && ($at - $begin) % 2 !== 0 ? $text : $this->blanks($text);
                $cursor = $at + strlen($text);
            }
            $parts[] = substr($line, $cursor);
            $line = implode('', $parts);
        }
        unset($line);

        return implode("\n", $lines);
    }

    protected function maskProtectedInlineForms(string $masked): string
    {
        $patterns = [
            '/\\\\\{([' . $this->bracedDelimiterClass() . '])(?!\s)[^\n]+?(?<!\s)\1\}/',
            '/\{\^(?!\s)((?:(?!\n[ \t]*\n)[^^])+?)(?<!\s)\^\}/',
            '/\{,(?!\s)((?:(?!\n[ \t]*\n)[^,])+?)(?<!\s),\}/',
        ];

        $masked = $this->maskFootnoteTokens($masked, '/\[\^[^\]\n]+\]:?/');

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

    private function djotContentStart(string $line): int
    {
            $at = 0;
        $length = strlen($line);
        while ($at < $length) {
            while (($line[$at] ?? '') === ' ' || ($line[$at] ?? '') === "\t") {
                $at++;
            }
            if (($line[$at] ?? '') === '>') {
                $at++;

                continue;
            }
            if (preg_match('/\G(?:\[\^[^\]\n]+\]:[ \t]*|(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+)/', $line, $marker, offset: $at) !== 1) {
                break;
            }
            $at += strlen($marker[0]);
        }

        return $at;
    }

    /**
     * @param string $source
     */
    private function escapeDjotNonTableRows(string $source): string
    {
        if (!str_contains($source, '|')) {
            return $source;
        }
        $mask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['destinations' => false, 'autolinks' => false, 'attributeValues' => false]);
        $rows = $this->djotTableRows($source, $mask);
        $lines = explode("\n", $source);
        $offset = 0;
        foreach ($lines as $n => $line) {
            $start = $this->djotContentStart($line);
            $end = strlen(rtrim($line)) - 1;
            if (!$rows[$n] && ($line[$start] ?? '') === '|' && ($line[$end] ?? '') === '|' && ($mask[$offset + $start] ?? '') === '|' && ($mask[$offset + $end] ?? '') !== '|') {
                $lines[$n] = substr($line, 0, $start) . '\\' . substr($line, $start);
            }
            $offset += strlen($line) + 1;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, bool>
     */
    private function djotTableRows(string $source, string $mask): array
    {
        $offset = 0;
        $previousRow = false;
        $previousBlock = true;
        $footnoteColumn = null;
        $rows = [];
        foreach (explode("\n", $source) as $line) {
            $at = $this->djotContentStart($line);
            $end = strlen(rtrim($line)) - 1;
            $content = substr($line, $at);
            $opensItem = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', substr($line, 0, $at)) === 1;
            $closesNote = $footnoteColumn !== null && $at < $footnoteColumn;
            if ($closesNote) {
                $footnoteColumn = null;
            }
            $allowed = $previousBlock || $previousRow || $opensItem || $closesNote;
            if ($allowed && preg_match('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+)*\[\^[^\]\n]+\]:/', $line, $note)) {
                $footnoteColumn = strpos($note[0], '[^') + 2;
            }
            $row = $allowed && ($line[$at] ?? '') === '|' && ($mask[$offset + $at] ?? '') === '|' && ($line[$end] ?? '') === '|' && ($mask[$offset + $end] ?? '') === '|' && ($line[$end - 1] ?? '') !== '\\';
            $previousBlock = trim($content) === '' || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{|\[[^\]]+\]:)/', $content) === 1;
            $previousRow = $row;
            $offset += strlen($line) + 1;
            $rows[] = $row;
        }

        return $rows;
    }

    private function renameDjotPipeFootnotes(string $source): string
    {
        $mask = $this->maskCodeAndDestinations($source, false);
        $prefix = 'carve-djot-footnote-';
        preg_match_all('/carve-djot-footnote-(\d+)/i', $source, $reservedMatches);
        $reserved = array_fill_keys(array_map('intval', $reservedMatches[1]), true);
        $serial = 0;
        $labels = [];
        $definitionEnds = [];
        preg_match_all('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9A-Za-z]+[.)])[ \t]+)?\[\^([^\]\n]+)\]:/m', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $n => [$value, $offset]) {
            $at = $offset + (int)strpos($value, '[');
            $label = trim(preg_replace('/\s+/', ' ', $matches[1][$n][0]) ?? $matches[1][$n][0]);
            if ($mask[$at] === '[') {
                $definitionEnds[$at] = $at + 2 + strlen($matches[1][$n][0]);
            }
            if ($mask[$at] === '[' && !str_contains($label, '[') && str_contains($label, '|') && !isset($labels[$label])) {
                while (isset($reserved[$serial])) {
                    $serial++;
                }
                $labels[$label] = $prefix . $serial++;
            }
        }
        if ($labels === [] && !str_contains($source, '[^')) {
            return $source;
        }
        $parens = [];
        $stack = [];
        $rowRanges = [];
        $lineOffset = 0;
        $previousRow = false;
        $previousBlock = true;
        foreach (explode("\n", $source) as $line) {
            $at = $this->djotContentStart($line);
            $end = strlen(rtrim($line)) - 1;
            $content = substr($line, $at);
            $opensItem = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', substr($line, 0, $at)) === 1;
            $row = ($previousBlock || $previousRow || $opensItem) && ($line[$at] ?? '') === '|' && ($mask[$lineOffset + $at] ?? '') === '|' && ($line[$end] ?? '') === '|' && ($mask[$lineOffset + $end] ?? '') === '|' && ($line[$end - 1] ?? '') !== '\\';
            $previousBlock = trim($content) === '' || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{|\[[^\]]+\]:)/', $content) === 1;
            $previousRow = $row;
            $length = strlen($line);
            if ($row) {
                $rowRanges[] = [$lineOffset + $at, $lineOffset + $length];
            }
            if ($row || trim($content) === '') {
                $stack = [];
            }
            for ($i = 0; $i < $length; $i++) {
                if (($mask[$lineOffset + $i] ?? '') === ' ') {
                    continue;
                }
                $attrs = $line[$i] === '{' ? $this->readDjotWordAttributes($line, $i) : null;
                if ($attrs !== null) {
                    $i = $attrs['end'] - 1;

                    continue;
                }
                if ($line[$i] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $line[$i + 1] ?? '') === 1) {
                    $i++;

                    continue;
                }
                if ($row && $line[$i] === '|' && ($line[$i - 1] ?? '') !== '\\') {
                    $stack = [];
                } elseif ($line[$i] === '(') {
                    $stack[] = $lineOffset + $i;
                } elseif ($line[$i] === ')' && $stack !== []) {
                    $parens[array_pop($stack)] = $lineOffset + $i;
                }
            }
            if ($row) {
                $stack = [];
            }
            $lineOffset += $length + 1;
        }
        $rowIndex = 0;
        $output = '';
        $images = [];
        $imageDepth = 0;
        $length = strlen($source);
        for ($i = 0; $i < $length;) {
            if ($source[$i] === "\n" && preg_match('/\G\n[ \t]*\n/', $source, offset: $i) === 1) {
                $images = [];
                $imageDepth = 0;
            }
            $attrs = $mask[$i] === '{' ? $this->readDjotWordAttributes($source, $i) : null;
            if ($attrs !== null) {
                $output .= substr($source, $i, $attrs['end'] - $i);
                $i = $attrs['end'];

                continue;
            }
            if ($mask[$i] === ' ') {
                $output .= $source[$i++];

                continue;
            }
            if ($source[$i] === '\\') {
                $output .= $source[$i++];
                if (isset($source[$i]) && preg_match('/[!-\/:-@\[-`{-~]/', $source[$i]) === 1) {
                    $output .= $source[$i++];
                }

                continue;
            }
            while (isset($rowRanges[$rowIndex]) && $rowRanges[$rowIndex][1] <= $i) {
                $rowIndex++;
            }
            $inRow = isset($rowRanges[$rowIndex]) && $rowRanges[$rowIndex][0] <= $i;
            if ($inRow && $source[$i] === '|' && (($i > 0 ? $source[$i - 1] : '')) !== '\\') {
                $images = [];
                $imageDepth = 0;
            }
            if (substr($source, $i, 2) === '[^') {
                $end = $i + 2;
                while ($end < $length && !str_contains("[]\n", $source[$end])) {
                    $end++;
                }
                if (($source[$end] ?? '') === '[') {
                    if (isset($definitionEnds[$i])) {
                        $definitionEnd = $definitionEnds[$i];
                        $output .= substr($source, $i, $definitionEnd + 1 - $i);
                        $i = $definitionEnd + 1;

                        continue;
                    }
                    $output .= '\\[^';
                    $i += 2;

                    continue;
                }
                if (($source[$end] ?? '') === ']') {
                    $label = substr($source, $i + 2, $end - $i - 2);
                    $key = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
                    $rawPipe = $inRow && preg_match('/(^|[^\\\\])\|/', $label) === 1;
                    if ($rawPipe) {
                        $images = [];
                        $imageDepth = 0;
                    }
                    $renamed = $imageDepth === 0 && !$rawPipe ? ($labels[$key] ?? null) : null;
                    $output .= $renamed === null ? substr($source, $i, $end + 1 - $i) : '[^' . $renamed . ']';
                    $i = $end + 1;

                    continue;
                }
            }
            if ($source[$i] === '[') {
                $image = (($i > 0 ? $source[$i - 1] : '')) === '!';
                $images[] = $image;
                if ($image) {
                    $imageDepth++;
                }
            } elseif ($source[$i] === ']' && $images !== []) {
                if (array_pop($images)) {
                    $imageDepth--;
                }
                $end = ($source[$i + 1] ?? '') === '(' ? ($parens[$i + 1] ?? null) : null;
                if ($end !== null) {
                    $output .= substr($source, $i, $end + 1 - $i);
                    $i = $end + 1;

                    continue;
                }
            }
            $output .= $source[$i++];
        }

        return $output;
    }

    private function closeDjotTableCode(string $source): string
    {
        if (!str_contains($source, '`')) {
            return $source;
        }
        $mask = $this->maskDjotOpaque($source, false, ['code' => false]);
        $length = strlen($source);
        preg_match_all('/\n[ \t]*(?:>[ \t]*)*\n/', $source, $breaks, PREG_OFFSET_CAPTURE);
        $paragraphEnds = array_column($breaks[0], 1);
        preg_match_all('/`+/', $source, $tickMatches, PREG_OFFSET_CAPTURE);
        $runs = [];
        foreach ($tickMatches[0] as [$run, $position]) {
            $runs[strlen($run)][] = $position;
        }
        $closeRun = static function (int $open, int $width) use ($runs, $length): int {
            $positions = $runs[$width] ?? [];
            $low = 0;
            $high = count($positions);
            while ($low < $high) {
                $mid = ($low + $high) >> 1;
                if ($positions[$mid] < $open + $width) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }

            return $positions[$low] ?? $length;
        };
        $punctuation = static fn (string $char): bool => $char !== '' && preg_match('/[!-\/:-@\[-`{-~]/', $char) === 1;
        $tickStarts = [];
        $tickWidths = [];
        $queryEnd = str_contains($source, '![') || str_contains($source, '[^') || str_contains($source, '](') ? max((int)strrpos($source, ']'), (int)strrpos($source, ')')) : 0;
        for ($at = 0; $at < $queryEnd;) {
            if ($source[$at] === '\\' && $punctuation($source[$at + 1] ?? '')) {
                $at += 2;

                continue;
            }
            if ($source[$at] !== '`') {
                $at++;

                continue;
            }
            $width = $this->backtickRun($source, $at);
            $tickStarts[] = $at;
            $tickWidths[] = $width;
            $at += $width;
        }
        $count = count($tickStarts);
        $tickIndex = static function (int $position) use ($tickStarts, $count): int {
            $low = 0;
            $high = $count;
            while ($low < $high) {
                $mid = ($low + $high) >> 1;
                if ($tickStarts[$mid] < $position) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }

            return $low;
        };
        $next = array_fill(0, $count + 1, $count);
        $ends = array_fill(0, $count + 1, $length + 1);
        $jumps = array_fill(0, $count + 1, $count);
        $depths = array_fill(0, $count + 1, 0);
        // Merge equal-length ancestor jumps so each tick stores one pointer.
        for ($at = $count - 1; $at >= 0; $at--) {
            $finish = $closeRun($tickStarts[$at], $tickWidths[$at]) + $tickWidths[$at];
            $parent = $tickIndex($finish);
            $jump = $jumps[$parent];
            $farther = $jumps[$jump];
            $next[$at] = $parent;
            $ends[$at] = $finish;
            $depths[$at] = $depths[$parent] + 1;
            $jumps[$at] = $depths[$parent] - $depths[$jump] === $depths[$jump] - $depths[$farther] ? $farther : $parent;
        }
        $balancedTicks = static function (int $from, int $end) use ($tickIndex, $tickStarts, $count, $next, $ends, $jumps): bool {
            $at = $tickIndex($from);
            while ($at < $count && $tickStarts[$at] < $end) {
                $jump = $jumps[$at];
                if ($jump < $count && $ends[$jump] <= $end) {
                    $at = $jump;
                } elseif ($ends[$at] <= $end) {
                    $at = $next[$at];
                } else {
                    return false;
                }
            }

            return true;
        };
        $parens = [];
        $labels = [];
        $parenthesisStack = [];
        $bracketStack = [];
        for ($at = 0; $at < $length; $at++) {
            if ($source[$at] === '\\' && $punctuation($source[$at + 1] ?? '')) {
                $at++;

                continue;
            }
            if ($source[$at] === '(') {
                $parenthesisStack[] = $at;
            } elseif ($source[$at] === ')' && $parenthesisStack !== []) {
                $parens[array_pop($parenthesisStack)] = $at;
            } elseif ($source[$at] === '[') {
                $bracketStack[] = $at;
            } elseif ($source[$at] === ']' && $bracketStack !== []) {
                $labels[array_pop($bracketStack)] = $at;
            }
        }
        $block = '/^(?:[-*+] |(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)] |\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\) |: |#{1,6} |`{3,}|~{3,}|:{3,}|>|\||\^ |\[[^\]]+\]:)/';
        $marker = '/^(?:\[\^[^\]\n]+\]:[ \t]*|(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+)/';
        $itemKindOf = static fn (string $item): string => trim(preg_replace(['/^[0-9]+/', '/^[A-Za-z]+/'], ['1', 'a'], $item) ?? $item);
        $paragraph = 0;
        $offset = 0;
        $cursor = 0;
        $consumed = 0;
        $itemColumn = 0;
        $itemQuote = 0;
        $previousQuote = 0;
        $itemKind = '';
        $previousBlock = true;
        $fenced = null;
        $divs = [];
        $output = [];
        foreach (explode("\n", $source) as $line) {
            $at = $this->djotContentStart($line);
            $lineEnd = $offset + strlen($line);
            [$depth, $content] = $this->quoted($line);
            $trimmed = ltrim($content, " \t");
            $indent = strlen($content) - strlen($trimmed);
            $item = preg_match($marker, $trimmed, $itemMatch) === 1;
            $oldColumn = $itemColumn;
            $oldQuote = $itemQuote;
            $beganInside = $offset < $consumed;
            if (!$beganInside) {
                if ($fenced !== null && $trimmed !== '' && ($depth < $fenced['depth'] || ($depth === $fenced['depth'] && $indent < $fenced['item']))) {
                    $fenced = null;
                }
                if ($fenced !== null) {
                    if ($depth === $fenced['depth'] && $indent <= $fenced['column'] && preg_match('/^' . $fenced['ch'] . '{' . $fenced['width'] . ',}[ \t]*$/', $trimmed) === 1) {
                        $fenced = null;
                        $previousBlock = true;
                    }
                    $offset = $lineEnd + 1;

                    continue;
                }
                if (preg_match('/^(`{3,}|~{3,})[ \t]*=?[a-zA-Z0-9_+#.-]*$/', substr($line, $at), $opening) === 1 && ($previousBlock || $item)) {
                    if (!$item && ($depth < $itemQuote || ($depth === $itemQuote && $indent < $itemColumn))) {
                        $itemColumn = $indent;
                        $itemQuote = $depth;
                        $itemKind = '';
                    }
                    $fenced = ['width' => strlen($opening[1]), 'ch' => $opening[1][0], 'depth' => $depth, 'column' => $at, 'item' => $item ? $indent + (str_starts_with($itemMatch[0], '[^') ? 2 : strlen($itemMatch[0])) : min($itemColumn, $indent)];
                    $offset = $lineEnd + 1;

                    continue;
                }
                if ($item) {
                    $itemColumn = $indent + (str_starts_with($itemMatch[0], '[^') ? 2 : strlen($itemMatch[0]));
                    $itemQuote = $depth;
                    $itemKind = $itemKindOf($itemMatch[0]);
                } elseif ($trimmed !== '' && ($depth < $itemQuote || ($depth === $itemQuote && $indent < $itemColumn && (preg_match($block, $trimmed) === 1 || (str_starts_with($trimmed, '{') && $this->readDjotWordAttributes($trimmed, 0) !== null))))) {
                    $itemColumn = 0;
                }
                if (preg_match('/^(:{3,})(?:[ \t]+\S.*)?[ \t]*$/', substr($line, $at), $div) === 1) {
                    $owner = $divs !== [] ? end($divs) : null;
                    if ($owner !== null && $depth === $owner['depth'] && preg_match('/^:{3,}[ \t]*$/', substr($line, $at)) === 1 && strlen($div[1]) >= $owner['width']) {
                        array_pop($divs);
                    } elseif ($mask[$offset + $at] !== ' ') {
                        $divs[] = ['width' => strlen($div[1]), 'depth' => $depth, 'column' => $at];
                    }
                }
                if (($line[$at] ?? '') === '|' && (($oldColumn > 0 && $depth <= $oldQuote && $indent < $oldColumn) || $depth < $previousQuote)) {
                    $output[] = substr($source, $cursor, $offset - $cursor);
                    $output[] = "\n";
                    $cursor = $offset;
                }
                $previousQuote = $depth;
            }
            $i = max($offset + $at, $consumed);
            $pending = [];
            $footnotes = [];
            while ($i < $lineEnd) {
                if ($mask[$i] === ' ' && $source[$i] !== ' ') {
                    $i++;

                    continue;
                }
                if ($source[$i] === '\\' && $punctuation($source[$i + 1] ?? '')) {
                    $i += 2;

                    continue;
                }
                $attrs = $source[$i] === '{' ? $this->readDjotWordAttributes($source, $i) : null;
                if ($attrs !== null) {
                    $i = $attrs['end'];

                    continue;
                }
                if ($source[$i] === '<' && preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]*:[^<>\s]*|[^<>\s@]+@[^<>\s]+)>/', $source, $auto, offset: $i) === 1) {
                    $i += strlen($auto[0]);

                    continue;
                }
                if (substr($source, $i, 2) === '![' && isset($labels[$i + 1]) && $balancedTicks($i + 2, $labels[$i + 1])) {
                    $i = $labels[$i + 1] + 1;

                    continue;
                }
                if (substr($source, $i, 2) === '[^') {
                    $end = $i + 2;
                    while ($end < $lineEnd && !str_contains('[]', $source[$end])) {
                        $end++;
                    }
                    if (($source[$end] ?? '') === ']' && $balancedTicks($i + 2, $end)) {
                        $i = $end + 1;

                        continue;
                    }
                    $footnotes[] = $i;
                }
                if ($source[$i] === ']' && ($source[$i + 1] ?? '') === '(' && isset($parens[$i + 1]) && $parens[$i + 1] <= $lineEnd && $balancedTicks($i + 2, $parens[$i + 1])) {
                    $i = $parens[$i + 1] + 1;

                    continue;
                }
                if ($source[$i] === ']') {
                    $footnotes = [];
                }
                if ($source[$i] === '(') {
                    $pending[] = $i;
                } elseif ($source[$i] === ')') {
                    array_pop($pending);
                }
                if ($source[$i] !== '`') {
                    $i++;

                    continue;
                }
                $width = $this->backtickRun($source, $i);
                $candidate = $closeRun($i, $width);
                if ($candidate + $width <= $lineEnd) {
                    $i = $candidate + $width;
                    $consumed = $i;

                    continue;
                }
                while (isset($paragraphEnds[$paragraph]) && $paragraphEnds[$paragraph] <= $i) {
                    $paragraph++;
                }
                $limit = min($candidate, $paragraphEnds[$paragraph] ?? $length);
                $separate = false;
                for ($next = $lineEnd + 1; $next < $limit;) {
                    $newline = strpos($source, "\n", $next);
                    $end = $newline === false ? $length : $newline;
                    [$nextDepth, $nextContent] = $this->quoted(substr($source, $next, $end - $next));
                    $nextTrimmed = ltrim($nextContent);
                    $nextIndent = strlen($nextContent) - strlen($nextTrimmed);
                    $owner = $divs !== [] ? end($divs) : null;
                    $closesDiv = $owner !== null && $nextDepth === $owner['depth'] && $nextIndent <= $owner['column'] + 3 && preg_match('/^:{3,}[ \t]*$/', $nextTrimmed) === 1 && strlen(trim($nextTrimmed)) >= $owner['width'];
                    $outside = ($depth > 0 && $nextDepth < $depth) || ($itemColumn > 0 && $nextDepth <= $itemQuote && $nextIndent < $itemColumn);
                    $attributes = str_starts_with($nextTrimmed, '{') && $this->readDjotWordAttributes($nextTrimmed, 0) !== null;
                    if ($closesDiv || ($outside && (preg_match($block, $nextTrimmed) === 1 || $attributes))) {
                        $limit = $next - 1;
                        $nextItem = preg_match($marker, $nextTrimmed, $nextItemMatch) === 1 ? $itemKindOf($nextItemMatch[0]) : null;
                        $separate = $outside && ($nextDepth < $depth || $nextItem !== $itemKind);

                        break;
                    }
                    if ($newline === false) {
                        break;
                    }
                    $next = $newline + 1;
                }
                $closed = $candidate <= $limit && $candidate < $length;
                $end = $closed ? $candidate : $limit;
                $payload = substr($source, $i + $width, $end - $i - $width);
                if (!$closed && $end === $length) {
                    $payload = preg_replace('/\n[ \t]*(?:>[ \t]*)*$/', '', $payload) ?? $payload;
                }
                $payload = preg_replace('/\n$/', '', $payload) ?? $payload;
                $payload = preg_replace('/^ `|` $/', '`', $payload) ?? $payload;
                $normalized = [];
                foreach (explode("\n", $payload) as $n => $value) {
                    if ($n === 0) {
                        $normalized[] = rtrim($value, " \t");

                        continue;
                    }
                    $valueOffset = 0;
                    for ($q = 0; $q < $depth; $q++) {
                        if (preg_match('/\G[ \t]*>[ ]?/', $value, $prefixMatch, 0, $valueOffset) !== 1) {
                            break;
                        }
                        $valueOffset += strlen($prefixMatch[0]);
                    }
                    $normalized[] = trim(substr($value, $valueOffset), " \t");
                }
                $rawCode = str_starts_with($itemKind, '[^') && count($normalized) > 1;
                foreach (array_slice($normalized, 1) as $value) {
                    if ((preg_match($block, $value) === 1 || str_starts_with($value, '{')) && (preg_match('/^\|[ \t:|-]*\|$/', $value) !== 1 || ($line[$at] ?? '') !== '|')) {
                        $rawCode = true;

                        break;
                    }
                }
                if ($rawCode) {
                    $payload = '<code>' . (preg_replace_callback('/[!-\/:-@\[-`{-~]|\n/', static fn (array $match): string => '&#' . ord($match[0]) . ';', implode("\n", $normalized)) ?? '') . '</code>';
                }
                preg_match_all('/`+/', $payload, $payloadRuns);
                $fenceWidth = 1;
                foreach ($payloadRuns[0] as $run) {
                    $fenceWidth = max($fenceWidth, strlen($run) + 1);
                }
                $fence = str_repeat('`', $fenceWidth);
                $pad = str_starts_with($payload, '`') || str_ends_with($payload, '`') || (str_starts_with($payload, ' ') && str_ends_with($payload, ' ') && trim($payload) !== '') ? ' ' : '';
                $escapes = array_merge(array_filter($pending, static fn (int $k): bool => $k > 0 && $source[$k - 1] === ']'), $footnotes);
                sort($escapes);
                foreach ($escapes as $escape) {
                    $output[] = substr($source, $cursor, $escape - $cursor);
                    $output[] = '\\';
                    $cursor = $escape;
                }
                $output[] = substr($source, $cursor, $i - $cursor);
                $output[] = $fence . $pad . $payload . $pad . $fence . ($rawCode ? '{=html}' : '');
                $cursor = $closed ? $end + $width : $end;
                $consumed = $cursor;
                if ($separate && !$closed) {
                    $output[] = "\n";
                }
                $i = $consumed;
            }
            $previousBlock = !$beganInside && $consumed <= $lineEnd && ($trimmed === '' || preg_match('/^(?:#{1,6} |:{3,}|\[[^\]]+\]:)/', $trimmed) === 1 || (str_starts_with($trimmed, '{') && $this->readDjotWordAttributes($trimmed, 0) !== null) || (($line[$at] ?? '') === '|' && str_ends_with(rtrim($line), '|') && !str_ends_with(rtrim($line), '\\|')));
            $offset = $lineEnd + 1;
        }
        $output[] = substr($source, $cursor);

        return implode('', $output);
    }

    private function normalizeDjotTablePipes(string $source): string
    {
        if (!str_contains($source, '\\|')) {
            return $source;
        }
        $source = $this->renameDjotPipeFootnotes($source);
        $mask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['autolinks' => false, 'attributeValues' => false, 'destinations' => false]);
        $lines = explode("\n", $source);
        $offsets = [];
        $offset = 0;
        foreach ($lines as $line) {
            $offsets[] = $offset;
            $offset += strlen($line) + 1;
        }
        $rowFlags = $this->djotTableRows($source, $mask);
        $definitions = [];
        foreach ($lines as $n => $line) {
            $at = $this->djotContentStart($line);
            if (($mask[$offsets[$n] + $at] ?? '') !== '[' || preg_match('/^\[([^\[\]\n^][^\[\]\n]*)\]:[ \t]*(\S*)[ \t]*$/', substr($line, $at), $definition) !== 1 || !str_contains($definition[1], '|')) {
                continue;
            }
            $previousLine = $lines[$n - 1] ?? '';
            $previousAt = $this->djotContentStart($previousLine);
            $previous = trim(substr($previousLine, $previousAt));
            $opensItem = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', substr($line, 0, $at)) === 1;
            $previousAttrs = str_starts_with($previous, '{') ? $this->readDjotWordAttributes($previous, 0) : null;
            if ($previous !== '' && !($rowFlags[$n - 1] ?? false) && $at >= $previousAt && preg_match('/^(?:\*[ \t]*){3,}$|^(?:-[ \t]*){3,}$/', $previous) !== 1 && preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\[[^\]]+\]:)/', $previous) !== 1 && ($previousAttrs === null || $previousAttrs['end'] !== strlen($previous)) && !$opensItem && substr_count(substr($line, 0, $at), '>') <= substr_count(substr($previousLine, 0, $previousAt), '>')) {
                $lines[$n] = substr($line, 0, $at) . '\\' . substr($line, $at);

                continue;
            }
            $target = $definition[2];
            $end = $n;
            while (isset($lines[$end + 1])) {
                $next = $lines[$end + 1];
                $nextAt = $this->djotContentStart($next);
                if ($nextAt <= $at || preg_match('/^\S+$/', substr($next, $nextAt)) !== 1 || ($mask[$offsets[$end + 1] + $nextAt] ?? '') === ' ') {
                    break;
                }
                $target .= substr($next, $nextAt);
                $end++;
            }
            if ($target === '') {
                continue;
            }
            $attributeParts = [];
            for ($k = $n - 1; $k >= 0; $k--) {
                $value = trim(substr($lines[$k], $this->djotContentStart($lines[$k])));
                $parsed = str_starts_with($value, '{') ? $this->readDjotWordAttributes($value, 0) : null;
                if ($parsed === null || $parsed['end'] !== strlen($value)) {
                    break;
                }
                $attributeParts[] = $value;
            }
            $key = preg_replace('/\s+/', ' ', $definition[1]) ?? $definition[1];
            $definitions[trim($key)] = ['target' => $target, 'attrs' => implode('', array_reverse($attributeParts))];
            $lines[$n] = substr($line, 0, $at) . '[' . $definition[1] . ']: ' . $target;
            for ($k = $n + 1; $k <= $end; $k++) {
                $lines[$k] = '';
            }
        }
        $punctuation = static fn (string $char): bool => $char !== '' && preg_match('/[!-\/:-@\[-`{-~]/', $char) === 1;
        foreach ($lines as $n => &$line) {
            $at = $this->djotContentStart($line);
            $length = strlen($line);
            $lineMask = substr($mask, $offsets[$n], $length);
            $end = strlen(rtrim($line)) - 1;
            $row = ($line[$at] ?? '') === '|' && ($lineMask[$at] ?? '') === '|';
            $content = substr($line, $at);
            if (!$row) {
                continue;
            }
            if (!($rowFlags[$n] ?? false)) {
                $line = substr($line, 0, $at) . '\\' . substr($line, $at);

                continue;
            }
            $brackets = [];
            $parens = [];
            $bracketStack = [];
            $parenStack = [];
            for ($i = $at; $i < $length; $i++) {
                if (($lineMask[$i] ?? '') === ' ') {
                    continue;
                }
                $attrs = $line[$i] === '{' ? $this->readDjotWordAttributes($line, $i) : null;
                if ($attrs !== null) {
                    $i = $attrs['end'] - 1;

                    continue;
                }
                if ($line[$i] === '|' && ($line[$i - 1] ?? '') !== '\\') {
                    $bracketStack = [];
                    $parenStack = [];

                    continue;
                }
                if ($line[$i] === '\\') {
                    $begin = $i;
                    while (($line[$i] ?? '') === '\\') {
                        $i++;
                    }
                    if (($i - $begin) % 2 !== 0 && $punctuation($line[$i] ?? '')) {
                        continue;
                    }
                    $i--;

                    continue;
                }
                if ($line[$i] === '[') {
                    $bracketStack[] = $i;
                } elseif ($line[$i] === ']' && $bracketStack !== []) {
                    $brackets[array_pop($bracketStack)] = $i;
                } elseif ($line[$i] === '(') {
                    $parenStack[] = $i;
                } elseif ($line[$i] === ')' && $parenStack !== []) {
                    $parens[array_pop($parenStack)] = $i;
                }
            }
            $destinations = [];
            $references = [];
            foreach ($brackets as $open => $close) {
                if (($line[$open + 1] ?? '') === '^') {
                    continue;
                }
                $next = $close + 1;
                if (($line[$next] ?? '') === '(' && isset($parens[$next])) {
                    $destinations[$next + 1] = ($destinations[$next + 1] ?? 0) + 1;
                    $destinations[$parens[$next]] = ($destinations[$parens[$next]] ?? 0) - 1;
                } elseif (($line[$next] ?? '') === '[' && isset($brackets[$next]) && !isset($references[$open])) {
                    $last = $brackets[$next];
                    $label = substr($line, $next + 1, $last - $next - 1);
                    if ($label === '') {
                        $label = substr($line, $open + 1, $close - $open - 1);
                    }
                    $key = preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $label) ?? $label;
                    $key = preg_replace('/\s+/', ' ', $key) ?? $key;
                    if (!isset($definitions[trim($key)])) {
                        continue;
                    }
                    $definition = $definitions[trim($key)];
                    $target = strtr($definition['target'], ['\\' => '%5C', '|' => '%7C', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', ' ' => '%20']);
                    $references[$next] = ['end' => $last + 1, 'text' => '(' . $target . ')' . (preg_replace_callback('/\\\\+\|?|\|/', static fn (array $match): string => str_ends_with($match[0], '|') && (strlen($match[0]) - 1) % 2 === 0 ? substr($match[0], 0, -1) . '\\|' : $match[0], $definition['attrs']) ?? $definition['attrs'])];
                }
            }
            $inside = 0;
            for ($k = 0; $k < $length; $k++) {
                $inside += $destinations[$k] ?? 0;
                $destinations[$k] = $inside;
            }
            $output = '';
            for ($i = 0; $i < $length;) {
                if (isset($references[$i])) {
                    $output .= $references[$i]['text'];
                    $i = $references[$i]['end'];

                    continue;
                }
                if (($lineMask[$i] ?? '') === ' ' || $line[$i] !== '\\') {
                    $output .= $line[$i++];

                    continue;
                }
                $begin = $i;
                while (($line[$i] ?? '') === '\\') {
                    $i++;
                }
                $run = $i - $begin;
                if (($line[$i] ?? '') !== '|') {
                    $output .= substr($line, $begin, $run);
                    if ($run % 2 !== 0 && $punctuation($line[$i] ?? '')) {
                        $output .= $line[$i++];
                    }

                    continue;
                }
                $output .= $destinations[$begin] > 0 ? str_repeat('%5C', intdiv($run, 2)) . '%7C' : str_repeat('\\', $run + ($run % 2 === 0 ? 1 : 0)) . '|';
                $i++;
            }
            $line = $output;
        }
        unset($line);

        return implode("\n", $lines);
    }
}
