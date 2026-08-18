<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Block;

use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Parser for list blocks (bullet, ordered, task lists).
 *
 * This class handles parsing of:
 * - Bullet lists (-, *, +)
 * - Ordered lists (1., 1), roman numerals, alphabetical)
 * - Task lists (- [ ], - [x])
 *
 * Definition lists are handled by DefinitionListParser.
 */
class ListParser
{
    /**
     * Roman numeral values for conversion
     *
     * @var array<string, int>
     */
    protected const ROMAN_VALUES = [
        'I' => 1,
        'V' => 5,
        'X' => 10,
        'L' => 50,
        'C' => 100,
        'D' => 500,
        'M' => 1000,
    ];

    /**
     * Characters used in roman numerals (lowercase)
     *
     * @var string
     */
    protected const ROMAN_CHARS = 'ivxlcdm';

    /**
     * Regex character-class fragment of the recognized bullet markers.
     *
     * Carve drops `+` as a bullet by default (it is reserved as the
     * list-continuation marker). The PlusBulletExtension re-enables it via
     * allowPlusBullet().
     *
     * @var string
     */
    protected string $bulletMarkerClass = '-*';

    /**
     * Memoized marker heads, invalidated when the bullet class changes.
     *
     * @var array<string, string>|null
     */
    protected ?array $markerHeads = null;

    /**
     * Memoized head split points, invalidated with the heads.
     *
     * @var array<string, array{0: string, 1: string}>|null
     */
    protected ?array $markerTokens = null;

    /**
     * Memoized content-capturing patterns, invalidated with the heads.
     *
     * @var array<string, string>|null
     */
    protected ?array $capturePatterns = null;

    /**
     * Memoized offset-only patterns, invalidated with the heads.
     *
     * @var array<string, string>|null
     */
    protected ?array $offsetPatterns = null;

    /**
     * Memoized offset-only patterns for the abutting-attribute spelling.
     *
     * @var array<string, string>|null
     */
    protected ?array $attributedOffsetPatterns = null;

    /**
     * Allow (or disallow) `+` as a bullet marker alongside `-` and `*`.
     *
     * A `+` is only ever a bullet when followed by a space and non-empty
     * content; a content-less `+` stays the list-continuation marker, so the
     * two never collide.
     *
     * @param bool $enable
     *
     * @return void
     */
    public function allowPlusBullet(bool $enable = true): void
    {
        $this->bulletMarkerClass = $enable ? '-*+' : '-*';
        $this->markerHeads = null;
        $this->markerTokens = null;
        $this->capturePatterns = null;
        $this->offsetPatterns = null;
        $this->attributedOffsetPatterns = null;
    }

    /**
     * The marker grammar, spelled ONCE, as the regex up to the item's CONTENT.
     *
     * Appending a CAPTURE of the tail gives the patterns
     * `parseListItemMarkerBase()` matches; appending the same tail as a
     * LOOKAHEAD gives the ones `markerContentOffset()` matches. Two renderings
     * of one spelling, because a second spelling of this grammar in a hot path
     * is how a wrong content offset would silently change the way ordinary
     * documents parse - and this repo already carries two spellings of the
     * marker prefix that do not agree with each other.
     *
     * Ordered as PART 9 tries them, roman before alpha, because a roman
     * numeral that fails `romanToInt()` falls THROUGH to the alpha branch.
     *
     * @return array<string, string>
     */
    protected function markerHeads(): array
    {
        if ($this->markerHeads !== null) {
            return $this->markerHeads;
        }

        $heads = [];
        foreach ($this->markerTokens() as $name => [$token, $rest]) {
            $heads[$name] = $token . $rest;
        }

        return $this->markerHeads = $heads;
    }

