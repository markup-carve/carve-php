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
use MarkupCarve\Carve\Renderer\SoftBreakMode;
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

    private bool $omitQuoteDelimiters = false;

    private string $paragraphContextSource = '';

    /**
     * @var array<int, array{key: string, result: bool}>
     */
    private array $paragraphStarts = [];

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
        $this->paragraphContextSource = '';
        $this->paragraphStarts = [];
        try {
            return $this->convertDocumentWithContext($djot, $preserveHeadingIds);
        } finally {
            $this->paragraphContextSource = '';
            $this->paragraphStarts = [];
        }
    }

    private function convertDocumentWithContext(string $djot, bool $preserveHeadingIds): string
    {
        $strippedDefinitions = $this->stripFootnoteDefinitionAttributes($djot);
        $source = $strippedDefinitions['source'];
        [$frontmatter, $separator, $source] = $this->splitSiteFrontmatter($source);
        $source = $this->escapeDjotTableAttributeBoundaries($this->normalizeDjotDivOpeners($source));
        if (str_contains($source, '`')) {
            foreach (explode("\n", $source) as $line) {
                if (preg_match('/^\[(?!\^)[^\]\n]*`[^\]\n]*\]:/', substr($line, $this->djotContentStart($line))) === 1) {
                    $source = $this->normalizeDjotReferenceUses($source, aliasesOnly: true);

                    break;
                }
            }
        }
        $hasCaretReference = false;
        if (str_contains($source, '[^') && str_contains($source, ']:')) {
            foreach (explode("\n", $source) as $line) {
                if (preg_match('/^\[\^[^\]\n]*\]:/', substr($line, $this->djotContentStart($line))) === 1) {
                    $hasCaretReference = true;

                    break;
                }
            }
        }
        if ($hasCaretReference) {
            $accepted = [];
            $this->foldDjotReferences($source, $accepted);
            $source = $this->normalizeDjotReferenceUses($source, true, $accepted ?? []);
        }
        $source = $this->carryDjotAttributesAcrossQuoteExits($this->carryDjotAttributesAcrossDivClosers($source));
        [$source, $inherited] = $this->escapeInvalidDjotAttributes($source);
        $source = $this->normalizeDjotFences($this->foldDjotReferences($this->normalizeDjotAttributeLines($source)));
        $itemAttributePrefix = DjotPlaceholderPrefix::choose($source, "\x00DJOTITEMATTR\x00");
        $itemAttributeValues = [];
        $holdItemAttributes = static function (string $attributes, bool $block = false) use (&$itemAttributeValues, $itemAttributePrefix): string {
            $itemAttributeValues[] = $attributes;

            return $itemAttributePrefix . ($block ? 'B' : '') . (count($itemAttributeValues) - 1) . "\x00";
        };
        $emptyNote = DjotPlaceholderPrefix::choose($source, "\x00DJOTEMPTYNOTE\x00");
        $source = $this->normalizeDjotReferenceUses($this->foldDjotReferences($this->normalizeDjotStructure($source, $strippedDefinitions['isBoundary'], $emptyNote, $holdItemAttributes)), inherited: $inherited);
        $source = $this->normalizeDjotFootnotes($source, $strippedDefinitions['isBoundary'], $inherited, $emptyNote);
        $source = $this->foldHeadingContinuations($this->padDjotCodeSpans($this->escapeDjotNonTableRows($this->normalizeDjotTablePipes($this->normalizeDjotAutolinks($this->normalizeDjotLinks($source, $inherited))))));
        $source = $this->normalizeImageQuoteSpellings($source);
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
                $definitionKey = preg_replace('/[ \t\r\n]+/', ' ', trim($definitionMatches[1][$index][0], " \t\r\n")) ?? $definitionMatches[1][$index][0];
                $definitions[$definitionKey] = $definitionMatches[1][$index][0];
            }
        }
        $childTextCache = [];
        $imageChildText = function (string $label, bool $omitQuotes = true) use (&$childTextCache, $inherited): string {
            $key = ($omitQuotes ? '1:' : '0:') . $label;

            return $childTextCache[$key] ??= $this->renderImageLabelChildText($label, $inherited, $omitQuotes);
        };
        $collapsedImageLines = null;
        $collapsedImageLine = 0;
        $source = preg_replace_callback('/(!?\[([^\[\]]*)\])\[\]/', function (array $match) use ($source, $collapsedMask, $definitions, &$collapsedImageLines, &$collapsedImageLine, $imageChildText): string {
            [$value, $at] = $match[0];
            $key = $match[2][0];
            $bracketAt = $at + (str_starts_with($match[1][0], '!') ? 1 : 0);
            if ($definitions === [] || $this->isDjotEscaped($source, $bracketAt) || $collapsedMask[$at] === ' ' || $collapsedMask[$at + strlen($value) - 3] !== ']' || str_starts_with($key, '^') && strlen($key) > 1) {
                return $value;
            }
            if (str_contains($key, "\n")) {
                if (preg_match('/\n[ \t]*\n/', $key) === 1) {
                    return $value;
                }
                $collapsedImageLines ??= $this->djotImageLineOwners($source, $collapsedMask);
                while ($collapsedImageLines[$collapsedImageLine]['end'] <= $at) {
                    $collapsedImageLine++;
                }
                $owner = $collapsedImageLines[$collapsedImageLine];
                $layout = $this->djotImageAltLayout($key, $owner['quotes'], $owner['itemColumn'], $owner['itemQuote'], $owner['div']);
                if (!$layout['valid']) {
                    return $value;
                }
                $key = preg_replace('/[ \t\r\n]+/', ' ', trim($imageChildText($layout['normalized']), " \t\r\n")) ?? $key;
            } else {
                $key = $imageChildText($key);
            }

            $key = preg_replace('/[ \t\r\n]+/', ' ', trim($key, " \t\r\n")) ?? $key;

            return isset($definitions[$key]) ? $match[1][0] . '[' . $definitions[$key] . ']' : $value;
        }, $source, -1, $collapsedCount, PREG_OFFSET_CAPTURE) ?? $source;
        $source = $this->convertDjotBlockMarkers($source);
        $emptyTerm = DjotPlaceholderPrefix::choose($source, "\x00DJOTEMPTYTERM\x00");
        $source = $this->convertDefinitionLists($source, $emptyTerm);
        $djotBody = $source;
        $strongSpans = [];
        $altPrefix = DjotPlaceholderPrefix::choose($source, "\x00DJOTALT\x00");
        $imageParagraph = DjotPlaceholderPrefix::choose($source, "\x00DJOTIMAGEPARAGRAPH\x00");
        $imageMask = $this->maskCodeAndDestinations($source);
        $imageLines = $this->djotImageLineOwners($source, $imageMask);
        $imageStarts = array_fill_keys(array_column($imageLines, 'start'), true);
        $imageLine = 0;
        $source = $this->replaceImageLabels($source, $imageMask, function (array $match) use (&$strongSpans, $altPrefix, $source, $imageMask, $imageParagraph, $imageStarts, $imageLines, &$imageLine, $imageChildText): string {
            [$image, $at] = $match[0];
            if ($imageMask[$at] !== '!' || $imageMask[$at + strlen($image) - 1] !== ']' || preg_match('/\n[ \t]*\n/', $match[1][0]) === 1 || $this->isDjotEscaped($source, $at)) {
                return $image;
            }
            $paragraph = isset($imageStarts[$at]) ? $imageParagraph : '';
            if (str_starts_with($match[1][0], '^')) {
                return $image;
            }
            $pairedQuotes = substr_count($match[1][0], '"') >= 2 || substr_count($match[1][0], "'") >= 2;
            $forcedCloseQuote = str_contains($match[1][0], '"}') || str_contains($match[1][0], "'}");
            if (!$pairedQuotes && !$forcedCloseQuote && !str_contains($match[1][0], "\n") && preg_match('/[_*`{^~\[\]<:]/', $match[1][0]) !== 1 && preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\[ \t]/', $match[1][0]) !== 1) {
                $token = $altPrefix . count($strongSpans) . "\x00";
                $strongSpans[$token] = $match[1][0];

                return $paragraph . '![' . $token . ']';
            }
            $label = $match[1][0];
            while ($imageLines[$imageLine]['end'] <= $at) {
                $imageLine++;
            }
            $layout = $this->djotImageAltLayout($label, $imageLines[$imageLine]['quotes'], $imageLines[$imageLine]['itemColumn'], $imageLines[$imageLine]['itemQuote'], $imageLines[$imageLine]['div']);
            if (!$layout['valid']) {
                return $image;
            }
            $label = $layout['normalized'];
            $continuations = $layout['continuations'];
            $token = $altPrefix . count($strongSpans) . "\x00";
            $alt = QuotedSlotEscaper::escapeImageAlt($imageChildText($label), $imageLines[$imageLine]['table']);
            $continuation = 0;
            $strongSpans[$token] = preg_replace_callback('/\n/', static function () use ($continuations, &$continuation): string {
                return "\n" . ($continuations[$continuation++] ?? '');
            }, $alt) ?? $alt;

            return $paragraph . '![' . $token . ']';
        });
        $source = $this->protectAttributedWords($source, $strongSpans, $inherited);
        [$source, $orphanSpans] = $this->consumeOrphanDjotAttributes($source);
        $wire = [];
        $mask = $this->djotEmphasisMask($source, true, $wire);
        $carve = DjotEmphasis::convert($source, $mask, fn (string $plain): string => $this->rewriteDjotInline($plain), $wire ?? [], cellBoundaries: $this->djotTableCellBoundaries($source), omitQuoteDelimiters: $this->omitQuoteDelimiters);

        $carve = str_replace($emptyTerm, '%%', $carve);
        $carve = str_replace([$emptyNote, $imageParagraph], ['%%%%', '{%%}'], $carve);
        $carve = strtr($carve, $orphanSpans + $strongSpans);
        $dropInherited = array_fill_keys(array_keys($inherited), '');
        $carve = strtr($carve, $dropInherited);
        if ($itemAttributeValues !== []) {
            $carve = preg_replace_callback('/^[ \t>]*' . preg_quote($itemAttributePrefix, '/') . '(B?)(\d+)\x00\n(?:[ \t>]*\n)*([ \t>]*)([-*+]|[^ \t\n]+)([ \t]+)/m', static fn (array $match): string => $match[1] === 'B' ? $match[3] . ($itemAttributeValues[(int)$match[2]] ?? '') . "\n" . $match[3] . $match[4] . $match[5] : $match[3] . $match[4] . ($itemAttributeValues[(int)$match[2]] ?? '') . $match[5], $carve) ?? $carve;
            $carve = preg_replace_callback('/(?<![ \t])([ \t]+)((?:\[[ xX]\][ \t]+)?)' . preg_quote($itemAttributePrefix, '/') . '(\d+)\x00/', static fn (array $match): string => ($itemAttributeValues[(int)$match[3]] ?? '') . $match[1] . $match[2], $carve) ?? $carve;
        }
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
        $masked = preg_replace_callback('/<[^<>\s]+>/', fn (array $match): string => !$this->isDjotEscaped($source, $match[0][1]) && preg_match('/[^:]@|[A-Za-z]:/', $match[0][0]) === 1 ? str_repeat(' ', strlen($match[0][0])) : $match[0][0], $masked, -1, $angleCount, PREG_OFFSET_CAPTURE) ?? $masked;
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
            if ($masked[$i] !== '{' || $this->isDjotEscaped($source, $i)) {
                continue;
            }
            $attrs = $readAttributes($i);
            if ($attrs === null) {
                continue;
            }
            $firstAttributeEnd = $attrs['end'];
            $attributeSources = $attrs['source'] === '{}' ? [] : [$attrs['source']];
            $attributeEnd = $attrs['end'];
            while (($source[$attributeEnd] ?? '') === '{') {
                $next = $readAttributes($attributeEnd);
                if ($next === null) {
                    break;
                }
                if ($next['source'] !== '{}') {
                    $attributeSources[] = $next['source'];
                }
                $attributeEnd = $next['end'];
            }
            if ($attributeEnd !== $attrs['end']) {
                $attrs = ['end' => $attributeEnd, 'source' => $attributeSources !== [] ? implode('', $attributeSources) : '{}'];
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
        $block = '/^(?:[#>|{]|[-*+][ \t]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)][ \t]|:[ \t]|:{2,}|\([0-9a-zA-Z]+\)[ \t]|[`~]{3,}|\^[ \t]|\[[^\]\n]*\]:|(?:\*[ \t]*){3,}$|(?:-[ \t]*){3,}$)/';
        $container = '/\G([ \t]*)(>(?:[ \t]|$)|\[\^[^\]\n]+\]:[ \t]*|[-*+][ \t]+|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)][ \t]+|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)[ \t]+)/';
        $result = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (!str_contains($line, '#')) {
                $result[] = $line;

                continue;
            }
            $at = 0;
            $depth = 0;
            $column = 0;
            $inlineContainer = false;
            while (preg_match($container, $line, $token, 0, $at) === 1) {
                $at += strlen($token[0]);
                if (str_starts_with($token[2], '>')) {
                    $depth++;
                    $column = 0;
                } elseif (str_starts_with($token[2], '[^')) {
                    $inlineContainer = true;
                    $column = strlen($token[1]) + 2;
                } else {
                    $inlineContainer = true;
                    $column += strlen($token[0]);
                    if (str_contains('-*+', $token[2][0]) && preg_match('/\G\[[ xX]\][ \t]+/', $line, $task, 0, $at) === 1) {
                        $at += strlen($task[0]);
                    }
                }
            }
            if (preg_match('/\G([ \t]*)(#{1,6})(?:[ \t]+\S|[ \t]*$)/', $line, $heading, 0, $at) !== 1 || ($masked[$i][$at + strlen($heading[1])] ?? '') !== '#') {
                $result[] = $line;

                continue;
            }
            $quoteHeading = $depth > 0 && $column === 0;
            $firstLine = $quoteHeading && $inlineContainer ? substr($line, 0, $at) . substr($line, $at + strlen($heading[1])) : $line;
            if (!$quoteHeading) {
                $column += strlen($heading[1]);
            }
            $marker = '/^' . $heading[2] . '[ \t]+/';
            $parts = [rtrim($firstLine, " \t")];
            $tail = $line;
            while ($i + 1 < $count) {
                if ((strlen($tail) - strlen(rtrim($tail, '\\'))) % 2 === 1) {
                    break;
                }
                if (preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $lines[$i + 1], $quote) !== 1) {
                    break;
                }
                if (substr_count($quote[0], '>') !== $depth) {
                    break;
                }
                $remainder = substr($lines[$i + 1], strlen($quote[0]));
                $indent = strspn($remainder, " \t");
                if ($indent < $column) {
                    break;
                }
                $next = substr($remainder, $indent);
                if ($this->rejectedDjotCommentStartsBlock($next)) {
                    break;
                }
                if (preg_match($marker, $next) === 1) {
                    $part = preg_replace($marker, '', $next) ?? $next;
                    if (trim($part, " \t\r\n") === '') {
                        break;
                    }
                } else {
                    if (trim($next, " \t\r\n") === '' || preg_match($block, $next) === 1) {
                        break;
                    }
                    $part = $next;
                }
                $parts[] = rtrim($part, " \t");
                $tail = $part;
                $i++;
            }
            $result[] = count($parts) === 1 ? $firstLine : implode(' ', $parts);
        }

        return implode("\n", $result);
    }

    private function rejectedDjotCommentStartsBlock(string $line): bool
    {
        if (preg_match('/^\\\{\x00DJOTINVALIDATTR\x00[0-9]+\x00(%[^\n]*)/', $line, $match) !== 1) {
            return false;
        }

        return $this->readDjotWordAttributes('{' . $match[1] . '}', 0) !== null;
    }

    private function djotCommentContinuesParagraph(string $source, int $start): bool
    {
        if ($this->paragraphContextSource !== $source) {
            $this->paragraphContextSource = $source;
            $this->paragraphStarts = [];
        }
        $context = $this->djotAttributeContext($source, $start);
        $key = $context['depth'] . ':' . ($context['indent'] ?? 'inline') . ':' . $context['minimum'];
        $result = $this->scanDjotCommentParagraph($source, $start, $key);
        $from = $start;
        while ($from > 0 && $source[$from - 1] !== "\n") {
            $from--;
        }
        $this->paragraphStarts[$from] = ['key' => $key, 'result' => $result];

        return $result;
    }

    private function scanDjotCommentParagraph(string $source, int $start, string $key): bool
    {
        $context = $this->djotAttributeContext($source, $start);
        if ($context['indent'] === null) {
            return true;
        }
        $marker = '/^(?:[ \t]*>[ \t]*)*[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))[.)]|\((?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))\))[ \t]+/';
        $lineStart = $start;
        while ($lineStart > 0 && $source[$lineStart - 1] !== "\n") {
            $lineStart--;
        }
        $prefix = substr($source, $lineStart, $start - $lineStart);
        if (preg_match($marker, $prefix)) {
            return false;
        }
        $lines = [];
        $end = $lineStart - 1;
        while ($end >= 0) {
            $from = $end;
            while ($from > 0 && $source[$from - 1] !== "\n") {
                $from--;
            }
            $raw = substr($source, $from, $end - $from);
            preg_match('/^[ \t]*(?:>[ \t]*)+/', $raw, $quotes);
            if (substr_count($quotes[0] ?? '', '>') !== $context['depth']) {
                return false;
            }
            if (trim(substr($raw, DjotEmphasis::structuralPrefixEnd($raw))) === '') {
                if ($lines === []) {
                    break;
                }
                $parentEnd = $from - 1;
                while ($parentEnd >= 0) {
                    $parentFrom = $parentEnd;
                    while ($parentFrom > 0 && $source[$parentFrom - 1] !== "\n") {
                        $parentFrom--;
                    }
                    $parent = substr($source, $parentFrom, $parentEnd - $parentFrom);
                    if (preg_match($marker, $parent)) {
                        if (preg_match('/^(?:[ \t]*>[ \t]*)*[ \t]*/', $parent, $leading) !== 1) {
                            return false;
                        }
                        $column = strlen($leading[0]);
                        if (strlen($prefix) <= $column && DjotEmphasis::structuralPrefixEnd($lines[array_key_last($lines)]['raw']) > $column) {
                            return false;
                        }

                        break;
                    }
                    if (trim($parent) === '') {
                        break;
                    }
                    $parentEnd = $parentFrom - 1;
                }

                break;
            }
            $cached = $this->paragraphStarts[$from] ?? null;
            if ($cached !== null && $cached['key'] === $key) {
                if ($cached['result']) {
                    return true;
                }
                $attrs = $this->readDjotWordAttributes($source, $from + DjotEmphasis::structuralPrefixEnd($raw), false, false, false);
                if ($attrs !== null && $attrs['end'] <= $start) {
                    while ($lines !== [] && $lines[array_key_last($lines)]['from'] < $attrs['end']) {
                        array_pop($lines);
                    }

                    break;
                }
            }
            $lines[] = ['raw' => $raw, 'from' => $from];
            $end = $from - 1;
        }
        $kind = null;
        $fence = '';
        $attributeEnd = -1;
        foreach (array_reverse($lines) as ['raw' => $raw, 'from' => $from]) {
            if ($from < $attributeEnd || $kind === 'paragraph') {
                continue;
            }
            if (preg_match($marker, $raw)) {
                if (preg_match('/^(?:[ \t]*>[ \t]*)*[ \t]*/', $raw, $leading) !== 1) {
                    return false;
                }
                if (strlen($prefix) <= strlen($leading[0])) {
                    return false;
                }
            }
            $bodyAt = DjotEmphasis::structuralPrefixEnd($raw);
            $body = substr($raw, $bodyAt);
            if ($kind === 'fence') {
                if (str_starts_with($body, $fence)) {
                    $kind = null;
                }

                continue;
            }
            if (str_starts_with($body, '{')) {
                $attrs = $this->readDjotWordAttributes($source, $from + $bodyAt, false, false, false);
                if ($attrs !== null) {
                    $attributeEnd = $attrs['end'];
                    $kind = null;

                    continue;
                }
            }
            if (preg_match('/^#{1,6}(?:[ \t]|$)/', $body)) {
                $kind = 'heading';

                continue;
            }
            if (preg_match('/^(`{3,}|~{3,})/', $body, $opened)) {
                $kind = 'fence';
                $fence = $opened[1];

                continue;
            }
            if (str_starts_with($body, '{%') && $this->readDjotWordAttributes($body . '}', 0, false, false, false) !== null) {
                $kind = 'paragraph';

                continue;
            }
            if ($kind === 'heading') {
                continue;
            }
            if (preg_match('/^:[ \t]/', $body)) {
                if (strlen($prefix) <= $bodyAt) {
                    return false;
                }
                $kind = trim(substr($body, 1)) !== '' ? 'paragraph' : null;

                continue;
            }
            if (preg_match('/^\[\^[^\]]+\]:[ \t]*(.*)$/', $body, $note)) {
                if (strlen($prefix) < $bodyAt + 2) {
                    return false;
                }
                $kind = trim($note[1]) !== '' ? 'paragraph' : null;

                continue;
            }
            if (preg_match('/^(?::::|\||\[[^\]]*\]:|:[ \t]|(?:\*[ \t]*){3,}$|(?:-[ \t]*){3,}$)/', $body)) {
                $kind = null;

                continue;
            }
            $kind = 'paragraph';
        }

        return $kind === 'paragraph';
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
        $prefix = preg_replace('/^[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))[.)]|\((?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))\))[ \t]+(?=>)/', '', $prefix) ?? $prefix;
        $depth = 0;
        while (preg_match('/^[ \t]*>(?:[ \t]|$)/', $prefix, $quote)) {
            $prefix = substr($prefix, strlen($quote[0]));
            $depth++;
        }
        preg_match('/^[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))[.)]|\((?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))\))[ \t]+/', $prefix, $marker);
        $block = preg_match('/^(?:[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))[.)]|\((?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))\))[ \t]+)?[ \t]*$/', $prefix) === 1;

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
    private function readDjotWordAttributes(
        string $source,
        int $start,
        bool $carve = false,
        bool $table = false,
        bool $paragraphContext = true,
        bool $preserveShorthand = false,
    ): ?array {
        $parts = [];
        $lastClassAssignment = -1;
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
            if (($source[$i] ?? '') === '}' && str_contains(substr($source, $start, $i + 1 - $start), "\n") && ($parts !== [] || !$paragraphContext || !$this->djotCommentContinuesParagraph($source, $start))) {
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

                if ($carve && $lastClassAssignment >= 0) {
                    $parts = array_values(array_filter($parts, static fn (string $part, int $index): bool => $index >= $lastClassAssignment || (!str_starts_with($part, '.') && !str_starts_with($part, 'class=')), ARRAY_FILTER_USE_BOTH));
                }

                return ['end' => $i + 1, 'source' => '{' . implode(' ', $parts) . '}', 'parts' => $parts];
            }
            if (($source[$i] ?? '') === '%') {
                $end = $i + 1;
                while ($end < $length && $source[$end] !== '%' && $source[$end] !== '}') {
                    $end++;
                }
                $comment = substr($source, $i, $end - $i);
                if ($end === $length || preg_match('/\n[ \t]*\r?\n/', $comment) === 1) {
                    return null;
                }
                if (str_contains($comment, '>') && str_contains($comment, "\n")) {
                    $context ??= $this->djotAttributeContext($source, $start);
                    if ($context['depth'] > 0) {
                        foreach (array_slice(explode("\n", $comment), 1, -1) as $raw) {
                            $line = $this->djotAttributeLine($raw, $context['depth']);
                            if ($line !== null && trim($line) === '') {
                                return null;
                            }
                        }
                    }
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
                $parts[] = ($preserveShorthand && !$carve && $kind === '.') || preg_match('/^[A-Za-z0-9_][\w-]*$/', $value) === 1
                    ? $kind . $value
                    : ($kind === '#' ? 'id' : 'class') . '=' . $quoteValue($value);
            } else {
                if (preg_match('/\G[A-Za-z][A-Za-z0-9_-]*=/', $source, $key, offset: $i) !== 1) {
                    return null;
                }
                if ($key[0] === 'class=') {
                    $lastClassAssignment = count($parts);
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
            $attributeSources = $attrs['source'] === '{}' ? [] : [$attrs['source']];
            $attributeEnd = $attrs['end'];
            while (($source[$attributeEnd] ?? '') === '{') {
                $next = $readNative($attributeEnd);
                if ($next === null) {
                    break;
                }
                if ($next['source'] !== '{}') {
                    $attributeSources[] = $next['source'];
                }
                $attributeEnd = $next['end'];
            }
            if ($attributeEnd !== $attrs['end']) {
                $attrs = ['end' => $attributeEnd, 'source' => $attributeSources !== [] ? implode('', $attributeSources) : '{}'];
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
            $exitedNote = false;
            while ($noteColumn !== null && $content !== '' && $column + 1 < $noteColumn) {
                $exitedNote = true;
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
            } elseif ($content !== '') {
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
            if ($exitedNote && !$marker && str_starts_with($content, '{') && ($this->readDjotWordAttributes($content, 0)['end'] ?? -1) === strlen($content) && $n > 0 && trim($lines[$n - 1]) !== '') {
                $lines[$n] = $prefix . "\n" . $lines[$n];
            }
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

    private function normalizeDjotDivOpeners(string $source): string
    {
        if (!str_contains($source, ':::')) {
            return $source;
        }
        $lines = explode("\n", $source);
        $mask = null;
        $boundaries = null;
        $offset = 0;
        foreach ($lines as $index => $line) {
            $start = $offset;
            $offset += strlen($line) + 1;
            $at = $this->djotContentStart($line);
            $prefix = substr($line, 0, $at);
            if (preg_match('/^(:{3,})([ \t]*)([A-Za-z0-9_-]+)([ \t]*)(\r?)$/', substr($line, $at), $opener) !== 1
                || $opener[2] === ' ') {
                continue;
            }
            $mask ??= $this->maskCodeAndDestinations($source);
            $boundaries ??= array_fill_keys($this->djotInlineBoundaries($source, $mask, true), true);
            if (($mask[$start + $at] ?? '') === ':' && isset($boundaries[$start])) {
                $lines[$index] = $prefix . $opener[1] . ' ' . $opener[3] . $opener[4] . $opener[5];
            }
        }

        return implode("\n", $lines);
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
            $contentAt = strlen($line) - strlen(ltrim($line, " \t"));
            if (($masked[$contentAt] ?? '') !== ($line[$contentAt] ?? '')) {
                continue;
            }
            $view = $line;
            while (preg_match('/^(?:[ \t]*> ?|[ \t]*(?:(?:[-*+]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-zA-Z])[.)]|\([0-9A-Za-z]+\)) +(?:\[[ xX]\] +)?|: |\[\^[^\]\r\n]+\]: +))/', $view, $host)) {
                $view = substr($view, strlen($host[0]));
            }
            $view = ltrim($view, " \t");
            $containerOffset = strlen($line) - strlen($view);
            // Djot treats a bare period list marker as text.
            if (preg_match('/^\.(?=[ \t]|$|\{)/', $view) === 1 && ($masked[$containerOffset] ?? '') === '.') {
                $lines[$i] = substr($line, 0, $containerOffset) . '\\' . $view;

                continue;
            }
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
            if (preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)\(((?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,}))\)([ \t]+\S.*)$/', $masked, $match)) {
                if (!preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)\(((?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,}))\)([ \t]+\S.*)$/', $line, $authored)) {
                    continue;
                }
                $indent = $match[1] === '' && $nested[$i] ? $authored[2] : '';
                $lines[$i] = $authored[1] . $indent . $authored[3] . '.' . $authored[4];

                continue;
            }
            if (!preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)([*-])(?:[ \t]*\3){2,}[ \t]*$/', $masked, $rule) || !preg_match('/^((?:(?:[ \t]*>)+[ \t]*)?)([ \t]*)([*-])(?:[ \t]*\3){2,}[ \t]*$/', $line)) {
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
                if (!$current['body'] && $this->rejectedDjotCommentStartsBlock(ltrim($lines[$i + 1] ?? '', " \t"))) {
                    $lines[$i] = str_repeat(' ', $current['target']) . ':  ' . $emptyTerm . "\n";
                    $current['body'] = true;
                }
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
        if (!str_contains($source, '{')) {
            return [$source, []];
        }

        $maskedSource = preg_replace_callback('/<[A-Za-z][A-Za-z0-9+.-]*:[^<>\\s]*>/', static fn (array $match): string => str_repeat(' ', strlen($match[0])), $this->maskCodeAndDestinations($source)) ?? $source;
        $maskedSource = $this->maskFootnoteTokens($maskedSource, '/\[\^[^\]\n]*\]/', true);
        $masked = explode("\n", $maskedSource);
        $item = '(?:[.#][A-Za-z0-9_:-]+|[A-Za-z][A-Za-z0-9_-]*=(?:"(?:\\\\.|[^"\\\\\n]++)*+"|[A-Za-z0-9_:-]+))';
        $pattern = '/\{[ \t]*' . $item . '(?:[ \t]+' . $item . ')*+[ \t]*\}/';
        $lines = explode("\n", $source);
        $lineOffsets = [];
        $lineOffset = 0;
        foreach ($lines as $line) {
            $lineOffsets[] = $lineOffset;
            $lineOffset += strlen($line) + 1;
        }
        $tableRows = str_contains($source, '|') ? $this->djotTableRows($source, $maskedSource) : [];
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
                if ($depth !== 0 && $followsBlank) {
                    $nextDepth = $depth;
                }

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
        $blockAttributes = [];
        $ownerExits = [];
        $quoteScopeChanges = [];
        $targetPrefixes = [];
        $previousDepth = 0;
        $previousNonblankDepth = 0;
        $blockOwners = [];
        foreach ($lines as $index => $line) {
            preg_match('/^(?:(?:[ \t]*>)+[ \t]*)?[ \t]*/', $line, $container);
            $first = strlen($container[0] ?? '');
            $body = rtrim(substr($line, $first));
            $depth = substr_count($container[0] ?? '', '>');
            if (trim($body) === '' && $depth <= $previousDepth) {
                $previousDepth = min($previousDepth, $depth);

                continue;
            }
            $opens = $depth > $previousDepth;
            $quoteScopeChanges[$index] = $opens || $depth !== $previousNonblankDepth;
            $previousNonblankDepth = $depth;
            $at = -1;
            if ($opens) {
                for ($count = 0; $count <= $previousDepth; $count++) {
                    $position = strpos($container[0] ?? '', '>', $at + 1);
                    $at = $position === false ? strlen($container[0] ?? '') : $position;
                }
            }
            $targetPrefixes[$index] = $opens ? substr($container[0] ?? '', 0, $at) : ($container[0] ?? '');
            $previousDepth = $depth;
            $previousLine = $lines[$index - 1] ?? '';
            $previous = trim(preg_replace('/^(?:(?:[ \t]*>)+[ \t]*)?[ \t]*/', '', $previousLine) ?? '');
            $blockOwner = $blockOwners === [] ? null : $blockOwners[array_key_last($blockOwners)];
            $ownerOutdent = $blockOwner !== null && ($depth < $blockOwner['depth'] || $depth === $blockOwner['depth'] && $first < $blockOwner['start']);
            $canOpenOwner = $index === 0 || $previous === '' || $ownerOutdent || ($blockAttributes[$index - 1] ?? false);
            $rowEnd = strlen(rtrim($line)) - 1;
            $exitsAtRow = $ownerOutdent && str_starts_with($body, '|') && $rowEnd > $first && ($masked[$index][$first] ?? '') === '|' && ($masked[$index][$rowEnd] ?? '') === '|' && ($line[$rowEnd - 1] ?? '') !== '\\';
            $closesOwner = $exitsAtRow || preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|[`~]{3,}|(?:[-*][ \t]*){3,}$)/', $body) === 1 && ($masked[$index][$first] ?? '') !== ' ' || ($tableRows[$index] ?? false) || preg_match('/^(?:`{3,}|~{3,})[ \t]*=?[A-Za-z0-9_+#.-]*[ \t]*$/', $body) === 1;
            if ($previous === '' || preg_match($wholeAttribute, $body) === 1 || $closesOwner || $ownerOutdent && ($tableRows[$index - 1] ?? false)) {
                while (($owner = $blockOwners === [] ? null : $blockOwners[array_key_last($blockOwners)]) !== null && ($depth < $owner['depth'] || $depth === $owner['depth'] && $first < $owner['start'])) {
                    array_pop($blockOwners);
                }
            }
            $contentStart = $this->djotContentStart($line);
            $ownerPrefix = substr($line, 0, $contentStart);
            $ownerNote = strpos($ownerPrefix, '[^');
            $markerPrefix = $ownerNote === false ? $ownerPrefix : substr($ownerPrefix, 0, $ownerNote);
            if ($canOpenOwner && preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', $markerPrefix, $ownerMatch, PREG_OFFSET_CAPTURE) === 1 && ($masked[$index][$ownerMatch[0][1]] ?? '') !== ' ') {
                $owner = ['start' => $ownerMatch[0][1] + 1, 'depth' => $depth];
                if (($blockOwners === [] ? null : $blockOwners[array_key_last($blockOwners)]) !== $owner) {
                    $blockOwners[] = $owner;
                }
            }
            if (($masked[$index][$first] ?? '') !== '{' || preg_match($wholeAttribute, $body) !== 1) {
                continue;
            }
            $previousStart = $this->djotContentStart($previousLine);
            $noteAt = strpos(substr($previousLine, 0, $previousStart), '[^');
            $ownerStart = $noteAt === false ? $previousStart : $noteAt + 1;
            preg_match('/^(?:(?:[ \t]*>)+[ \t]*)?/', $previousLine, $previousQuotes);
            $outdented = $depth < substr_count($previousQuotes[0] ?? '', '>') || $first < $ownerStart && ($noteAt !== false || preg_match('/(?:[-*+] |[0-9A-Za-z]+[.)] |\([0-9A-Za-z]+\) )/', substr($previousLine, 0, $previousStart)) === 1);
            $ownerExits[$index] = $ownerOutdent && $previous !== '';
            $blockAttributes[$index] = $index === 0 || $previous === '' || $outdented || $ownerOutdent || ($blockAttributes[$index - 1] ?? false)
                || preg_match('/^(?:`{3,}|~{3,}|:{3,}|#{1,6} |:{1,2} |(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|\|.*\||\[(?!\^)[^\]]+\]:|\[\^[^\]]+\]:[ \t]*$)/', $previous) === 1;
        }
        $nextBlock = [];
        $following = -1;
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $nextBlock[$index] = $following;
            preg_match('/^(?:(?:[ \t]*>)+[ \t]*)?[ \t]*/', $lines[$index], $container);
            $first = strlen($container[0] ?? '');
            $body = rtrim(substr($lines[$index], $first));
            if (($quoteScopeChanges[$index] ?? false) || trim($body) !== '' && !(($masked[$index][$first] ?? '') === '{' && preg_match($wholeAttribute, $body) === 1)) {
                $following = $index;
            }
        }
        $moved = [];
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
                $block = $blockAttributes[$index] ?? false;
                if ($alone && $block) {
                    if (($ownerExits[$index] ?? false) && !isset($dangling[$index])) {
                        $out[] = substr($line, 0, $first);
                    }
                    if (!isset($dangling[$index])) {
                        $target = $nextBlock[$index];
                        if ($target >= 0) {
                            $targetPrefix = $targetPrefixes[$target];
                            if (substr_count(substr($line, 0, $first), '>') > substr_count($targetPrefix, '>') || (substr_count(substr($line, 0, $first), '>') === substr_count($targetPrefix, '>') && substr($line, 0, $first) !== $targetPrefix)) {
                                if ($targetPrefixes[$index] === substr($line, 0, $first)) {
                                    foreach ($moved[$index] ?? [] as $pending) {
                                        $moved[$target][] = $pending;
                                    }
                                    unset($moved[$index]);
                                }
                                $moved[$target][] = $attrs;
                                $dropLine = true;
                                $cursor = $at + strlen($attrs);
                            }
                        }

                        continue;
                    }
                    $dropLine = true;
                    if ($targetPrefixes[$index] === substr($line, 0, $first)) {
                        unset($moved[$index]);
                    }
                }
                $written .= substr($line, $cursor, $at - $cursor) . ($prefix . ($at === $first ? 'L' : 'I'));
                $cursor = $at + strlen($attrs);
            }
            foreach ($moved[$index] ?? [] as $pending) {
                $out[] = $targetPrefixes[$index] . $pending;
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

    private function protectRejectedCommentQuotes(string $source): string
    {
        if (!str_contains($source, '{%') || !str_contains($source, '>')) {
            return $source;
        }
        $edits = [];
        $mask = $this->maskDjotAttributeSource($source);
        preg_match_all('/\{%/', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [, $at]) {
            if ($mask[$at] !== '{' || $this->readDjotWordAttributes($source, $at) !== null) {
                continue;
            }
            $context = $this->djotAttributeContext($source, $at);
            if ($context['depth'] === 0 || $context['indent'] === null || $this->djotCommentContinuesParagraph($source, $at)) {
                continue;
            }
            $line = strpos($source, "\n", $at);
            if ($line === false || $this->readDjotWordAttributes(substr($source, $at, $line - $at) . '}', 0) === null) {
                continue;
            }
            $from = $at;
            while ($from > 0 && $source[$from - 1] !== "\n") {
                $from--;
            }
            $linePrefix = substr($source, $from, $at - $from);
            preg_match('/^[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))[.)]|\((?:[0-9]+|[A-Za-z]|(?:[ivxlcdm]+|[IVXLCDM]+))\))[ \t]+(?=>)/', $linePrefix, $enclosing);
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))+/', substr($linePrefix, strlen($enclosing[0] ?? '')), $openingQuote);
            while ($line !== false) {
                preg_match('/\G(?:[ \t]*>(?:[ \t]|$))+/', $source, $quote, offset: $line + 1);
                $quoteText = $quote[0] ?? null;
                if ($quoteText === null) {
                    break;
                }
                $end = strpos($source, "\n", $line + 1);
                $bodyStart = $line + 1 + strlen($quoteText);
                $body = substr($source, $bodyStart, ($end === false ? strlen($source) : $end) - $bodyStart);
                if ($enclosing !== [] && strlen($quoteText) - strlen(ltrim($quoteText)) < strlen($enclosing[0])) {
                    if (strlen($quoteText) - strlen(ltrim($quoteText)) > strlen($enclosing[0]) - strlen(ltrim($enclosing[0])) && ltrim($quoteText) === ltrim($openingQuote[0] ?? '') && substr_count($quoteText, '>') === $context['depth']) {
                        $edits[$line + 1 + strlen($quoteText)] = str_replace('>', '\\>', trim($quoteText)) . ' ';
                    }

                    break;
                }
                if (substr_count($quoteText, '>') < $context['depth']) {
                    $edits[$line + 1] = rtrim($quoteText) . "\n";

                    break;
                }
                if (ltrim($quoteText) !== ltrim($openingQuote[0] ?? '') || substr_count($quoteText, '>') !== $context['depth']) {
                    break;
                }
                if (trim($body) === '') {
                    break;
                }
                $indent = strspn($body, " \t");
                if ($indent < $context['minimum']) {
                    if (strlen($quoteText) - strlen(ltrim($quoteText)) >= strlen($openingQuote[0] ?? '') - strlen(ltrim($openingQuote[0] ?? '')) + $context['minimum']) {
                        $edits[$bodyStart] = str_replace('>', '\\>', trim($quoteText)) . ' ';
                    }

                    break;
                }
                if ($indent <= $context['indent']) {
                    $edits[$bodyStart] = str_replace('>', '\\>', trim($quoteText)) . ' ';

                    break;
                }
                if (strpbrk($body, '}%') !== false) {
                    break;
                }
                $line = $end;
            }
        }
        ksort($edits);
        $output = '';
        $cursor = 0;
        foreach ($edits as $at => $text) {
            $output .= substr($source, $cursor, $at - $cursor) . $text;
            $cursor = $at;
        }

        return $output . substr($source, $cursor);
    }

    private function escapeDjotTableAttributeBoundaries(string $source): string
    {
        if (!str_contains($source, '|') || !str_contains($source, '{') && !str_contains($source, '<')) {
            return $source;
        }
        $mask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['attributeValues' => false, 'comments' => false, 'autolinks' => false]);
        $rows = $this->djotTableRows($source, $mask);
        $codeMask = $mask;
        if (str_contains($source, '`')) {
            $codeLines = [];
            $offset = 0;
            foreach (explode("\n", $source) as $index => $physical) {
                $codeLines[] = ($rows[$index] ?? false) && str_contains($physical, '`')
                    ? $this->maskCodeAndDestinations(str_replace('{', ' ', $physical), false, opaqueOptions: ['attributeValues' => false, 'comments' => false, 'autolinks' => false, 'destinations' => false], unclosedCode: false)
                    : substr($mask, $offset, strlen($physical));
                $offset += strlen($physical) + 1;
            }
            $codeMask = implode("\n", $codeLines);
        }
        $rejectedRows = [];
        $edits = [];
        $codeEnds = [];
        $line = 0;
        $lineStart = 0;
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            if ($source[$at] === "\n") {
                $line++;
                $lineStart = $at + 1;

                continue;
            }
            if (isset($codeEnds[$at])) {
                $at = $codeEnds[$at] - 1;

                continue;
            }
            if (($rows[$line] ?? false) && $source[$at] === '<' && !$this->isDjotEscaped($source, $at)) {
                if (preg_match('/\G<[^<>\s]+>/u', $source, $angle, offset: $at) === 1) {
                    $crossesCell = false;
                    for ($inner = $at + 1, $angleEnd = $at + strlen($angle[0]); $inner < $angleEnd; $inner++) {
                        if ($source[$inner] === '|' && $source[$inner - 1] !== '\\') {
                            $crossesCell = true;
                        }
                    }
                    if ($crossesCell) {
                        $edits[$at] = ['end' => $at, 'text' => '\\', 'line' => $line];
                    }
                }
            }
            if (!($rows[$line] ?? false) || $source[$at] !== '{' || $mask[$at] !== '{' || $this->isDjotEscaped($source, $at)) {
                continue;
            }
            $attrs = $this->readDjotWordAttributes($source, $at);
            if ($attrs === null) {
                continue;
            }
            $crossesCell = false;
            $firstPipe = -1;
            for ($inner = $at + 1; $inner < $attrs['end']; $inner++) {
                if ($source[$inner] === '|' && $source[$inner - 1] !== '\\') {
                    if ($firstPipe < 0) {
                        $firstPipe = $inner;
                    }
                    if ($mask[$inner] === '|' && ($source[$at + 1] === '%' || $codeMask[$inner] === '|')) {
                        $crossesCell = true;
                    }
                }
            }
            if ($firstPipe >= 0 && $source[$at + 1] !== '%' && $codeMask[$firstPipe] !== '|' && !isset($rejectedRows[$line])) {
                $newline = strpos($source, "\n", $lineStart);
                $physical = substr($source, $lineStart, ($newline === false ? $length : $newline) - $lineStart);
                $rejectedRows[$line] = $lineStart + $this->djotContentStart($physical);
            }
            if ($crossesCell) {
                for ($inner = $at + 1; $inner < $firstPipe; $inner++) {
                    if ($source[$inner] === '"' && ($source[$inner + 1] ?? '') === '}' && !$this->isDjotEscaped($source, $inner)) {
                        $edits[$inner] = ['end' => $inner + 2, 'text' => '“\\}', 'line' => $line];
                    }
                }
            }
            if ($crossesCell && $firstPipe >= 0 && !isset($rejectedRows[$line])) {
                $open = -1;
                $width = 0;
                for ($inner = $at + 1; $inner < $firstPipe; $inner++) {
                    if ($source[$inner] === '\\') {
                        $inner++;

                        continue;
                    }
                    if ($source[$inner] !== '`') {
                        continue;
                    }
                    $run = 1;
                    while (($source[$inner + $run] ?? '') === '`') {
                        $run++;
                    }
                    if ($open < 0) {
                        $open = $inner;
                        $width = $run;
                    } elseif ($width === $run) {
                        $open = -1;
                    }
                    $inner += $run - 1;
                }
                if ($open >= 0) {
                    if ($open + $width === $firstPipe) {
                        $edits[$open] = ['end' => $firstPipe, 'text' => '`<code></code>`{=html}', 'line' => $line];
                    } else {
                        $edits[$firstPipe] = ['end' => $firstPipe, 'text' => str_repeat('`', $width), 'line' => $line];
                    }
                    $codeEnds[$open] = $firstPipe;
                }
            }
            if ($crossesCell) {
                $edits[$at] = ['end' => $at + 1, 'text' => in_array($source[$at - 1] ?? '', [']', ')'], true) ? '{}\\{' : '\\{', 'line' => $line];

                continue;
            }
            for ($inner = $at; $inner < $attrs['end']; $inner++) {
                if ($source[$inner] === "\n") {
                    $line++;
                    $lineStart = $inner + 1;
                }
            }
            $at = $attrs['end'] - 1;
        }
        $ownedRows = $rows;
        if ($rejectedRows !== []) {
            $paragraphSource = $source;
            foreach ($rejectedRows as $at) {
                $paragraphSource[$at] = 'x';
            }
            $analysis = [];
            $positions = [];
            $owner = null;
            foreach (explode("\n", $paragraphSource) as $index => $physical) {
                $prefix = substr($physical, 0, $this->djotContentStart($physical));
                $depth = substr_count(preg_replace('/\[\^[^\]\n]+\]:[ \t]*/', '', $prefix) ?? $prefix, '>');
                preg_match('/^(?:[ \t]*>(?:[ \t]|$))*[ \t]*/', $physical, $leading);
                $leadingPrefix = $leading[0] ?? '';
                preg_match_all('/>/', $leadingPrefix, $quotes, PREG_OFFSET_CAPTURE);
                $column = $owner !== null && count($quotes[0]) > $owner['itemDepth'] ? $quotes[0][$owner['itemDepth']][1] : strlen($leadingPrefix);
                if (($rows[$index] ?? false) && $owner !== null && ($depth < $owner['depth'] || $owner['column'] >= 0 && $column <= $owner['column'])) {
                    $analysis[] = '';
                    $owner = null;
                }
                $positions[] = count($analysis);
                $analysis[] = $physical;
                if (isset($rejectedRows[$index])) {
                    $at = $itemDepth = $quoteDepth = $column = 0;
                    $itemColumn = -1;
                    $prefixLength = strlen($prefix);
                    while ($at < $prefixLength) {
                        if (str_contains(" \t", $prefix[$at])) {
                            $at++;
                            $column++;

                            continue;
                        }
                        if ($prefix[$at] === '>') {
                            $quoteDepth++;
                            $at++;
                            $column++;

                            continue;
                        }
                        if (preg_match('/\G(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|\[\^[^\]\n]+\]:)[ \t]+/', $prefix, $marker, offset: $at) !== 1) {
                            break;
                        }
                        $itemColumn = $column + (str_starts_with($marker[0], '[^') ? 1 : 0);
                        $itemDepth = $quoteDepth;
                        $at += strlen($marker[0]);
                        $column += intdiv(strlen(mb_convert_encoding($marker[0], 'UTF-16LE', 'UTF-8')), 2);
                    }
                    $owner = ['depth' => $depth, 'itemDepth' => $itemDepth, 'column' => $itemColumn];
                }
            }
            $analysisSource = implode("\n", $analysis);
            $analysisRows = $this->djotTableRows($analysisSource, $this->maskCodeAndDestinations($analysisSource, false, opaqueOptions: ['attributeValues' => false, 'comments' => false, 'autolinks' => false]));
            $ownedRows = array_map(static fn (int $position): bool => $analysisRows[$position] ?? false, $positions);
        }
        $rowStarts = array_fill_keys(array_values($rejectedRows), true);
        $copied = 0;
        $output = '';
        for ($at = 0; $at < $length; $at++) {
            if (isset($rowStarts[$at])) {
                $output .= substr($source, $copied, $at - $copied) . '\\';
                $copied = $at;
            }
            $edit = $edits[$at] ?? null;
            if ($edit === null || isset($rejectedRows[$edit['line']]) || !($ownedRows[$edit['line']] ?? false)) {
                continue;
            }
            $output .= substr($source, $copied, $at - $copied) . $edit['text'];
            $copied = $edit['end'];
            $at = max($at, $edit['end'] - 1);
        }

        return $output !== '' ? $output . substr($source, $copied) : $source;
    }

    /**
     * @return array{string, array<string, true>}
     */
    private function escapeInvalidDjotAttributes(string $source): array
    {
        $source = $this->protectRejectedCommentQuotes($source);
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
                if ($source[$i + 1] === '%' && $this->djotCommentContinuesParagraph($source, $i)) {
                    for ($inner = max($i + 2, $escapes[array_key_last($escapes)] + 1); $inner < $length && $source[$inner] !== '%' && $source[$inner] !== '}'; $inner++) {
                        if ($source[$inner] === "\n") {
                            $nextEnd = strpos($source, "\n", $inner + 1);
                            $nextLine = substr($source, $inner + 1, ($nextEnd === false ? $length : $nextEnd) - $inner - 1);
                            if (trim(substr($nextLine, DjotEmphasis::structuralPrefixEnd($nextLine))) === '') {
                                break;
                            }
                        }
                        if ($source[$inner] === '{' && $masked[$inner] === '{') {
                            $escapes[] = $inner;
                        }
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
            if ($masked[$i] === ' ' && $source[$i] !== ' ' && $source[$i] !== "\n") {
                if ($plain !== '') {
                    $result .= $this->escapePlainCarveInlineSyntax($plain, self::HANDLED_DJOT);
                    $plain = '';
                }
                $result .= $source[$i];

                continue;
            }

            if ($source[$i] === '!' && ($source[$i + 1] ?? '') === '`' && !$this->isDjotEscaped($source, $i)) {
                $plain .= '\\';
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
        $fenceOpener = '/^([ \t]*)(?:(\[\^[^\]\n]+\]:[ \t]*|[-*+][ \t]+(?:\[[ xX]\][ \t]+)?|(?:(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+))?(`{3,}|~{3,})[ \t]*=?[A-Za-z0-9_+#.-]*[ \t]*$/';
        $heldFence = null;
        $previousBlock = true;
        $normalizeBoundary = true;
        $previousDepth = 0;
        $ancestors = [];
        foreach ($lines as $i => $line) {
            if ($heldFence === null) {
                [$depth, $content] = $this->quoted($line);
            } else {
                $depth = $offset = 0;
                while ($depth < $heldFence['depth'] && preg_match('/\G[ \t]*>(?:[ \t]|$)/', $line, $hostQuote, 0, $offset) === 1) {
                    $depth++;
                    $offset += strlen($hostQuote[0]);
                }
                $content = substr($line, $offset);
            }
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
            $nestedNote = false;
            $nestedNoteColumn = -1;
            $openResult = preg_match($fenceOpener, $content, $open);
            if ($openResult !== 1 && str_contains($content, '[^') && (str_contains($content, '```') || str_contains($content, '~~~'))) {
                $openingAt = $this->djotContentStart($content);
                $openingPrefix = substr($content, 0, $openingAt);
                preg_match_all('/\[\^[^\]\n]+\]:[ \t]*/', $openingPrefix, $headers, PREG_OFFSET_CAPTURE);
                if (count($headers[0]) > 1 && !str_contains(preg_replace('/\[\^[^\]\n]+\]:[ \t]*/', '', $openingPrefix) ?? '', '>')) {
                    $openResult = preg_match($fenceOpener, substr($content, $openingAt), $open);
                    if ($openResult === 1) {
                        $nestedNote = true;
                        $open[1] = substr($content, 0, strspn($content, " \t"));
                        $open[2] = substr($openingPrefix, strlen($open[1]));
                        $lastHeader = $headers[0][array_key_last($headers[0])];
                        $nestedNoteColumn = (int)(strlen(mb_convert_encoding(substr($openingPrefix, 0, $lastHeader[1]), 'UTF-16LE', 'UTF-8')) / 2);
                    }
                }
            }
            if ($openResult === 1) {
                if ((str_starts_with($open[2], ':') && !$previousBlock) || ($strict && !$canNormalize && $open[2] === '')) {
                    continue;
                }
                $container = $nestedNote ? $nestedNoteColumn + 1 : ($open[2] !== '' ? strlen($open[1]) + 1 : ($nested ? $ownerIndent + 1 : null));
                $markerBase = preg_replace('/\[[ xX]\][ \t]+$/', '', $open[2]) ?? $open[2];
                $nativeMarkerBase = preg_replace('/\(([0-9A-Za-z]+)\)([ \t]+)$/', '$1.$2', $markerBase) ?? $markerBase;
                $nativeMarker = $nativeMarkerBase . substr($open[2], strlen($markerBase));
                $targetColumn = $nestedNote ? $nestedNoteColumn + 2 : (str_starts_with($open[2], '[^') ? strlen($open[1]) + 2 : ($open[2] !== '' ? strlen($open[1]) + strlen($nativeMarkerBase) : ($nested ? $ownerColumn : 0)));
                $heldFence = ['container' => $container, 'char' => $open[3][0], 'length' => strlen($open[3]), 'depth' => $depth, 'target' => str_repeat(' ', $targetColumn), 'dedent' => $nestedNote ? (int)(strlen(mb_convert_encoding($openingPrefix, 'UTF-16LE', 'UTF-8')) / 2) : strlen($open[1]) + strlen($open[2]), 'normalize' => $canNormalize || $open[2] !== ''];
                if ($onFenceLine !== null && $heldFence['normalize'] && ($open[2] === '' || $nativeMarker !== $open[2])) {
                    $openingPrefix = $open[2] === '' ? $heldFence['target'] : $open[1] . $nativeMarker;
                    $onFenceLine($i, substr($line, 0, strlen($line) - strlen($content)) . $openingPrefix . substr($content, $heldFence['dedent']));
                }
                $start = $nestedNote ? strlen($line) - strlen($content) + $openingAt : strpos($line, $open[3]);
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

    private function convertImageQuoteText(string $source, bool $omitQuotes): string
    {
        $previous = $this->omitQuoteDelimiters;
        $this->omitQuoteDelimiters = $omitQuotes;
        try {
            return $this->convert($source);
        } finally {
            $this->omitQuoteDelimiters = $previous;
        }
    }

    private function normalizeImageQuoteSpellings(string $source): string
    {
        $source = $this->normalizeDjotInlineComments($source);
        if (!$this->omitQuoteDelimiters && str_contains($source, '![')) {
            $source = $this->replaceImageLabels($source, $this->maskCodeAndDestinations($source), function (array $match, bool $completeTarget): string {
                $label = $match[1][0];
                if ($completeTarget || str_contains($label, '![') || !(str_contains($label, '{"') || str_contains($label, "{'") || str_contains($label, '"}') || str_contains($label, "'}"))) {
                    return $match[0][0];
                }

                $label = $this->normalizeDjotInlineComments($label, true);
                $wire = [];
                $mask = $this->djotEmphasisMask($label, true, $wire);
                $emptyAttributeEnd = 0;
                $label = preg_replace_callback('/\{[\"\']|[\"\']\}|\{\}/', function (array $match) use ($label, $mask, $wire, &$emptyAttributeEnd): string {
                    [$token, $at] = $match[0];
                    if ($at < $emptyAttributeEnd) {
                        return '';
                    }
                    if ($this->isDjotEscaped($label, $at)) {
                        return $token;
                    }
                    if ($token === '{}' && ($wire[$at]['source'] ?? '') === '{}' && !($at > 0 && $label[$at - 1] === ']')) {
                        $emptyAttributeEnd = $wire[$at]['end'];

                        return '{%%}';
                    }
                    if ($mask[$at] !== $label[$at]) {
                        return $token;
                    }

                    return match ($token) {
                        "{'" => '‘', "'}" => '’', '{"' => '“', '"}' => '”',
                        default => $token,
                    };
                }, $label, -1, $count, PREG_OFFSET_CAPTURE) ?? $label;

                return '![' . $label . ']';
            }, true);
        }
        $positions = [];
        if (!$this->omitQuoteDelimiters && str_contains($source, '![')) {
            $mask = $this->maskCodeAndDestinations($source);
            $imageLines = $this->djotImageLineOwners($source, $mask);
            $line = 0;
            $this->replaceImageLabels($source, $mask, function (array $match) use (&$positions, $imageLines, &$line): string {
                [$image, $at] = $match[0];
                if (str_starts_with($match[1][0], '^') || preg_match('/\n[ \t]*\n/', $match[1][0]) === 1) {
                    return $image;
                }
                $label = $match[1][0];
                while ($imageLines[$line]['end'] <= $at) {
                    $line++;
                }
                $owner = $imageLines[$line];
                $layout = $this->djotImageAltLayout($label, $owner['quotes'], $owner['itemColumn'], $owner['itemQuote'], $owner['div']);
                if (!$layout['valid']) {
                    return $image;
                }
                for ($i = 0, $length = strlen($label); $i < $length; $i++) {
                    if ($label[$i] === '"' || $label[$i] === "'") {
                        $positions[$at + 2 + $i] = $positions[$at + 1 + $i] = true;
                    }
                }

                return $image;
            });
        }

        return $this->normalizeDjotInlineSpellings($source, fn (int $at): bool => $this->omitQuoteDelimiters || isset($positions[$at]));
    }

    private function djotImageLabelAttributes(string $source): string
    {
        $mask = $this->maskCodeAndDestinations($source, opaqueOptions: ['comments' => false, 'autolinks' => false]);
        $framed = 'DJOTALT ' . $source;
        $parts = [];
        $cursor = 0;
        $noteEnd = -1;
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            if ($mask[$at] !== $source[$at] || $this->isDjotEscaped($source, $at)) {
                continue;
            }
            if ($source[$at] === ':' && preg_match('/\G:[a-zA-Z0-9_+-]+:/', $source, $symbol, 0, $at) === 1) {
                $parts[] = substr($source, $cursor, $at - $cursor);
                $parts[] = '{%%}';
                $cursor = $at + strlen($symbol[0]);
                $at = $cursor - 1;

                continue;
            }
            if ($source[$at] === '<' && preg_match('/\G<([^<>\s]+)>/', $source, $autolink, 0, $at) === 1 && preg_match('/[^:]@|[A-Za-z]:/', $autolink[1]) === 1) {
                preg_match_all('/`+/', $autolink[1], $runs);
                $width = 1;
                foreach ($runs[0] as $run) {
                    $width = max($width, strlen($run) + 1);
                }
                $ticks = str_repeat('`', $width);
                $parts[] = substr($source, $cursor, $at - $cursor);
                $parts[] = $ticks . ' ' . $autolink[1] . ' ' . $ticks;
                $cursor = $at + strlen($autolink[0]);
                $at = $cursor - 1;

                continue;
            }
            if ($source[$at] === '[' && ($source[$at + 1] ?? '') === '^' && !($at !== $noteEnd && $at > 0 && $source[$at - 1] === ']' && !$this->isDjotEscaped($source, $at - 1))) {
                $end = $at + 2;
                while ($end < $length && !($mask[$end] === $source[$end] && !$this->isDjotEscaped($source, $end) && ($source[$end] === '[' || $source[$end] === ']'))) {
                    $end++;
                }
                if (($source[$end] ?? '') === ']') {
                    $parts[] = substr($source, $cursor, $at - $cursor);
                    $parts[] = '{%%}';
                    $cursor = $noteEnd = $end + 1;
                    $at = $cursor - 1;

                    continue;
                }
            }
            if ($source[$at] !== '{') {
                continue;
            }
            $attrs = $this->readDjotWordAttributes($framed, $at + 8);
            if ($attrs === null) {
                continue;
            }
            $parts[] = substr($source, $cursor, $at - $cursor);
            $parts[] = $at !== $noteEnd && $at > 0 && $source[$at - 1] === ']' && !$this->isDjotEscaped($source, $at - 1) ? '{}' : '{%%}';
            $cursor = $attrs['end'] - 8;
            $at = $cursor - 1;
        }

        return implode('', $parts) . substr($source, $cursor);
    }

    /**
     * @param string $source
     * @param int $quoteDepth
     * @param array<string, true> $inherited
     */
    private function djotCollapsedReferenceText(string $source, int $quoteDepth = 0, array $inherited = []): string
    {
        $source = $this->removeInheritedAttributeMarkers($source, $inherited);
        if ($quoteDepth > 0 && str_contains($source, "\n")) {
            $source = preg_replace_callback('/\n([^\n]*)/', static function (array $match) use ($quoteDepth): string {
                $line = $match[1];
                for ($n = 0; $n < $quoteDepth; $n++) {
                    if (preg_match('/^[ \t]*>(?:[ \t]|$)/', $line, $prefix) !== 1) {
                        break;
                    }
                    $line = substr($line, strlen($prefix[0]));
                }

                return "\n" . $line;
            }, $source) ?? $source;
        }

        if (strpbrk($source, "_*`{~^\"'\\[]=!/<:\n\r") === false) {
            return $source;
        }

        return $this->renderImageLabelChildText($source, []);
    }

    /**
     * @param string $label
     * @param array<string, true> $inherited
     * @param bool $omitQuotes
     */
    private function renderImageLabelChildText(string $label, array $inherited, bool $omitQuotes = true): string
    {
        $label = $this->djotImageLabelAttributes($label);
        [$protected, $scalars] = $this->protectImageAltScalars($label);
        $renderer = new DjotImageChildTextRenderer();
        if (str_contains($label, "\n")) {
            $renderer->setSoftBreakMode(SoftBreakMode::Newline);
        }
        $plain = (new CarveConverter(smartTypography: false, renderer: $renderer))->convert(str_replace("\0U\0", "\0", $this->removeInheritedAttributeMarkers($this->convertImageQuoteText('DJOTALT ' . $protected . ' DJOTEND', $omitQuotes), $inherited)));

        return strtr(preg_replace('/^DJOTALT ?| ?DJOTEND\n?$/', '', $plain) ?? $plain, $scalars);
    }

    /**
     * @param string $label
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function protectImageAltScalars(string $label): array
    {
        preg_match_all('/DJOTALTSCALAR([0-9]+)Z/', $label, $matches);
        $reserved = array_fill_keys($matches[1], true);
        $serial = 0;
        while (isset($reserved[$serial])) {
            $serial++;
        }
        $prefix = 'DJOTALTSCALAR' . $serial . 'Z';
        $values = [];
        $scalar = static function (string $value) use ($prefix, &$values): string {
            $token = $prefix . count($values) . 'Z';
            $values[$token] = $value;

            return $token;
        };
        $codeMask = $this->maskDjotOpaque($label, false, ['code' => false, 'rawCode' => false]);
        preg_match_all('/`+/', $codeMask, $runs, PREG_OFFSET_CAPTURE);
        $ends = [];
        $next = [];
        foreach (array_reverse($runs[0]) as [$run, $at]) {
            $width = strlen($run);
            for ($offset = 0; $offset < $width; $offset++) {
                $ends[$at + $offset] = $next[$width - $offset] ?? -1;
            }
            $next[$width] = $at + $width;
        }
        preg_match_all('/\{=[^\x09-\x0d\x20\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}{}`]+\}/u', $label, $formats, PREG_OFFSET_CAPTURE);
        $rawFormats = [];
        foreach ($formats[0] as [$format, $at]) {
            $rawFormats[$at] = $at + strlen($format);
        }
        $chunks = [];
        $copied = 0;
        foreach ($runs[0] as [$run, $at]) {
            $width = strlen($run);
            if ($this->isDjotEscaped($label, $at)) {
                $at++;
                $width--;
            }
            if ($width === 0 || $at < $copied || $ends[$at] < 0) {
                continue;
            }
            $end = $ends[$at];
            $value = substr($label, $at + $width, $end - $at - 2 * $width);
            $raw = $rawFormats[$end] ?? null;
            if ($raw !== null) {
                $value = preg_replace('/^ `/', '`', $value) ?? $value;
                $value = preg_replace('/` \z/', '`', $value) ?? $value;
            } elseif (str_starts_with($value, ' ') && str_ends_with($value, ' ') && trim($value, " \t\n\r") !== '') {
                $value = substr($value, 1, -1);
            }
            $chunks[] = substr($label, $copied, $at - $copied);
            $chunks[] = $scalar($value);
            $copied = $raw ?? $end;
        }
        if ($copied > 0) {
            $label = implode('', $chunks) . substr($label, $copied);
        }
        $mask = $this->maskCodeAndDestinations($label);
        $label = preg_replace_callback('/(\n[ \t]*)(#{1,6}(?=[ \t]|$)|>(?=[ \t]|$)|(?:[-*][ \t]*){3,}(?=\n|$)|[-*+](?=[ \t]|$)|:{1,}(?=[ \t]|$)|\||\^(?=[ \t]|$)|%%|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)](?=[ \t]|$))/m', static function (array $match) use ($mask, $scalar): string {
            [$all, $at] = $match[0];
            $prefix = $match[1][0];
            $marker = $match[2][0];

            return $mask[$at + strlen($prefix)] === $marker[0] ? $prefix . $scalar($marker) : $all;
        }, $label, -1, $count, PREG_OFFSET_CAPTURE) ?? $label;
        $label = preg_replace_callback('/^[ \t\x0b\x0c\x{0085}\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]+$/mu', static fn (array $match): string => trim($match[0], " \t") === '' ? $match[0] : $scalar($match[0]), $label) ?? $label;

        return [$label, $values];
    }

    /**
     * @return list<array{start: int, end: int, quotes: int, itemColumn: int, itemQuote: int, table: bool, div: ?array{width: int, depth: int, column: int}}>
     */
    private function djotImageLineOwners(string $source, string $mask): array
    {
        $imageLines = [];
        $imageOffset = 0;
        $imageItemColumn = 0;
        $imageItemQuote = 0;
        $imageBlank = false;
        $imageDivs = [];
        $imageOwnerMarker = '/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+/';
        foreach (explode("\n", $source) as $line) {
            $contentStart = $this->djotContentStart($line);
            [$depth, $body] = $this->quoted($line);
            $text = ltrim($body, " \t");
            $indent = strlen($body) - strlen($text);
            $itemBody = $line;
            for ($quote = 0; $quote < $imageItemQuote; $quote++) {
                if (preg_match('/^[ \t]*>(?:[ \t]|$)/', $itemBody, $prefix) !== 1) {
                    break;
                }
                $itemBody = substr($itemBody, strlen($prefix[0]));
            }
            $ownerIndent = strlen($itemBody) - strlen(ltrim($itemBody, " \t"));
            if (preg_match($imageOwnerMarker, $text) === 1) {
                $imageItemColumn = $indent + 1;
                $imageItemQuote = $depth;
            } elseif ($text !== '' && ($depth < $imageItemQuote || $imageBlank && $ownerIndent < $imageItemColumn || $depth === $imageItemQuote && $indent < $imageItemColumn && preg_match('/^(?:#{1,6} |:{3,}|[-*+] |\{|\|)/', $text) === 1)) {
                $imageItemColumn = 0;
            }
            $imageBlank = $text === '';
            if (preg_match('/^(:{3,})(?:[ \t]+\S.*)?[ \t]*$/', substr($line, $contentStart), $delimiter) === 1 && $mask[$imageOffset + $contentStart] !== ' ') {
                $owner = $imageDivs === [] ? null : $imageDivs[array_key_last($imageDivs)];
                if ($owner !== null && substr_count(substr($line, 0, $contentStart), '>') === $owner['depth'] && preg_match('/^:{3,}[ \t]*$/', substr($line, $contentStart)) === 1 && strlen($delimiter[1]) >= $owner['width']) {
                    array_pop($imageDivs);
                } else {
                    $imageDivs[] = ['width' => strlen($delimiter[1]), 'depth' => substr_count(substr($line, 0, $contentStart), '>'), 'column' => $contentStart];
                }
            }
            $imageLines[] = ['start' => $imageOffset + $contentStart, 'end' => $imageOffset + strlen($line) + 1, 'quotes' => substr_count(substr($line, 0, $contentStart), '>'), 'itemColumn' => $imageItemColumn, 'itemQuote' => $imageItemQuote, 'table' => ($line[$contentStart] ?? '') === '|', 'div' => $imageDivs === [] ? null : $imageDivs[array_key_last($imageDivs)]];
            $imageOffset += strlen($line) + 1;
        }

        return $imageLines;
    }

    /**
     * @return \Closure(int, int, int, int, int, ?int): bool
     */
    private function djotImageBoundaryIndex(string $source, string $mask): Closure
    {
        $offsets = [];
        $thresholds = [];
        $itemThresholds = [];
        $stride = strlen($source) + 1;
        $divClosers = [];
        $divs = [];
        $offset = 0;
        $block = '/^(?:[-*+](?:[ \t]|$)|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)] |\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\) |: |#{1,6} |`{3,}|~{3,}|:{3,}(?:[ \t]*[A-Za-z0-9_-]+)?[ \t]*$|\||\^ |\[[^\]]+\]:)/';
        foreach (explode("\n", $source) as $line) {
            [$depth, $body] = $this->quoted($line);
            $blank = trim($body, " \t") === '';
            $text = ltrim($body, " \t");
            $indent = strlen($body) - strlen($text);
            $attributes = str_starts_with($text, '{') ? $this->readDjotWordAttributes($text, 0) : null;
            $extraBlock = preg_match('/^(?:[-*][ \t]*){3,}$/', $text) === 1 || $attributes !== null && trim(substr($text, $attributes['end']), " \t") === '';
            if ($blank || preg_match($block, $text) === 1 || $extraBlock) {
                $offsets[] = $offset;
                $thresholds[] = $depth + ($blank ? 0 : 1);
                $itemThresholds[] = $depth * $stride + ($blank ? -1 : $indent);
            }
            $contentAt = $this->djotContentStart($line);
            if (preg_match('/^(:{3,})(?:[ \t]+\S.*)?[ \t]*$/', substr($line, $contentAt), $delimiter) === 1 && $mask[$offset + $contentAt] !== ' ') {
                $owner = $divs === [] ? null : $divs[array_key_last($divs)];
                if ($owner !== null && $depth === $owner['depth'] && preg_match('/^:{3,}[ \t]*$/', substr($line, $contentAt)) === 1 && strlen($delimiter[1]) >= $owner['width']) {
                    $divClosers[$owner['at']] = $offset;
                    array_pop($divs);
                } else {
                    $divs[] = ['width' => strlen($delimiter[1]), 'depth' => $depth, 'at' => $offset];
                }
            }
            $offset += strlen($line) + 1;
        }
        $size = 1;
        $boundaryCount = count($offsets);
        while ($size < $boundaryCount) {
            $size *= 2;
        }
        $tree = array_fill(0, $size * 2, PHP_INT_MAX);
        $itemTree = array_fill(0, $size * 2, PHP_INT_MAX);
        foreach ($thresholds as $at => $threshold) {
            $tree[$size + $at] = $threshold;
            $itemTree[$size + $at] = $itemThresholds[$at];
        }
        for ($at = $size - 1; $at > 0; $at--) {
            $tree[$at] = min($tree[$at * 2], $tree[$at * 2 + 1]);
            $itemTree[$at] = min($itemTree[$at * 2], $itemTree[$at * 2 + 1]);
        }
        $after = static function (int $offset) use ($offsets): int {
            $low = 0;
            $high = count($offsets);
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                if ($offsets[$middle] <= $offset) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        };

        return static function (int $start, int $end, int $depth, int $itemColumn, int $itemQuote, ?int $divAt) use ($after, $size, $tree, $itemTree, $stride, $divClosers): bool {
            if ($divAt !== null && ($divClosers[$divAt] ?? PHP_INT_MAX) <= $end) {
                return false;
            }
            $boundary = $itemQuote * $stride + $itemColumn;
            $left = $after($start) + $size;
            $right = $after($end) + $size;
            while ($left < $right) {
                if ($left % 2) {
                    if ($tree[$left] <= $depth || $itemColumn > 0 && $itemTree[$left] < $boundary) {
                        return false;
                    }
                    $left++;
                }
                if ($right % 2) {
                    $right--;
                    if ($tree[$right] <= $depth || $itemColumn > 0 && $itemTree[$right] < $boundary) {
                        return false;
                    }
                }
                $left = intdiv($left, 2);
                $right = intdiv($right, 2);
            }

            return true;
        };
    }

    /**
     * @param string $source
     * @param string $mask
     * @param \Closure(array<int, array{string, int}>, bool): string $replace
     * @param bool $allowIncomplete
     *
     * @return string
     */
    private function replaceImageLabels(string $source, string $mask, Closure $replace, bool $allowIncomplete = false): string
    {
        if (!str_contains($source, '![')) {
            return $source;
        }
        $opens = [];
        $closes = [];
        $length = strlen($source);
        $boundaries = array_fill_keys($this->djotInlineBoundaries($source, $mask), true);
        preg_match_all('/\n[ \t]*\n/', $source, $blankLines, PREG_OFFSET_CAPTURE);
        foreach ($blankLines[0] as $blank) {
            $boundaries[$blank[1]] = true;
        }
        $referenceEnds = [];
        $referenceEnd = -1;
        for ($at = $length - 1; $at >= 0; $at--) {
            if (isset($boundaries[$at])) {
                $referenceEnd = -1;
            }
            if ($source[$at] === ']' && !$this->isDjotEscaped($source, $at)) {
                $referenceEnd = $at;
            }
            if ($at > 0 && $source[$at - 1] === '[') {
                $referenceEnds[$at] = $referenceEnd;
            }
        }
        for ($at = 0; $at < $length; $at++) {
            if (isset($boundaries[$at])) {
                $opens = [];
            }
            if ($mask[$at] === '[' && !$this->isDjotEscaped($source, $at)) {
                $opens[] = $at;
            } elseif ($mask[$at] === ']' && !$this->isDjotEscaped($source, $at)) {
                $open = array_pop($opens);
                $completeTarget = ($source[$at + 1] ?? '') === '(' ? ($mask[$at + 1] ?? '') === ' ' : ($source[$at + 1] ?? '') === '[' && ($referenceEnds[$at + 2] ?? -1) >= $at + 2;
                if ($open !== null && $open > 0 && $source[$open - 1] === '!' && $mask[$open - 1] === '!' && !$this->isDjotEscaped($source, $open - 1) && ($completeTarget || $allowIncomplete && in_array($source[$at + 1] ?? '', ['(', '['], true))) {
                    $closes[$open - 1] = [$at, $completeTarget];
                }
            }
        }
        if (!$closes) {
            return $source;
        }
        $chunks = [];
        $cursor = 0;
        for ($at = 0; $at < $length; $at++) {
            if (!isset($closes[$at])) {
                continue;
            }
            [$end, $completeTarget] = $closes[$at];
            $image = substr($source, $at, $end + 1 - $at);
            $replaced = $replace([[$image, $at], [substr($source, $at + 2, $end - $at - 2), $at + 2]], $completeTarget);
            if ($replaced === $image) {
                continue;
            }
            $chunks[] = substr($source, $cursor, $at - $cursor);
            $chunks[] = $replaced;
            $cursor = $end + 1;
            $at = $end;
        }
        $chunks[] = substr($source, $cursor);

        return implode('', $chunks);
    }

    /**
     * @param string $label
     * @param int $quotes
     * @param int $itemColumn
     * @param int $itemQuote
     * @param array{width: int, depth: int, column: int}|null $div
     *
     * @return array{normalized: string, continuations: list<string>, valid: bool}
     */
    private function djotImageAltLayout(string $label, int $quotes, int $itemColumn = 0, int $itemQuote = 0, ?array $div = null): array
    {
        $continuations = [];
        $valid = true;
        $labelLines = explode("\n", $label);
        foreach ($labelLines as $index => $line) {
            if ($index === 0) {
                continue;
            }
            [$depth, $body] = $this->quoted($line);
            $ownerOffset = 0;
            for ($quote = 0; $quote < $itemQuote; $quote++) {
                if (preg_match('/\G[ \t]*>(?:[ \t]|$)/', $line, $prefix, 0, $ownerOffset) !== 1) {
                    break;
                }
                $ownerOffset += strlen($prefix[0]);
            }
            $ownerBody = substr($line, $ownerOffset);
            $ownerIndent = strlen($ownerBody) - strlen(ltrim($ownerBody, " \t"));
            $text = ltrim($body, " \t");
            $attributes = str_starts_with($text, '{') ? $this->readDjotWordAttributes($text, 0) : null;
            $extraAttributes = $attributes !== null && trim(substr($text, $attributes['end']), " \t") === '';
            $extraBlock = preg_match('/^(?:[-*][ \t]*){3,}$/', $text) === 1 || $extraAttributes;
            if (($extraBlock || preg_match('/^[ \t]*>(?:[ \t]|$)/', $ownerBody) === 1) && ($depth < $quotes || $itemColumn > 0 && $ownerIndent < $itemColumn)) {
                $valid = false;
            }
            if ($div !== null && $depth === $div['depth'] && preg_match('/^[ \t]*:{3,}[ \t]*$/', $body) === 1 && strlen(trim($body)) >= $div['width'] && strlen($body) - strlen($text) <= $div['column'] + 3) {
                $valid = false;
            }
            if (($depth < $quotes || $itemColumn > 0 && $ownerIndent < $itemColumn) && preg_match('/^(?:[-*+](?:[ \t]|$)|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)] |\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\) |: |#{1,6} |`{3,}|~{3,}|:{3,}(?:[ \t]*[A-Za-z0-9_-]+)?[ \t]*$|\||\^ |\[[^\]]+\]:)/', ltrim($body, " \t")) === 1) {
                $valid = false;
            }
            $start = 0;
            for ($quote = 0; $quote < $quotes; $quote++) {
                while (($line[$start] ?? '') === ' ' || ($line[$start] ?? '') === "\t") {
                    $start++;
                }
                if (($line[$start] ?? '') !== '>' || $start + 1 < strlen($line) && $line[$start + 1] !== ' ' && $line[$start + 1] !== "\t") {
                    break;
                }
                $start++;
            }
            while (($line[$start] ?? '') === ' ' || ($line[$start] ?? '') === "\t") {
                $start++;
            }
            $continuations[] = substr($line, 0, $start);
            $labelLines[$index] = substr($line, $start);
        }
        $label = implode("\n", $labelLines);

        return ['normalized' => $label, 'continuations' => $continuations, 'valid' => $valid && preg_match('/\n[ \t]*\n/', $label) !== 1];
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
        while (preg_match('/\G[ \t]*>(?:[ \t]|$)/', $line, $prefix, 0, $offset)) {
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
     * @param string $source
     * @param string $mask
     * @param bool $codeScopes
     * @param array<int, list<int>>|null $divClosers
     * @param array<int, array{column: int|null, depth: int}>|null $divOwners
     *
     * @return array<int, int>
     */
    private function djotInlineBoundaries(string $source, string $mask, bool $codeScopes = false, ?array &$divClosers = null, ?array &$divOwners = null): array
    {
        $boundaries = [];
        $rows = $this->djotTableRows($source, $mask);
        $items = [];
        $divs = [];
        $previousBlock = true;
        $previousDepth = 0;
        $codeFence = null;
        $offset = 0;
        $previousBlank = true;
        foreach (explode("\n", $source) as $row => $line) {
            $lineLength = strlen($line);
            if ($codeScopes && str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $line, $quote);
            $prefix = $quote[0] ?? '';
            $depth = substr_count($prefix, '>');
            $content = substr($line, strlen($prefix));
            $trimmed = ltrim($content, " \t");
            $indent = strlen($content) - strlen($trimmed);
            preg_match('/^(?:\[\^[^\]\n]+\]:[ \t]*|(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+)/', $trimmed, $marker);
            $active = $items !== [] ? $items[array_key_last($items)] : null;
            $startsItem = $marker !== [] && (($previousBlank || $codeScopes && $previousBlock) || ($active !== null && $depth <= $active['depth'] && $indent < $active['column']));
            $blank = $trimmed === '' || $trimmed === "\r";
            if ($codeScopes) {
                if ($previousBlock && !$blank && !$startsItem) {
                    while ($items !== [] && $depth === $items[array_key_last($items)]['depth'] && $indent < $items[array_key_last($items)]['column']) {
                        array_pop($items);
                    }
                }
                $blockStart = preg_match('/^(?:#{1,6}(?: |$)|:{3,}|[`~]{3,}|(?:[*-][ \t]*){3,}$)/', $trimmed) === 1 || $marker !== [];
                while ($divs !== []) {
                    $owner = $divs[array_key_last($divs)];
                    $outsideQuote = $depth < $owner['depth'] && ($blank || $previousBlank || $startsItem || $blockStart);
                    $outsideItem = $owner['itemColumn'] !== null && $depth === $owner['itemDepth']
                        && $indent < $owner['itemColumn'] && !$blank && ($previousBlank || $startsItem || $blockStart);
                    if (!$outsideQuote && !$outsideItem) {
                        break;
                    }
                    array_pop($divs);
                }
                $markerWidth = $startsItem ? strlen($marker[0]) : 0;
                $body = substr($trimmed, $markerWidth);
                $column = $indent + $markerWidth;
                $padding = strlen($body) - strlen(ltrim($body, " \t"));
                $body = substr($body, $padding);
                $column += $padding;
                preg_match('/^(?:>[ \t]+)*/', $body, $nestedQuote);
                $nestedPrefix = $nestedQuote[0] ?? '';
                $bodyDepth = $depth + substr_count($nestedPrefix, '>');
                $body = substr($body, strlen($nestedPrefix));
                if ($nestedPrefix !== '') {
                    $column = 0;
                }
                $canOpen = $previousBlock || $previousBlank || $startsItem || ($blockStart && ($depth < $previousDepth || ($items !== [] && $depth === $items[array_key_last($items)]['depth'] && $indent < $items[array_key_last($items)]['column'])));
                if ($codeFence !== null && ($depth < $codeFence['depth'] || ($codeFence['itemColumn'] !== null && $depth === $codeFence['depth'] && $indent < $codeFence['itemColumn'] && !$blank))) {
                    $codeFence = null;
                    while ($items !== [] && $depth === $items[array_key_last($items)]['depth'] && $indent < $items[array_key_last($items)]['column']) {
                        array_pop($items);
                    }
                }
                preg_match('/^(`{3,}|~{3,})(.*)$/', $body, $ticks);
                if ($codeFence !== null) {
                    if (
                        $ticks !== [] && $ticks[1][0] === $codeFence['ch'] && strlen($ticks[1]) >= $codeFence['width']
                        && trim($ticks[2], " \t") === '' && $bodyDepth === $codeFence['depth']
                    ) {
                        $codeFence = null;
                        $previousBlock = true;
                    } else {
                        $previousBlock = false;
                    }
                } elseif ($ticks !== [] && $canOpen && ($ticks[1][0] !== '`' || !str_contains($ticks[2], '`'))) {
                    $codeFence = ['ch' => $ticks[1][0], 'width' => strlen($ticks[1]), 'depth' => $bodyDepth, 'itemColumn' => $startsItem ? $indent + strlen($marker[0]) : (($active !== null && $depth === $active['depth'] && $indent >= $active['column']) ? $active['column'] : null)];
                    $previousBlock = false;
                } else {
                    preg_match('/^(:{3,})(?:[ \t]*[A-Za-z0-9_-]+)?[ \t]*$/', $body, $fence);
                    $owner = $divs !== [] ? $divs[array_key_last($divs)] : null;
                    $visible = ($mask[$offset + strlen($line) - strlen($body)] ?? '') === ':';
                    if (
                        $fence !== [] && $visible && $owner !== null && trim(substr($body, strlen($fence[1])), " \t") === ''
                        && $bodyDepth === $owner['depth'] && strlen($fence[1]) >= $owner['minimumWidth']
                    ) {
                        $boundaries[] = $offset;
                        $low = $owner['scopeStart'];
                        $high = count($divs) - 1;
                        while ($low < $high) {
                            $mid = intdiv($low + $high, 2);
                            if ($divs[$mid]['minimumWidth'] <= strlen($fence[1])) {
                                $high = $mid;
                            } else {
                                $low = $mid + 1;
                            }
                        }
                        if ($divClosers !== null) {
                            $divClosers[$offset] = array_reverse(array_column(array_slice($divs, $low), 'start'));
                        }
                        if ($divOwners !== null) {
                            $divOwners[$offset] = ['column' => $divs[$low]['itemColumn'], 'depth' => $divs[$low]['itemDepth']];
                        }
                        for ($remaining = count($divs) - $low; $remaining > 0; $remaining--) {
                            array_pop($divs);
                        }
                        $previousBlock = true;
                    } elseif ($fence !== [] && $visible && $canOpen) {
                        $boundaries[] = $offset;
                        $divs[] = [
                            'width' => strlen($fence[1]),
                            'column' => $column,
                            'depth' => $bodyDepth,
                            'itemColumn' => $startsItem ? $indent + (str_starts_with($marker[0], '[^') ? 2 : strlen($marker[0])) : (($active !== null && $depth === $active['depth'] && $indent >= $active['column']) ? $active['column'] : null),
                            'itemDepth' => $depth,
                            'start' => $offset,
                            'minimumWidth' => $owner !== null && $owner['depth'] === $bodyDepth ? min($owner['minimumWidth'], strlen($fence[1])) : strlen($fence[1]),
                            'scopeStart' => $owner !== null && $owner['depth'] === $bodyDepth ? $owner['scopeStart'] : count($divs),
                        ];
                        $previousBlock = true;
                    } else {
                        $attrs = str_starts_with($body, '{') ? $this->readDjotWordAttributes($body, 0) : null;
                        $previousBlock = $blank || $rows[$row] || ($canOpen && (preg_match('/^(?:#{1,6}(?: |$)|(?:[*-][ \t]*){3,}$|\[[^\]]+\]:)/', $body) === 1 || ($attrs !== null && trim(substr($body, $attrs['end']), " \t") === '')));
                    }
                }
            }
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
            $previousDepth = $depth;
            $offset += $lineLength + 1;
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
        $imageLines = null;
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
        $pipeTargets = [];
        $pipeDefinitions = [];
        $occupiedLabels = null;
        $pipeSerial = 0;
        $line = 0;
        $boundaries = array_fill_keys($this->djotInlineBoundaries($source, $mask), true);
        if (str_contains($source, ':::')) {
            $divClosers = [];
            $this->djotInlineBoundaries($source, $mask, true, $divClosers);
            foreach ($divClosers ?? [] as $at => $_) {
                $boundaries[$at] = true;
            }
        }
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
            $authoredLabel = $owner['at'] > 0 && $source[$owner['at'] - 1] === '!' && !$this->isDjotEscaped($source, $owner['at'] - 1) ? substr($source, $owner['at'] + 1, $owner['labelEnd'] - $owner['at'] - 1) : '';
            if (str_contains($authoredLabel, "\n")) {
                $imageLines ??= $this->djotImageLineOwners($source, $mask);
                $left = 0;
                $right = count($imageLines);
                while ($left < $right) {
                    $middle = intdiv($left + $right, 2);
                    if ($imageLines[$middle]['end'] <= $owner['at']) {
                        $left = $middle + 1;
                    } else {
                        $right = $middle;
                    }
                }
                $imageOwner = $imageLines[$left];
                if (!$this->djotImageAltLayout($authoredLabel, $imageOwner['quotes'], $imageOwner['itemColumn'], $imageOwner['itemQuote'])['valid']) {
                    array_pop($stack);
                    $destinationOwner = null;

                    continue;
                }
            }
            $label = $this->djotLinkLabel($source, $mask, $angles, $edits, $owner['at'] + 1, $owner['labelEnd']);
            $rawDestination = '';
            for ($at = $owner['target']; $at < $i;) {
                $end = $literalNotes[$at] ?? null;
                if ($end !== null && $end <= $i) {
                    $rawDestination .= preg_replace('/\n[ \t]*/', ' ', str_replace('\\', ($rows[$line] ?? false) ? '\\\\' : '%5C', substr($source, $at, $end - $at)));
                    $at = $end;
                } else {
                    $rawDestination .= $source[$at++];
                }
            }
            $destination = $this->djotDestinationLines($rawDestination, $owner['depth']);
            if ($owner['at'] > 0 && $source[$owner['at'] - 1] === '!' && !$this->isDjotEscaped($source, $owner['at'] - 1) && !str_contains($authoredLabel, "\n") && str_contains($label, '[')) {
                $altDefinitions = [];
                preg_match_all('/\[(carve-djot-pipe-target-[0-9]+)\]/', $label, $altAliases);
                foreach ($altAliases[1] as $alias) {
                    if (isset($pipeDefinitions[$alias])) {
                        $altDefinitions[] = $pipeDefinitions[$alias];
                    }
                }
                $label = (new CarveConverter(smartTypography: false, renderer: new DjotImageChildTextRenderer()))->convert(str_replace("\0U\0", "\0", $this->convertImageQuoteText(($altDefinitions !== [] ? implode("\n", $altDefinitions) . "\n\n" : '') . 'DJOTALT ' . $this->removeInheritedAttributeMarkers($label, $inherited) . ' DJOTEND', true)));
                $label = substr(preg_replace('/ DJOTEND\n?$/', '', $label) ?? $label, 8);
                $label = preg_replace_callback('/[\\\\\[\]\"\']/', static fn (array $match): string => '\\' . $match[0], $label) ?? $label;
            }
            $target = preg_replace_callback(($rows[$line] ?? false) && str_contains($destination, '|') ? '/\\\\([ \t!-\/:-@\[-`{-~])|[\\\\\s"<>`]/u' : '/\\\\([ \t!-\/:-@\[-`{-~])|[\\\\\s"<>`()]/u', static fn (array $match): string => isset($match[1]) ? (str_contains('()\\', $match[1]) ? '\\' . $match[1] : (str_contains(" \t\"<>`", $match[1]) ? rawurlencode($match[1]) : $match[1])) : ($match[0] === '\\' ? '\\\\' : rawurlencode($match[0])), $destination) ?? $destination;
            $target = preg_replace('/\](?=[\[{])/', '%5D', $target) ?? $target;
            if (($rows[$line] ?? false) && str_contains($target, '|')) {
                if (!isset($pipeTargets[$target])) {
                    if ($occupiedLabels === null) {
                        preg_match_all('/\[([^\[\]\n]*)\]/', $source, $labelMatches);
                        $occupiedLabels = array_fill_keys(array_map(static fn (string $label): string => preg_replace('/[ \t\r\n]+/', ' ', trim(preg_replace('/\\\\([ \t!-\/:-@\[-`{-~])/', '$1', $label) ?? $label)) ?? $label, $labelMatches[1]), true);
                    }
                    do {
                        $alias = 'carve-djot-pipe-target-' . ++$pipeSerial;
                    } while (isset($occupiedLabels[$alias]));
                    $occupiedLabels[$alias] = true;
                    $pipeTargets[$target] = $alias;
                    $pipeDefinitions[$alias] = '[' . $alias . ']: ' . $target;
                }
                $edits[$owner['at']] = ['end' => $i + 1, 'text' => '[' . $label . '][' . $pipeTargets[$target] . ']'];
            } else {
                $edits[$owner['at']] = ['end' => $i + 1, 'text' => '[' . $label . '](' . $target . ')'];
            }
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

        preg_match_all('/\[(carve-djot-pipe-target-[0-9]+)\]/', $output, $aliases);
        $used = array_fill_keys($aliases[1], true);
        $definitions = [];
        foreach ($pipeTargets as $target => $alias) {
            if (isset($used[$alias])) {
                $definitions[] = '[' . $alias . ']: ' . $target;
            }
        }

        return ($definitions !== [] ? implode("\n", $definitions) . "\n\n" : '') . $output;
    }

    private function carryDjotAttributesAcrossDivClosers(string $source): string
    {
        if (!str_contains($source, '{') || !str_contains($source, ':::')) {
            return $source;
        }
        $mask = $this->maskCodeAndDestinations($source);
        $closers = [];
        $owners = [];
        $this->djotInlineBoundaries($source, $mask, true, $closers, $owners);
        if ($closers === []) {
            return $source;
        }
        $lines = explode("\n", $source);
        $removed = $moved = $pending = $residues = [];
        $crossed = false;
        $offset = $previousDepth = 0;
        $previous = '';
        $closedOwner = null;
        foreach ($lines as $index => $line) {
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*[ \t]*/', $line, $prefix);
            $physical = $prefix[0] ?? '';
            $start = strlen($physical);
            $content = substr($line, $start);
            $depth = substr_count($physical, '>');
            $followsBlock = $pending !== [] || $previous === '' || preg_match('/^(?:#{1,6} |:{3,}|[`~]{3,}|\[[^\]]+\]:|(?:[*-][ \t]*){3,}$)/', $previous) === 1;
            $comment = str_starts_with($content, '{') && ($followsBlock || ($mask[$offset + $start] ?? '') === '{') ? $this->readDjotWordAttributes($content, 0) : null;
            if ($followsBlock && $depth === $previousDepth && $comment !== null && $comment['parts'] === [] && $comment['end'] === strlen(rtrim($content, " \t\r"))) {
                $offset += strlen($line) + 1;

                continue;
            }
            $attrs = ($mask[$offset + $start] ?? '') === '{' ? $comment : null;
            $whole = $attrs !== null && $attrs['end'] === strlen(rtrim($content, " \t\r"));
            if ($crossed && !isset($closers[$offset]) && trim($content, " \t\r") !== '' && (!$whole || $depth !== $previousDepth)) {
                preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $line, $quotePrefix);
                $indent = strspn(substr($line, strlen($quotePrefix[0] ?? '')), " \t");
                $peerItem = $closedOwner !== null && $closedOwner['column'] !== null && $depth === $closedOwner['depth'] && $indent < $closedOwner['column'] && preg_match('/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))(?:[ \t]+|$)/', substr($line, strlen($physical))) === 1;
                if (!$peerItem || preg_match('/^(?:[-*][ \t]*){3,}$/', $content) === 1) {
                    $targetPrefix = $physical;
                    if ($depth > $previousDepth) {
                        preg_match_all('/>/', $physical, $markers, PREG_OFFSET_CAPTURE);
                        $targetPrefix = substr($physical, 0, $markers[0][$previousDepth][1]);
                    }
                    foreach ($pending as $attr) {
                        $removed[$attr['line']] = true;
                        $moved[$index][] = $targetPrefix . $attr['value'];
                    }
                }
                $pending = [];
                $crossed = false;
            }
            if ($whole && $followsBlock) {
                if (!$crossed && $depth > $previousDepth) {
                    $pending = [];
                }
                $pending[] = ['line' => $index, 'value' => rtrim($content, " \t\r")];
                if ($depth > $previousDepth) {
                    $residues[$index] = rtrim($physical);
                }
            } elseif (isset($closers[$offset])) {
                $crossed = $crossed || $pending !== [];
                $closedOwner = $owners[$offset] ?? null;
            } elseif (trim($content, " \t\r") !== '') {
                $pending = [];
            }
            if (!$whole) {
                $previous = trim($content, " \t\r");
            }
            $previousDepth = trim($content, " \t\r") === '' ? min($previousDepth, $depth) : $depth;
            $offset += strlen($line) + 1;
        }
        if ($crossed) {
            foreach ($pending as $attr) {
                $removed[$attr['line']] = true;
            }
        }
        if ($removed === []) {
            return $source;
        }
        $out = [];
        $lineCount = count($lines);
        for ($index = 0; $index <= $lineCount; $index++) {
            foreach ($moved[$index] ?? [] as $attr) {
                $out[] = $attr;
            }
            if ($index < $lineCount) {
                if (!isset($removed[$index])) {
                    $out[] = $lines[$index];
                } elseif (isset($residues[$index])) {
                    $out[] = $residues[$index];
                }
            }
        }

        return implode("\n", $out);
    }

    private function carryDjotAttributesAcrossQuoteExits(string $source): string
    {
        if (!str_contains($source, '>') || !str_contains($source, '{')) {
            return $source;
        }
        $lines = explode("\n", $source);
        $mask = explode("\n", $this->maskCodeAndDestinations($source));
        $contexts = [];
        $offset = 0;
        $previousDepth = 0;
        foreach ($lines as $n => $line) {
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $line, $matches);
            $prefix = $matches[0] ?? '';
            $body = substr($line, strlen($prefix));
            $indent = strspn($body, " \t");
            $attrs = ($mask[$n][strlen($prefix) + $indent] ?? '') === '{' ? $this->readDjotWordAttributes($body, $indent) : null;
            $currentDepth = substr_count($prefix, '>');
            $contexts[] = ['opens' => $currentDepth > $previousDepth, 'prefix' => $prefix, 'depth' => substr_count($prefix, '>'), 'body' => $body, 'indent' => $indent, 'attributes' => $attrs !== null && $attrs['end'] === strlen(rtrim($body)), 'offset' => $offset, 'continued' => false, 'listOwned' => false];
            $previousDepth = $currentDepth;
            $offset += strlen($line) + 1;
        }
        $listColumns = [];
        foreach ($contexts as $n => $context) {
            if (trim($context['body']) === '') {
                continue;
            }
            $columns = $listColumns[$context['depth']] ?? [];
            $item = preg_match('/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+/', substr($context['body'], $context['indent'])) === 1;
            if ($item) {
                while ($columns !== [] && $columns[array_key_last($columns)] >= $context['indent']) {
                    array_pop($columns);
                }
                $columns[] = $context['indent'];
                $listColumns[$context['depth']] = $columns;
            }
            if ($context['attributes']) {
                $contexts[$n]['continued'] = $this->djotCommentContinuesParagraph($source, $context['offset'] + strlen($context['prefix']) + $context['indent']);
                foreach ($columns as $column) {
                    $contexts[$n]['listOwned'] = $contexts[$n]['listOwned'] || $column < $context['indent'];
                }
            } elseif ($context['indent'] <= ($columns !== [] ? $columns[array_key_last($columns)] : -1) && !$item) {
                unset($listColumns[$context['depth']]);
            }
        }
        $moved = [];
        $following = null;
        $nextContent = null;
        $destinationDepth = null;
        for ($n = count($lines) - 1; $n >= 0; $n--) {
            $context = $contexts[$n];
            if ($context['attributes']) {
                if ($following !== null && $destinationDepth !== null && $context['depth'] > $destinationDepth && !($nextContent !== null && $contexts[$nextContent]['opens']) && !$context['continued'] && !$context['listOwned']) {
                    $target = $lines[$following];
                    $end = 0;
                    for ($level = 0; $level < $destinationDepth; $level++) {
                        preg_match('/^[ \t]*>(?:[ \t]|$)/', substr($target, $end), $prefix);
                        $end += strlen($prefix[0] ?? '');
                    }
                    $end += strspn(substr($target, $end), " \t");
                    $ownerEnd = 0;
                    for ($level = 0; $level < $destinationDepth; $level++) {
                        preg_match('/^[ \t]*>(?:[ \t]|$)/', substr($context['prefix'], $ownerEnd), $ownerPrefix);
                        $ownerEnd += strlen($ownerPrefix[0] ?? '');
                    }
                    $ownerIndent = strspn(substr($context['prefix'], $ownerEnd), " \t");
                    $targetPrefix = $ownerIndent > 0 ? substr($context['prefix'], 0, $ownerEnd) . str_repeat(' ', $ownerIndent) : substr($target, 0, $end);
                    $moved[$following][] = $targetPrefix . substr($context['body'], $context['indent']);
                    $lines[$n] = rtrim($context['prefix']);
                } elseif ($destinationDepth !== null && $context['depth'] <= $destinationDepth) {
                    $following = $n;
                }
            } elseif (trim($context['body']) === '') {
                if ($destinationDepth !== null) {
                    $destinationDepth = min($destinationDepth, $context['depth']);
                }
            } else {
                $following = $n;
                $destinationDepth = $context['depth'];
            }
            if (trim($context['body']) !== '' || $context['opens']) {
                $nextContent = $n;
            }
        }
        $output = [];
        foreach ($lines as $n => $line) {
            foreach (array_reverse($moved[$n] ?? []) as $attribute) {
                $output[] = $attribute;
            }
            $output[] = $line;
        }

        return implode("\n", $output);
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
            $attrs = $this->readDjotWordAttributes($source, $i, preserveShorthand: true);
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

    /**
     * @param string $source
     * @param array<int, array{label: string, target: string, end: int}>|null $accepted
     */
    private function foldDjotReferences(string $source, ?array &$accepted = null): string
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
        $definitionOwners = [];
        $fenceMask = null;
        for ($n = 0, $count = count($lines); $n < $count; $n++) {
            $line = $lines[$n];
            [$depth] = $this->quoted($line);
            $at = $this->djotContentStart($line);
            $content = substr($line, $at);
            $definition = preg_match('/^\[(?!\^[^\]\n]+\]:(?:[ \t\r]|$))([^\]\n]*)\]:[ \t]*+([^ \t\r\n]*+)\r?$/', $content, $match) === 1;
            $previousLine = $lines[$n - 1] ?? '';
            $previousAt = $this->djotContentStart($previousLine);
            $previousNote = strpos(substr($previousLine, 0, $previousAt), '[^');
            $previousOwner = $previousNote === false ? $previousAt : $previousNote + 1;
            $ownerPrefix = substr($line, 0, $at);
            $noteAt = strpos($ownerPrefix, '[^');
            $hasOwnerMarker = $noteAt === false && preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', $ownerPrefix, $ownerMatch, PREG_OFFSET_CAPTURE) === 1;
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*[ \t]*/', $line, $physical);
            $physicalPrefix = $physical[0] ?? '';
            $physicalLength = strlen($physicalPrefix);
            $definitionOwner = $definitionOwners === [] ? null : $definitionOwners[array_key_last($definitionOwners)];
            $ownerExit = $definitionOwner !== null && ($depth < $definitionOwner['depth'] || $depth === $definitionOwner['depth'] && $physicalLength < $definitionOwner['start']);
            $attrs = str_starts_with($content, '{') ? $this->readDjotWordAttributes($content, 0) : null;
            $metadata = $definition || $attrs !== null && $attrs['end'] === strlen(rtrim($content));
            if ($ownerExit && $metadata && ($mask[$offset + $at] ?? '') === ' ') {
                $fenceMask ??= $this->maskDjotFences($source, null, $rows, true);
            }
            $metadataExit = $ownerExit && $metadata && (($mask[$offset + $at] ?? '') !== ' ' || ($fenceMask[$offset + $at] ?? ' ') !== ' ');
            $outdented = $metadataExit || $at < $previousOwner && preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', substr($previousLine, 0, $previousNote === false ? $previousAt : $previousNote)) === 1;
            $boundary = $previous === '' || $previousAttributeBlock || ($rows[$n - 1] ?? false) || $depth !== $previousDepth || preg_match('/^(?:[ \t]*>[ \t]*)*[ \t]*(?:[-*][ \t]*){3,}$/', $previousLine) === 1 || $outdented || preg_match('/^(?:#{1,6} |:{3,}|[`~]{3,}|\[[^\]]*\]:|(?:[-*][ \t]*){3,}$)/', $previous) === 1;
            $marker = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', ($noteAt === false ? $ownerPrefix : substr($ownerPrefix, 0, $noteAt))) === 1;
            $ownerBody = substr($line, $physicalLength);
            $rowEnd = strlen(rtrim($line)) - 1;
            $boundaryRow = ($ownerExit || ($rows[$n - 1] ?? false)) && str_starts_with($ownerBody, '|') && $rowEnd > $physicalLength && ($mask[$offset + $physicalLength] ?? '') === '|' && ($mask[$offset + $rowEnd] ?? '') === '|' && ($line[$rowEnd - 1] ?? '') !== '\\';
            $rowEntry = $boundaryRow && $ownerExit && !($rows[$n] ?? false);
            if ($boundaryRow) {
                $rows[$n] = true;
            }
            $closesOwner = $boundaryRow || preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|[`~]{3,}|(?:[-*][ \t]*){3,}$)/', $ownerBody) === 1 && ($mask[$offset + $physicalLength] ?? '') !== ' ' || ($rows[$n] ?? false) || preg_match('/^(?:`{3,}|~{3,})[ \t]*=?[A-Za-z0-9_+#.-]*[ \t]*$/', $ownerBody) === 1;
            if ($ownerExit && ($metadataExit || $previous === '' || $closesOwner || ($rows[$n - 1] ?? false))) {
                while (($owner = $definitionOwners === [] ? null : $definitionOwners[array_key_last($definitionOwners)]) !== null && ($depth < $owner['depth'] || $depth === $owner['depth'] && $physicalLength < $owner['start'])) {
                    array_pop($definitionOwners);
                }
            }
            if ($boundary && ($noteAt !== false || $hasOwnerMarker)) {
                $ownerStart = $noteAt !== false ? $noteAt : $ownerMatch[0][1];
                if (($mask[$offset + $ownerStart] ?? '') !== ' ') {
                    $owner = ['start' => $ownerStart + 1, 'depth' => $depth];
                    if (($definitionOwners === [] ? null : $definitionOwners[array_key_last($definitionOwners)]) !== $owner) {
                        $definitionOwners[] = $owner;
                    }
                }
            }
            $separator = ($metadataExit && !$marker || $rowEntry || $definition && ($rows[$n - 1] ?? false) && !$marker && ($ownerExit || $definitionOwner === null)) && $previous !== '' ? $physicalPrefix . "\n" : '';
            if ($separator !== '') {
                $lines[$n] = $separator . $line;
            }
            if ($definition && (($mask[$offset + $at] ?? '') === '[' || $metadataExit && ($fenceMask[$offset + $at] ?? '') === '[') && ($boundary || $marker)) {
                $target = $match[2];
                $end = $n;
                while ($end + 1 < $count) {
                    $next = $lines[$end + 1];
                    [$nextDepth] = $this->quoted($next);
                    $nextAt = $this->djotContentStart($next);
                    $nextPrefix = substr($next, 0, $nextAt);
                    $nextToken = str_ends_with($next, "\r") ? substr($next, $nextAt, -1) : substr($next, $nextAt);
                    if ($nextDepth !== $depth || $nextAt <= $at || str_contains($nextPrefix, '[^') || preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', $nextPrefix) === 1 || preg_match('/^[^ \t\r\n]+$/', $nextToken) !== 1) {
                        break;
                    }
                    $target .= $nextToken;
                    $end++;
                }
                if ($match[1] === '') {
                    $lines[$n] = $separator . substr($line, 0, $at);
                }
                $nextByte = $content[strlen($match[1]) + 3] ?? '';
                if ($accepted !== null) {
                    $accepted[$n] = ['label' => $match[1], 'target' => $target, 'end' => $end];
                }
                if ($match[1] !== '' && ($end > $n || $target !== '' && $nextByte !== ' ')) {
                    $lines[$n] = $separator . substr($line, 0, $at) . '[' . $match[1] . ']:' . ($target !== '' ? ' ' . $target : '');
                }
                if ($end > $n) {
                    for ($k = $n + 1; $k <= $end; $k++) {
                        $offset += strlen($lines[$k]) + 1;
                        $removed[$k] = true;
                    }
                    $n = $end;
                }
            }
            $invalidDefinition = !$definition && preg_match('/^\[(?!\^)[^\]\n]*\]:/', $content) === 1;
            if (($invalidDefinition || $definition && !($boundary || $marker)) && ($mask[$offset + $at] ?? '') === '[') {
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
     * @param string $emptyNote
     */
    private function normalizeDjotFootnotes(string $source, ?Closure $isDefinitionBoundary = null, array $inherited = [], string $emptyNote = '%%%%'): string
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
            preg_match('/^[ \t]*(?:>[ \t]+|>$)*(?:(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})\))[ \t]+)*/', $line, $prefix);
            $noteAt = strpos(substr($line, 0, $this->djotContentStart($line)), '[^');
            $prefixText = $noteAt !== false ? substr($line, 0, $noteAt) : ($prefix[0] ?? '');
            $at = strlen($prefixText);
            $lineHeads[$offset + $at] = true;
            $previousLine = $noteLines[$n - 1] ?? '';
            preg_match('/^[ \t]*(?:>[ \t]+|>$)*(?:(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})\))[ \t]+)*/', $previousLine, $previousPrefix);
            $previousPrefixText = $previousPrefix[0] ?? '';
            $previous = trim(substr($previousLine, strlen($previousPrefixText)));
            $item = preg_match('/(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})\))[ \t]+/', $prefixText) === 1;
            $boundary = ($isDefinitionBoundary !== null && $isDefinitionBoundary($previousLine)) || $previous === '' || ($rows[$n - 1] ?? false) || $item && preg_match('/(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})\))[ \t]+/', $previousPrefixText) === 1 || preg_match('/^(?:[ \t]*>[ \t]*)*[ \t]*(?:[-*][ \t]*){3,}$/', $previousLine) === 1 || $at < strlen($previousPrefixText) && preg_match('/(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})\))[ \t]+/', $previousPrefixText) === 1 || substr_count($prefixText, '>') < substr_count($previousPrefixText, '>') || preg_match('/^(?:#{1,6} |`{3,}|~{3,}|:{3,}|\{|\[[^\]]*\]:|(?:[-*][ \t]*){3,}$)/', $previous) === 1;
            if ($at > strlen($previousPrefixText) && preg_match('/^\[\^[^\]\n]+\]:[ \t]*(.+)$/', $previous, $previousNote) === 1 && preg_match('/^(?:>(?:[ \t]|$)|[-*+][ \t]|#{1,6}(?:[ \t]|$)|[`~]{3,}|:{3,}|[|]|\{|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)][ \t]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)[ \t])/', $previousNote[1]) !== 1) {
                $boundary = false;
            }
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
                preg_match('/^\[\^[^\]\n]+\]:[ \t]*/', substr($line, $at), $header);
                $bodyAt = $at + strlen($header[0] ?? $head[0]);
                preg_match_all('/\[\^([^\]\n]+)\]:(?:[ \t]|$)/', substr($line, $bodyAt, $this->djotContentStart($line) - $bodyAt), $nestedHeads, PREG_OFFSET_CAPTURE);
                foreach ($nestedHeads[0] as $index => $nestedHead) {
                    $nestedAt = $bodyAt + $nestedHead[1];
                    if (($mask[$offset + $nestedAt] ?? '') !== '[') {
                        continue;
                    }
                    $nestedKey = $keyOf($nestedHeads[1][$index][0]);
                    $defined[$nestedKey] = true;
                    $definitions[$offset + $nestedAt] = [$nestedKey, $offset + $nestedAt + 2 + strlen($nestedHeads[1][$index][0])];
                    $lineHeads[$offset + $nestedAt] = true;
                    if (trim(substr($line, $nestedAt + strlen($nestedHead[0]))) === '') {
                        $emptyDefinitions[$offset + $nestedAt] = true;
                    }
                    if ($unsupported($nestedKey)) {
                        $alias($nestedKey);
                    }
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
                if (preg_match('/\G\n[ \t]*(?:>[ \t]+)*>?\n/', $source, offset: $i)) {
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
            if ($at > 0 && $source[$at - 1] === '!' && (($source[$at + 1] ?? '') !== '^' || $close === $at + 2) && str_contains('[(', $source[$close + 1] ?? "\0")) {
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
            if (substr($source, $i, 2) === '[^' && ($definition !== null || $mask[$i] === '[') && $end !== null && $end > $i + 2) {
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
                    $output .= ': ' . $emptyNote;
                    $i++;
                }
                if ($definition !== null && ($source[$i] ?? '') === ':' && ($source[$i + 1] ?? '') === "\t") {
                    $output .= ': ';
                    $i += 2;
                }
                if ($definition === null && ($source[$i] ?? '') === ':' && isset($lineHeads[$at])) {
                    $output .= '\\:';
                    $i++;
                }

                continue;
            }
            if ($source[$i] === '!' && !$this->isDjotEscaped($source, $i) && substr($source, $i + 1, 2) === '[^' && ($brackets[$i + 1] ?? 0) > $i + 3 && ($mask[$i + 1] ?? '') === '[') {
                $output .= '\\';
            }
            $output .= $source[$i++];
        }
        $stubs = [];
        foreach ($used as $key => $_) {
            if (!isset($defined[$key])) {
                $stubs[] = '[^' . $alias((string)$key) . ']: ' . $emptyNote;
            }
        }

        return ($stubs !== [] ? implode("\n\n", $stubs) . "\n\n" : '') . $output;
    }

    private function normalizeDjotFences(string $source): string
    {
        $quoteCode = $this->hasDjotCodeLayoutCandidates($source);
        if (!str_contains($source, '```') && !str_contains($source, '~~~')) {
            return str_contains($source, '\\|') || $quoteCode ? $this->closeDjotTableCode($source) : $source;
        }
        $lines = explode("\n", $source);
        $rows = $this->djotTableRows($source, $this->maskCodeAndDestinations($source, false));
        $this->maskCodeAndDestinations($source, false, static function (int $line, string $replacement) use (&$lines): void {
            $lines[$line] = $replacement;
        }, $rows);

        $normalized = $this->normalizeDjotParagraphFences(implode("\n", $lines));

        return $quoteCode ? $this->closeDjotTableCode($normalized) : $normalized;
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
            // carve#2854: a square bracket is not encoded. A Carve destination holds it
            // literally, balanced or not, so encoding it would change the href against a
            // body the angle form promises to show verbatim.
            $encoding = ['`' => '%60', '|' => '%7C', '\\' => '\\\\', '(' => '%28', ')' => '%29'];
            $target = strtr($target, $encoding);
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
            if (($line[$at] ?? '') === '>' && ($at + 1 === $length || ($line[$at + 1] ?? '') === ' ' || ($line[$at + 1] ?? '') === "\t")) {
                $at++;

                continue;
            }
            if (preg_match('/\G(?:\[\^[^\]\n]+\]:(?:[ \t]+|[\r\n]*$)|(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})\)|:)[ \t]+)/', $line, $marker, offset: $at) !== 1) {
                break;
            }
            $at += strlen($marker[0]);
            if (in_array($marker[0][0], ['-', '*', '+'], true) && preg_match('/\G\[[ xX]\][ \t]+/', $line, $task, offset: $at) === 1) {
                $at += strlen($task[0]);
            }
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

    private function djotItemPrefix(string $line, int $at): string
    {
        return preg_replace('/\[\^[^\]\n]+\]:[ \t]*/', '', substr($line, 0, $at)) ?? substr($line, 0, $at);
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
        $noteContainerColumn = null;
        $noteContainerDepth = 0;
        $noteItemColumn = null;
        $noteItemDepth = 0;
        $noteItemPrefix = 0;
        $rows = [];
        foreach (explode("\n", $source) as $line) {
            $at = $this->djotContentStart($line);
            $end = strlen(rtrim($line)) - 1;
            $content = substr($line, $at);
            $itemPrefix = substr($line, 0, $at);
            $noteAt = strpos($itemPrefix, '[^');
            $opensItem = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', $noteAt === false ? $itemPrefix : substr($itemPrefix, 0, $noteAt)) === 1;
            $closesNote = $footnoteColumn !== null && $at < $footnoteColumn;
            if ($closesNote) {
                $footnoteColumn = $noteContainerColumn = null;
            }
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $line, $physicalQuote);
            $physicalPrefix = $physicalQuote[0] ?? '';
            $physicalDepth = substr_count($physicalPrefix, '>');
            $closesContainer = $noteContainerColumn !== null && trim($content) !== '' && ($noteContainerDepth > 0 ? $physicalDepth < $noteContainerDepth : $at <= $noteContainerColumn);
            if ($physicalDepth > $noteItemDepth) {
                preg_match_all('/>/', $physicalPrefix, $positions, PREG_OFFSET_CAPTURE);
                $itemColumn = ($positions[0][$noteItemDepth][1] ?? 0) - $noteItemPrefix;
            } else {
                $itemColumn = strspn(substr($line, strlen($physicalPrefix)), " \t") + strlen($physicalPrefix) - $noteItemPrefix;
            }
            $closesNoteItem = $noteItemColumn !== null && str_starts_with($content, '|') && ($physicalDepth < $noteItemDepth || $itemColumn <= $noteItemColumn);
            if ($closesNoteItem || $closesNote) {
                $noteItemColumn = null;
            }
            if ($closesContainer) {
                $noteContainerColumn = null;
            }
            $allowed = $previousBlock || $previousRow || $opensItem || $closesNote || $closesContainer || $closesNoteItem;
            if ($allowed && $noteAt !== false && ($mask[$offset + $noteAt] ?? '') === '[' && preg_match('/^\[\^[^\]\n]+\]:/', substr($line, $noteAt), $noteHead) === 1) {
                $note = substr($line, 0, $noteAt + strlen($noteHead[0]));
                $notePrefix = substr($line, 0, $noteAt);
                $noteDepth = substr_count($notePrefix, '>');
                $footnoteColumn = $noteAt + 1;
                $after = substr($line, strlen($note));
                $padding = strspn($after, " \t");
                $body = substr($after, $padding);
                $firstItem = preg_match('/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+/', $body) === 1;
                $noteItemColumn = $firstItem ? intdiv(strlen(mb_convert_encoding($note, 'UTF-16LE', 'UTF-8')), 2) + $padding - strlen($notePrefix) : null;
                $noteItemDepth = $noteDepth;
                $noteItemPrefix = strlen($notePrefix);
                if ($firstItem || preg_match('/^>(?:[ \t]|$)/', $body) === 1) {
                    $noteContainerColumn = strlen($note) + $padding;
                    $inlinePrefix = preg_replace('/\[\^[^\]\n]+\]:[ \t]*/', '', substr($body, 0, $this->djotContentStart($body))) ?? '';
                    $inlineDepth = substr_count($inlinePrefix, '>');
                    $noteContainerDepth = $inlineDepth > 0 ? $noteDepth + $inlineDepth : 0;
                }
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
            $opensItem = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', $this->djotItemPrefix($line, $at)) === 1;
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

    /**
     * @param string $source
     * @param callable(int): void|null $onRawCode
     */
    private function closeDjotTableCode(string $source, ?callable $onRawCode = null): string
    {
        if (!str_contains($source, '`')) {
            return $source;
        }
        $blockAttributes = function (string $value): bool {
            $attrs = str_starts_with($value, '{') ? $this->readDjotWordAttributes($value, 0) : null;

            return $attrs !== null && trim(substr($value, $attrs['end']), " \t") === '';
        };
        $mask = $this->maskDjotOpaque($this->maskDjotFences($source), false, ['code' => false]);
        $length = strlen($source);
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
        $block = '/^(?:[-*+] |(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)] |\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\) |: |#{1,6} |`{3,}|~{3,}|:{3,}(?:[ \t]*[A-Za-z0-9_-]+)?[ \t]*$|>(?:[ \t]|$)|\||\^ |\[[^\]]+\]:)/';
        $marker = '/^(?:\[\^[^\]\n]+\]:[ \t]*|(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)|:)[ \t]+)/';
        $prefixMarker = '/\G' . substr($marker, 2);
        $itemKindOf = static fn (string $item): string => trim(preg_replace(['/^[0-9]+/', '/^[A-Za-z]+/'], ['1', 'a'], $item) ?? $item);
        $imageParagraphValid = str_contains($source, '![') ? $this->djotImageBoundaryIndex($source, $mask) : null;
        $codeHostDepth = 0;
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
            if (preg_match('/[-*+][ \t]+$/', substr($line, 0, $at)) === 1 && preg_match('/\G\[[ xX]\][ \t]+/', $line, $taskPrefix, offset: $at) === 1) {
                $at += strlen($taskPrefix[0]);
            }
            $lineLength = strlen($line);
            $lineEnd = $offset + $lineLength;
            $codeDepth = 0;
            $prefixAt = 0;
            while ($prefixAt < $lineLength) {
                $prefixAt += strspn($line, " \t", $prefixAt);
                if (preg_match('/\G>(?:[ \t]|$)/', $line, $quotePrefix, 0, $prefixAt) === 1) {
                    ++$codeDepth;
                    $prefixAt += strlen($quotePrefix[0]);

                    continue;
                }
                if (preg_match($prefixMarker, $line, $itemPrefix, 0, $prefixAt) !== 1) {
                    break;
                }
                $prefixAt += strlen($itemPrefix[0]);
                if (in_array($itemPrefix[0][0], ['-', '*', '+'], true) && preg_match('/\G\[[ xX]\][ \t]+/', $line, $taskPrefix, 0, $prefixAt) === 1) {
                    $prefixAt += strlen($taskPrefix[0]);
                }
            }
            [$parsedDepth, $content] = $this->quoted($line);
            $trimmed = ltrim($content, " \t");
            $indent = strlen($content) - strlen($trimmed);
            $item = preg_match($marker, $trimmed, $itemMatch) === 1;
            if ($item && str_starts_with($itemMatch[0], '[^') && !$previousBlock && $parsedDepth >= $previousQuote && !($itemColumn > 0 && $parsedDepth === $itemQuote && $indent < $itemColumn)) {
                $item = false;
            }
            $oldColumn = $itemColumn;
            $oldQuote = $itemQuote;
            $beganInside = $offset < $consumed;
            $depth = $beganInside ? $codeHostDepth : $parsedDepth;
            if ($beganInside) {
                $codeDepth = $codeHostDepth;
            } elseif (!$previousBlock && $parsedDepth < $previousQuote && $trimmed !== '' && preg_match($block, $trimmed) !== 1) {
                $codeDepth = max($codeDepth, $previousQuote);
            }
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
                    $itemColumn = $indent + 1;
                    $itemQuote = $depth;
                    $itemKind = $itemKindOf($itemMatch[0]);
                } elseif ($trimmed !== '' && $itemColumn > 0) {
                    $ownerAt = 0;
                    for ($q = 0; $q < $itemQuote; $q++) {
                        if (preg_match('/\G[ \t]*>(?:[ \t]|$)/', $line, $ownerPrefix, 0, $ownerAt) !== 1) {
                            break;
                        }
                        $ownerAt += strlen($ownerPrefix[0]);
                    }
                    $ownerIndent = strspn($line, " \t", $ownerAt);
                    $ownerText = substr($line, $ownerAt + $ownerIndent);
                    if ($depth < $itemQuote || ($ownerIndent < $itemColumn && ($previousBlock || preg_match($block, $ownerText) === 1 || $blockAttributes($ownerText)))) {
                        $itemColumn = 0;
                        $itemKind = '';
                    }
                }
                if (preg_match('/^(:{3,})(?:[ \t]+\S.*)?[ \t]*$/', substr($line, $at), $div) === 1) {
                    $owner = $divs !== [] ? end($divs) : null;
                    if ($owner !== null && $depth === $owner['depth'] && preg_match('/^:{3,}[ \t]*$/', substr($line, $at)) === 1 && strlen($div[1]) >= $owner['width']) {
                        array_pop($divs);
                    } elseif ($mask[$offset + $at] !== ' ') {
                        $divs[] = ['width' => strlen($div[1]), 'depth' => $depth, 'column' => $at, 'at' => $offset];
                    }
                }
                if (($line[$at] ?? '') === '|' && (($oldColumn > 0 && $depth <= $oldQuote && $indent < $oldColumn) || $depth < $previousQuote)) {
                    $output[] = substr($source, $cursor, $offset - $cursor);
                    preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $line, $hostQuote);
                    $output[] = rtrim($hostQuote[0] ?? '') . "\n";
                    $cursor = $offset;
                }
                $previousQuote = max($depth, $codeDepth);
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
                    if ($labels[$i + 1] >= $lineEnd && in_array($source[$labels[$i + 1] + 1] ?? '', ['(', '['], true) && $imageParagraphValid !== null && $imageParagraphValid($i, $labels[$i + 1], $codeDepth, $itemColumn, $itemQuote, $divs === [] ? null : $divs[array_key_last($divs)]['at'])) {
                        $consumed = $labels[$i + 1] + 1;
                        $codeHostDepth = $codeDepth;
                    }
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
                $codeHostDepth = $codeDepth;
                $candidate = $closeRun($i, $width);
                if ($candidate + $width <= $lineEnd) {
                    $i = $candidate + $width;
                    $consumed = $i;

                    continue;
                }
                $limit = min($candidate, $length);
                $separate = false;
                $separateDepth = 0;
                for ($next = $lineEnd + 1; $next < $limit;) {
                    $newline = strpos($source, "\n", $next);
                    $end = $newline === false ? $length : $newline;
                    $following = substr($source, $next, $end - $next);
                    [$nextDepth, $nextContent] = $this->quoted($following);
                    $nextTrimmed = ltrim($nextContent, " \t");
                    $ownerAt = 0;
                    for ($q = 0; $q < $itemQuote; ++$q) {
                        if (preg_match('/\G[ \t]*>(?:[ \t]|$)/', $following, $ownerPrefix, 0, $ownerAt) !== 1) {
                            break;
                        }
                        $ownerAt += strlen($ownerPrefix[0]);
                    }
                    $ownerContent = substr($following, $ownerAt);
                    $ownerIndent = strspn($following, " \t", $ownerAt);
                    $outside = ($codeDepth > 0 && $nextDepth < $codeDepth) || ($itemColumn > 0 && $ownerIndent < $itemColumn);
                    if (($nextDepth <= $codeDepth || $outside) && trim($nextContent, " \t") === '') {
                        $limit = $next - 1;

                        break;
                    }
                    $nextIndent = strlen($nextContent) - strlen($nextTrimmed);
                    $owner = $divs !== [] ? end($divs) : null;
                    $closesDiv = $owner !== null && $nextDepth === $owner['depth'] && $nextIndent <= $owner['column'] + 3 && preg_match('/^:{3,}[ \t]*$/', $nextTrimmed) === 1 && strlen(trim($nextTrimmed)) >= $owner['width'];
                    $attributes = $blockAttributes($nextTrimmed);
                    if ($closesDiv || ($outside && (preg_match($block, $nextTrimmed) === 1 || ($itemColumn > 0 && $ownerIndent < $itemColumn && preg_match($block, ltrim($ownerContent, " \t")) === 1) || $attributes))) {
                        $limit = $next - 1;
                        $nextItem = preg_match($marker, $nextTrimmed, $nextItemMatch) === 1 ? $itemKindOf($nextItemMatch[0]) : null;
                        $separate = $outside && ($nextDepth < $depth || $nextItem !== $itemKind);
                        $separateDepth = min($depth, $nextDepth);

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
                if (!$closed) {
                    $payload = preg_replace('/\n$/', '', $payload) ?? $payload;
                }
                $payload = preg_replace('/^ `|` $/', '`', $payload) ?? $payload;
                $normalized = [];
                foreach (explode("\n", $payload) as $n => $value) {
                    if ($n === 0) {
                        $normalized[] = $value;

                        continue;
                    }
                    $valueOffset = 0;
                    for ($q = 0; $q < $codeDepth; $q++) {
                        if (preg_match('/\G[ \t]*>(?:[ \t]|$)/', $value, $prefixMatch, 0, $valueOffset) !== 1) {
                            break;
                        }
                        $valueOffset += strlen($prefixMatch[0]);
                    }
                    $normalized[] = ltrim(substr($value, $valueOffset), " \t");
                }
                $rawCode = $itemColumn > 0 && str_starts_with($itemKind, '[^') && count($normalized) > 1;
                foreach (array_slice($normalized, 1) as $value) {
                    if ((preg_match($block, $value) === 1 || str_starts_with($value, ':::') || str_starts_with($value, '{')) && (preg_match('/^\|[ \t:|-]*\|$/', $value) !== 1 || ($line[$at] ?? '') !== '|')) {
                        $rawCode = true;

                        break;
                    }
                }
                if ($codeDepth > 0 && !$rawCode) {
                    $payload = implode("\n", $normalized);
                }
                if ($rawCode) {
                    if ($onRawCode !== null) {
                        $onRawCode($i);
                    }
                    $payload = '<code>' . (preg_replace_callback('/[!-\/:-@\[-`{-~]|\n/', static fn (array $match): string => '&#' . ord($match[0]) . ';', implode("\n", $normalized)) ?? '') . '</code>';
                }
                preg_match_all('/`+/', $payload, $payloadRuns);
                $fenceWidth = 1;
                foreach ($payloadRuns[0] as $run) {
                    $fenceWidth = max($fenceWidth, strlen($run) + 1);
                }
                $fence = str_repeat('`', $fenceWidth);
                $pad = str_starts_with($payload, '`') || str_ends_with($payload, '`') ? ' ' : '';
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
                    $output[] = "\n" . rtrim(str_repeat('> ', $separateDepth));
                }
                $i = $consumed;
            }
            $previousBlock = !$beganInside && $consumed <= $lineEnd && ($trimmed === '' || preg_match('/^(?:#{1,6} |:{3,}(?:[ \t]*[A-Za-z0-9_-]+)?[ \t]*$|\[[^\]]+\]:)/', $trimmed) === 1 || $blockAttributes($trimmed) || (($line[$at] ?? '') === '|' && str_ends_with(rtrim($line), '|') && !str_ends_with(rtrim($line), '\\|')));
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
            if (($mask[$offsets[$n] + $at] ?? '') !== '[' || preg_match('/^\[([^\[\]\n^][^\[\]\n]*)\]:[ \t]*+([^ \t\r\n]*+)\r?$/', substr($line, $at), $definition) !== 1 || !str_contains($definition[1], '|')) {
                continue;
            }
            $previousLine = $lines[$n - 1] ?? '';
            $previousAt = $this->djotContentStart($previousLine);
            $previous = trim(substr($previousLine, $previousAt));
            $opensItem = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', $this->djotItemPrefix($line, $at)) === 1;
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
