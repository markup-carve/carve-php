<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Block;

use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;

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
                '/^([-*]|\.|[0-9]+[.)]|[a-zA-Z]+[.)])(\{(?:[^{}"\']|"[^"]*"|\'[^\']*\')*\})( +\S.*)$/',
                $line,
                $am,
            )
        ) {
            $body = substr($am[2], 1, -1);
            $parsed = AttributeParser::parseOrdered($body);
            // Valid only if it yields >= 1 attribute or is the empty block `{}`
            // (mirrors the inline-span disambiguation, grammar §14). Otherwise
            // `-{...}` is not a marker and the line stays ordinary text.
            if ($parsed !== [] || $body === '') {
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
        // Task list. PART 9 enumerates the states EXHAUSTIVELY:
        //
        //   task_state = ' ' | 'x' | 'X' | '-' | '_' | '>' | '?' ;
        //
        // `x`/`X` render a CHECKED checkbox, the other five an UNCHECKED one.
        // This accepted ANY single character, so `- [d] u` became a checkbox
        // and the `[d]` was DELETED from the output -- a bracketed one-character
        // tag at the head of an item silently lost its text and gained a
        // checkbox nobody wrote (carve-php#657). Anything outside the
        // enumeration is literal text, which is what carve-js does.
        if (preg_match('/^([' . $this->bulletMarkerClass . ']) +\[([ xX\-_>?])\] +(\S.*)$/', $line, $matches)) {
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
        if (preg_match('/^([' . $this->bulletMarkerClass . ']) +(\S.*)$/', $line, $matches)) {
            $marker = $matches[1];
            $content = $matches[2];

            return [
                'type' => ListBlock::TYPE_BULLET,
                'marker' => $marker,
                'content' => $content,
            ];
        }

        // Ordered list: 1. or 1)
        if (preg_match('/^(\d+)([.)]) +(\S.*)$/', $line, $matches)) {
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
        if (preg_match('/^\. +(\S.*)$/', $line, $matches)) {
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
        if (preg_match('/^([ivxlcdmIVXLCDM]+)([.)]) +(\S.*)$/', $line, $matches)) {
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
        if (preg_match('/^([a-zA-Z])([.)]) +(\S.*)$/', $line, $matches)) {
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
            if (trim($line) === '') {
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