    /**
     * Each head SPLIT at the point an abutting attribute block sits.
     *
     * `-{.k} x` is the marker, then the block, then the ordinary gap and
     * content - so the block goes between the marker TOKEN and whatever else
     * the head needs. For a task marker that is the bullet and then
     * ` [x] `, which is why the split is a pair rather than a prefix length:
     * `-{.k} [x] y` is the attributed spelling and `- [x]{.k} y` is not.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    protected function markerTokens(?string $bulletClass = null): array
    {
        if ($bulletClass === null && $this->markerTokens !== null) {
            return $this->markerTokens;
        }

        $gap = ' +[ \t]*';
        $bullet = '[' . ($bulletClass ?? $this->bulletMarkerClass) . ']';

        $tokens = [
            'task' => ['(' . $bullet . ')', ' +\[([ xX_>?-])\]' . $gap],
            'bullet' => ['(' . $bullet . ')', $gap],
            'ordered' => ['(\d+)([.)])', $gap],
            'bare' => ['\.', $gap],
            'roman' => ['(?P<roman>[ivxlcdmIVXLCDM]+)([.)])', $gap],
            'alpha' => ['([a-zA-Z])([.)])', $gap],
        ];

        if ($bulletClass !== null) {
            return $tokens;
        }

        return $this->markerTokens = $tokens;
    }

    /**
     * Where the INNERMOST marker's content begins on a line of nested markers.
     *
     * The question `BlockParser::advanceTrailingBlockState()` asks of every
     * marker line: `- - - x` has its content at the `x`. Walked as offsets, so
     * a line of N markers copies nothing rather than N times.
     *
     * @param string $line A single collected line.
     */
    public function innermostMarkerContentOffset(string $line): ?int
    {
        // AN INTERIOR NEWLINE IS THE ONE SHAPE THE FAST FORM MISREADS, and it
        // is screened ONCE for the whole walk instead of once per marker - the
        // per-call spelling is exactly the O(rest) scan this walk exists to
        // remove. `parseListItemMarker()` answers null for such a subject,
        // because its `(NON_WHITESPACE.*)$` tail cannot cross a newline, so
        // null is the agreeing answer and not a refusal.
        $newline = strpos($line, "\n");
        if ($newline !== false && $newline !== strlen($line) - 1) {
            return null;
        }

        return $this->markerWalkOffset($line, 0);
    }

    /**
     * The same walk with the interior-newline screen ALREADY ANSWERED.
     *
     * {@see self::innermostMarkerContentOffset()} is this plus the screen, so
     * the walk itself is spelled once. A caller crossing a container prefix
     * asks the screen once for the whole line and then walks from an offset:
     * asked per level, the screen is an O(rest) scan that puts back exactly the
     * cost the offset walk removes (markup-carve/carve-php#1437).
     *
     * @param string $line A single line, already screened for an INTERIOR
     *   newline at or after `$from`.
     * @param int $from Byte offset to walk from, anchored.
     */
    public function markerWalkOffset(string $line, int $from = 0): ?int
    {
        $offset = $this->markerContentOffset($line, $from);
        if ($offset === null) {
            return null;
        }

        while (($next = $this->markerContentOffset($line, $offset)) !== null) {
            $offset = $next;
        }

        return $offset;
    }

    /**
     * One marker head, rendered as the pattern that CAPTURES the content.
     *
     * Memoized whole rather than composed per call: this is the hottest
     * function in the parser on a marker-heavy line, and building six pattern
     * strings per call cost more than the copy the offset walk removes.
     */
    protected function markerPattern(string $name): string
    {
        return $this->capturePatterns()[$name];
    }

    /**
     * @return array<string, string>
     */
    protected function capturePatterns(): array
    {
        if ($this->capturePatterns !== null) {
            return $this->capturePatterns;
        }

        $patterns = [];
        foreach ($this->markerHeads() as $name => $head) {
            $patterns[$name] = '/^' . $head . '(' . StringUtil::NON_WHITESPACE_CLASS . '.*)$/';
        }

        return $this->capturePatterns = $patterns;
    }

    /**
     * The offset patterns for the ABUTTING-ATTRIBUTE spelling, `-{.k} x`.
     *
     * The block goes between the marker token and the rest of its head, which
     * is exactly where `parseListItemMarker()` strips it from before handing
     * the line to the base parser. Built from the SAME split, so the two forms
     * cannot drift apart - and the payload is still validated by
     * `AttributeParser`, so `-{bad ..}` is no more a marker here than it is
     * there.
     *
     * Without these, this spelling kept the string path and stayed quadratic:
     * 2000 abutting markers cost 6.7s where every other spelling had gone
     * linear, and it was the most expensive shape measured.
     *
     * @return array<string, string>
     */
    protected function attributedOffsetPatterns(): array
    {
        if ($this->attributedOffsetPatterns !== null) {
            return $this->attributedOffsetPatterns;
        }

        $block = '(?P<attrs>\{(?:[^{}"\']|"[^"]*"|\'[^\']*\')*\})';
        // THE STRIP'S OWN TAIL, asserted where it sits. `parseListItemMarker()`
        // only strips a block that is followed by ` +NON_WHITESPACE`, and then
        // hands the base parser the marker spliced onto that tail - so a TAB
        // after the spaces means no strip at all, where the head's own
        // ` +[ \t]*` gap would have accepted one. Without this the two forms
        // disagreed on `-{.k} <tab>x`, which a 44,100-document sweep caught and
        // the single-marker matrix did not.
        $strippable = '(?= +' . StringUtil::NON_WHITESPACE_CLASS . ')';
        // THE STRIP'S OWN BULLET CLASS, WHICH IS NARROWER. The attribute
        // pre-step in `parseListItemMarker()` spells its markers as
        // `[-*]|\.|[0-9]+[.)]|[a-zA-Z]+[.)]` - a literal `[-*]`, which the
        // PlusBulletExtension does not widen. So `+{.k} x` is not a marker
        // there even with the extension on, and building these from the live
        // bullet class made the two forms disagree exactly there (raised by
        // codex review). Mirrored rather than corrected: whether the strip
        // SHOULD accept a plus bullet is a behavior question, and this change
        // is required to alter nothing.
        $patterns = [];
        foreach ($this->markerTokens('-*') as $name => [$token, $rest]) {
            $patterns[$name] = '/' . $token . $block . $strippable . $rest
                . '(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/A';
        }

        return $this->attributedOffsetPatterns = $patterns;
    }

    /**
     * The same heads with the tail as a zero-width LOOKAHEAD, anchored.
     *
     * @return array<string, string>
     */
    protected function offsetPatterns(): array
    {
        if ($this->offsetPatterns !== null) {
            return $this->offsetPatterns;
        }

        $patterns = [];
        foreach ($this->markerHeads() as $name => $head) {
            $patterns[$name] = '/' . $head . '(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/A';
        }

        return $this->offsetPatterns = $patterns;
    }

    /**
     * Where the CONTENT of the marker at `$from` begins, or null for no marker.
     *
     * The answer `parseListItemMarker()` gives, minus the copy. Every pattern
     * there ends by CAPTURING the rest of the line, so asking the same question
     * N times down a line of N markers copies the tail N times - the walk in
     * `BlockParser::advanceTrailingBlockState()` did exactly that, and 8 KB of
     * markers cost about three seconds with the ratio per doubling still
     * climbing (carve-php#1426, and PART 9 section 25 is normative about
     * refusing rather than degrading).
     *
     * The tail is asserted with a zero-width LOOKAHEAD written from the same
     * heads, and the only substring this returns is bounded by the MARKER
     * rather than by the line.
     *
     * THE LOOKAHEAD DELIBERATELY DROPS THE `.*$`. The capturing form ends
     * `(NON_WHITESPACE.*)$`, and asserting that tail again per call is what
     * made the first version of this SLOWER than the copy it replaced: the
     * scan to end-of-line is O(rest) whether or not anything is copied, so
     * the walk stayed quadratic with a bigger constant. Dropping it is exact
     * for a line with no INTERIOR newline, which is the whole difference the
     * `$` makes, and `innermostMarkerContentOffset()` checks that once for the
     * whole walk rather than once per marker.
     *
     * @param string $line A single line. A newline anywhere but the last byte
     *   makes this DISAGREE with `parseListItemMarker()`, which is why the walk
     *   below screens for one.
     * @param int $from Byte offset to match at, anchored.
     */
    public function markerContentOffset(string $line, int $from = 0): ?int
    {
        foreach ($this->offsetPatterns() as $name => $pattern) {
            if (preg_match($pattern, $line, $m, 0, $from) !== 1) {
                continue;
            }

            if ($name === 'roman' && $this->romanToInt(strtoupper($m[1])) <= 0) {
                // Not a roman numeral after all, so the alpha head gets its
                // turn - the same fall-through the base parser makes.
                //
                // CURRENTLY UNREACHABLE, and kept for the coupling rather than
                // for the case. `romanToInt()` returns 0 only for a character
                // outside `ROMAN_VALUES`, and the roman head's class is exactly
                // that table's keys in both cases, so nothing the head matches
                // can fail it. It is the base parser's own guard mirrored, and
                // it is what keeps the two tables coupled if either is widened
                // - deleting it would leave a widened class producing a list
                // that starts at zero.
                continue;
            }

            return $from + strlen($m[0]);
        }

        // AN ABUTTING ATTRIBUTE BLOCK, tried second because the two spellings
        // are disjoint: a plain head needs whitespace where this one has a
        // brace, so neither can match what the other does.
        foreach ($this->attributedOffsetPatterns() as $name => $pattern) {
            if (preg_match($pattern, $line, $m, 0, $from) !== 1) {
                continue;
            }

            if ($name === 'roman' && $this->romanToInt(strtoupper($m['roman'])) <= 0) {
                continue;
            }

            $body = substr($m['attrs'], 1, -1);
            // The WHOLE payload has to be valid, exactly as it does in
            // `parseListItemMarker()`: a block mixing a good class with an
            // unrecognized name is not a marker at all.
            if ($body !== '' && (AttributeParser::parseOrdered($body) === [] || !AttributeParser::isValidInlinePayload($body))) {
                continue;
            }

            return $from + strlen($m[0]);
        }

        return null;
    }

    /**
     * Parse a list item marker from a line.
     *
     * @param string $line The line to parse
     *
     * @return array{type: string, marker: string, content: string, start?: int, checked?: bool, taskMarker?: string, style?: string, marker_indent?: int, ambiguous?: bool, alpha_start?: int, alpha_style?: string, bareMarker?: bool, attributes?: array<string, string>}|null
     */
    public function parseListItemMarker(string $line): ?array
    {
        // A `{...}` attribute block ABUTTING the marker (no space before `{`)
        // attaches its attributes to the <li> (Carve addition, grammar
        // `item_attributes`). Strip a valid block so the bare marker patterns
        // match, and attach the parsed attributes to the returned info. A space
        // before the brace does NOT match here -- it stays ordinary content.
        $itemAttributes = [];
        if (
            preg_match(
                '/^([-*]|\.|[0-9]+[.)]|[a-zA-Z]+[.)])(\{(?:[^{}"\']|"[^"]*"|\'[^\']*\')*\})( +' . StringUtil::NON_WHITESPACE_CLASS . '.*)$/',
                $line,
                $am,
            )
        ) {
            $body = substr($am[2], 1, -1);
            $parsed = AttributeParser::parseOrdered($body);
            // Valid only if it yields >= 1 attribute or is the empty block `{}`
            // (mirrors the inline-span disambiguation, grammar §14). Otherwise
            // `-{...}` is not a marker and the line stays ordinary text.
            //
            // The WHOLE payload has to be valid, not merely yield something:
            // `parseOrdered()` reports the valid tokens it finds, so a block
            // mixing a good class with an unrecognized name (`{.ok xml:lang=en}`,
            // `{.ok .1}`) used to open a list carrying the good half, where
            // carve-js and carve-rs leave the line a paragraph.
            if (($parsed !== [] && AttributeParser::isValidInlinePayload($body)) || $body === '') {
                $itemAttributes = $parsed;
                $line = $am[1] . $am[3];
            }
        }

        $info = $this->parseListItemMarkerBase($line);
        if ($info !== null && $itemAttributes !== []) {
            $info['attributes'] = $itemAttributes;
        }

        return $info;
    }

    /**
     * Parse a list item marker from a line, without the abutting-attribute
     * handling (see parseListItemMarker).
     *
     * @param string $line The line to parse
     *
     * @return array{type: string, marker: string, content: string, start?: int, checked?: bool, taskMarker?: string, style?: string, marker_indent?: int, ambiguous?: bool, alpha_start?: int, alpha_style?: string, bareMarker?: bool}|null
     */
    private function parseListItemMarkerBase(string $line): ?array
    {
        // Task list. PART 9 enumerates the states exhaustively:
        //
        //   task_state = ' ' | 'x' | 'X' | '-' | '_' | '>' | '?' ;
        //
        // `x`/`X` are CHECKED, the other five UNCHECKED. Anything else is not a
        // task marker and the brackets stay literal text.
        //
        // This used to accept any single character, which did not merely
        // reinterpret `- [!] urgent` - it DELETED the `[!]` and rendered a
        // checkbox nobody wrote (carve-php#657). Two characters were already
        // rejected; it was only the one-character case that was open.
        if (preg_match($this->markerPattern('task'), $line, $matches)) {
            $taskMarker = $matches[2];

            return [
                'type' => ListBlock::TYPE_TASK,
                'marker' => $matches[1],
                'content' => $matches[3],
                'checked' => strtolower($taskMarker) === 'x',
                'taskMarker' => $taskMarker,
            ];
        }

        // Bullet list: - or * (and + when the PlusBulletExtension is active).
        // Unlike Markdown/djot, `+` is not a Carve bullet by default -- it is
        // reserved as the list-continuation marker, so a lone `+` is
        // unambiguous and a `+ x` line is ordinary paragraph text.
        // A marker is a list item only with non-empty content: a content-less
        // marker (bare or trailing whitespace only) is paragraph text, not a
        // list. Avoids a trailing space being load-bearing. See PART 9.
        if (preg_match($this->markerPattern('bullet'), $line, $matches)) {
            $marker = $matches[1];
            $content = $matches[2];

            return [
                'type' => ListBlock::TYPE_BULLET,
                'marker' => $marker,
                'content' => $content,
            ];
        }

        // Ordered list: 1. or 1)
        if (preg_match($this->markerPattern('ordered'), $line, $matches)) {
            return [
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => $matches[2],
                'content' => $matches[3],
                'start' => (int)$matches[1],
            ];
        }

        // Bare-dot ordered list: `. text` is shorthand for decimal-dot ordered
        // items starting at 1. Only the dot delimiter has this shorthand, and
        // it still requires a space and non-empty content.
        if (preg_match($this->markerPattern('bare'), $line, $matches)) {
            return [
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => '.',
                'content' => $matches[1],
                'start' => 1,
                'bareMarker' => true,
            ];
        }

        // Parenthesized markers (1) / (a) / (i) are NOT Carve list markers
        // (too easily confused with a prose parenthetical); they stay
        // literal paragraph text. Carve uses the . and ) delimiters only.

        // Roman numeral ordered list
        if (preg_match($this->markerPattern('roman'), $line, $matches)) {
            $roman = $matches[1];
            $isLower = ctype_lower($roman[0]);
            $start = $this->romanToInt(strtoupper($roman));
            if ($start > 0) {
                $result = [
                    'type' => ListBlock::TYPE_ORDERED,
                    'marker' => $matches[2],
                    'content' => $matches[3],
                    'start' => $start,
                    'style' => $isLower ? 'i' : 'I',
                ];
                if (strlen($roman) === 1) {
                    $alphaStart = ord(strtolower($roman)) - ord('a') + 1;
                    $result['ambiguous'] = true;
                    $result['alpha_start'] = $alphaStart;
                    $result['alpha_style'] = $isLower ? 'a' : 'A';
                }

                return $result;
            }
        }

        // Alpha ordered list: a. or A. or a) or A)
        if (preg_match($this->markerPattern('alpha'), $line, $matches)) {
            $letter = $matches[1];
            $isLower = ctype_lower($letter);
            $start = ord(strtolower($letter)) - ord('a') + 1;

            return [
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => $matches[2],
                'content' => $matches[3],
                'start' => $start,
                'style' => $isLower ? 'a' : 'A',
            ];
        }

        // A definition-list term is `::` (double colon), parsed by
        // BlockParser::tryParseDefinitionList. A single-colon `: term` is NOT a
        // carve definition list (grammar definition_term = "::"); it stays
        // ordinary paragraph text, matching carve-js and carve-rs.
        return null;
    }

    /**
     * Disambiguate between roman numeral and alphabetical list styles.
     *
     * For single-letter markers that could be either roman (i, v, x, l, c, d, m)
     * or alphabetical, looks ahead at subsequent items to determine the style.
     *
     * @param array{type: string, marker: string, content: string, start?: int, checked?: bool, taskMarker?: string, style?: string, marker_indent?: int, ambiguous?: bool, alpha_start?: int, alpha_style?: string} $listInfo The parsed list info with ambiguous flag
     * @param array<string> $lines All lines being parsed
     * @param int $start Starting line index
     *
     * @return array{type: string, marker: string, content: string, start?: int, checked?: bool, taskMarker?: string, style?: string, marker_indent?: int, ambiguous?: bool, alpha_start?: int, alpha_style?: string} Updated list info with resolved style
     */
    public function disambiguateListStyle(array $listInfo, array $lines, int $start): array
    {
        $marker = $listInfo['marker'];
        $firstMarkerLetter = null;
        $firstIsLower = null;

        // Extract the letter from the first marker for comparison
        if (preg_match('/^([ivxlcdmIVXLCDM])/', $lines[$start], $m)) {
            $firstMarkerLetter = strtolower($m[1]);
            $firstIsLower = ctype_lower($m[1]);
        }

        $hasMultiCharRoman = false;
        $hasNonRomanLetter = false;
        $allSameLetter = true;
        $lineCount = count($lines);

        // Look ahead at subsequent items
        for ($i = $start + 1; $i < $lineCount; $i++) {
            $line = $lines[$i];

            // Stop at blank lines or non-list content
            if (IndentationHelper::isBlankLine($line)) {
                continue;
            }

            // Check if this line is a list item with the same marker type
            $itemInfo = $this->parseListItemMarker($line);
            if ($itemInfo === null || $itemInfo['marker'] !== $marker) {
                break;
            }

            // Extract the marker text (preserve original case for comparison)
            $markerTextRaw = null;
            if (preg_match('/^([a-zA-Z]+)[.)]/', $line, $m)) {
                $markerTextRaw = $m[1];
            }

            if ($markerTextRaw === null) {
                break;
            }

            // Check if case matches - different case means different list style
            $itemIsLower = ctype_lower($markerTextRaw[0]);
            if ($firstIsLower !== null && $itemIsLower !== $firstIsLower) {
                break;
            }

            $markerText = strtolower($markerTextRaw);

            // Check for multi-character roman numerals
            if (strlen($markerText) > 1 && preg_match('/^[ivxlcdm]+$/', $markerText)) {
                $hasMultiCharRoman = true;

                break;
            }

            // Check if it's a letter not used in roman numerals
            if (strlen($markerText) === 1 && !str_contains(self::ROMAN_CHARS, $markerText)) {
                $hasNonRomanLetter = true;

                break;
            }

            // A single-letter sibling that is the consecutive LETTER of the
            // first marker (c -> d, v -> w) but NOT its consecutive roman
            // numeral means alphabetical (§11). This catches `c.`/`d.`: `d` is a
            // roman char (500) so the non-roman check above misses it, yet it
            // is the next letter after `c`, not the next roman after 100.
            if (strlen($markerText) === 1 && $firstMarkerLetter !== null) {
                $firstRoman = $this->romanToInt(strtoupper($firstMarkerLetter));
                $sibRoman = $this->romanToInt(strtoupper($markerText));
                $firstAlpha = ord($firstMarkerLetter) - ord('a') + 1;
                $sibAlpha = ord($markerText) - ord('a') + 1;
                if ($sibAlpha === $firstAlpha + 1 && $sibRoman !== $firstRoman + 1) {
                    $hasNonRomanLetter = true;

                    break;
                }
            }

            // Check if all letters are the same
            if ($firstMarkerLetter !== null && $markerText !== $firstMarkerLetter) {
                $allSameLetter = false;
            }
        }

        // Decision logic
        if ($hasMultiCharRoman) {
            return $listInfo;
        }

        if ($hasNonRomanLetter) {
            // Both are set together with `ambiguous`, by the only branch that
            // sets it; the defaults keep the shape honest for a caller that
            // hands us an info array without them.
            $listInfo['start'] = $listInfo['alpha_start'] ?? 1;
            $listInfo['style'] = $listInfo['alpha_style'] ?? 'a';
            unset($listInfo['ambiguous'], $listInfo['alpha_start'], $listInfo['alpha_style']);

            return $listInfo;
        }

        return $listInfo;
    }

    /**
     * Convert roman numeral string to integer.
     *
     * @param string $roman Roman numeral string (uppercase)
     *
     * @return int The integer value, or 0 if invalid
     */
    public function romanToInt(string $roman): int
    {
        $result = 0;
        $prev = 0;
        $length = strlen($roman);

        for ($i = $length - 1; $i >= 0; $i--) {
            $char = $roman[$i];
            if (!isset(self::ROMAN_VALUES[$char])) {
                return 0;
            }
            $value = self::ROMAN_VALUES[$char];

            if ($value < $prev) {
                $result -= $value;
            } else {
                $result += $value;
            }
            $prev = $value;
        }

        return $result;
    }

    /**
     * Get the last list item from a list block.
     *
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $list The list block
     *
     * @return \MarkupCarve\Carve\Node\Block\ListItem|null The last item, or null if empty
     */
    public function getLastListItem(ListBlock $list): ?ListItem
    {
        $children = $list->getChildren();
        $count = count($children);
        if ($count === 0) {
            return null;
        }
        $last = $children[$count - 1];

        return $last instanceof ListItem ? $last : null;
    }

    /**
     * Check if list items match (same type, marker, and style).
     *
     * @param array<string, mixed> $listInfo The list info
     * @param array<string, mixed> $itemInfo The item info
     *
     * @return bool True if they match
     */
    public function itemMatchesList(array $listInfo, array $itemInfo): bool
    {
        if ($itemInfo['type'] !== $listInfo['type']) {
            return false;
        }
        if ($itemInfo['marker'] !== $listInfo['marker']) {
            return false;
        }

        $listStyle = $listInfo['style'] ?? null;
        $itemStyle = $itemInfo['style'] ?? null;

        if (($listStyle === null) !== ($itemStyle === null)) {
            return false;
        }

        if ($listStyle === $itemStyle) {
            return true;
        }

        // Handle ambiguous markers (e.g., 'c' could be alpha or roman)
        // If list is alphabetic and item could be alphabetic, continue the list
        if (isset($itemInfo['ambiguous']) && isset($itemInfo['alpha_style'])) {
            if ($listStyle === $itemInfo['alpha_style']) {
                return true;
            }
        }

        return false;
    }
}
