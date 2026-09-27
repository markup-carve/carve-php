<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Shared utility for parsing Carve attribute strings.
 *
 * Handles parsing of attribute syntax: {.class #id key="value" boolean}
 */
class AttributeParser
{
    /**
     * Parse attribute string and return as array
     *
     * Supports:
     * - .class (class shorthand)
     * - #id (id shorthand)
     * - key="double quoted value" (with escape support)
     * - key='single quoted value' (with escape support)
     * - key=unquoted
     * - bareword (boolean attribute)
     * - % comment % or % trailing comment (stripped)
     *
     * @param string $attrStr The attribute string (contents inside {})
     *
     * @return array<string, string|list<string>> Parsed attributes
     */
    public static function parse(string $attrStr): array
    {
        return self::parseOrderedWithSlots($attrStr)['attributes'];
    }

    /**
     * Parse attribute string preserving source order
     *
     * @param string $attrStr The attribute string to parse
     *
     * @return array<string, string|list<string>> Parsed attributes in source order
     */
    public static function parseOrdered(string $attrStr): array
    {
        return self::parseOrderedWithSlots($attrStr)['attributes'];
    }

    /**
     * Parse attribute string preserving source slot order.
     *
     * @return array{attributes: array<string, string|list<string>>, order: list<string>}
     */
    public static function parseOrderedWithSlots(string $attrStr): array
    {
        $attributes = [];
        $order = [];

        // Single-pass regex that matches all token types in source order.
        // Order matters: quoted values and invalid unquoted values must be matched/skipped
        // first to prevent dots/hashes inside them from being matched as .class or #id.
        // Explicit ids/classes admit an ASCII digit first. Keys and booleans
        // keep the narrower identifier grammar.
        $pattern = '/'
            // Group 1,2: key="double quoted value"
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)="([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|'
            // Group 3,4: key='single quoted value'
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)=\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|'
            // Group 5,6: key=unquoted (must end at whitespace/}/end, not invalid chars)
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)=([^ \t\r\n"\'}|\\\\]+)(?=[ \t\r\n]|}|$)|'
            // Skip invalid unquoted values (e.g. key=a|b, 1=v) - consume but don't capture
            . '(?:(?<=[ \t\r\n])|^)[a-zA-Z0-9_:-]+=[^ \t\r\n}]+|'
            // Group 7: .class shorthand
            . '\.([a-zA-Z0-9_][a-zA-Z0-9_-]*+)(?!:)|'
            // Group 8: #id shorthand
            . '#([a-zA-Z0-9_][a-zA-Z0-9_-]*+)(?!:)|'
            // Group 9: boolean attribute (bareword)
            . '(?:^|[ \t\r\n])([a-zA-Z][a-zA-Z0-9_-]*)(?=[ \t\r\n]|}|$)|'
            // Named groups: semantic language shorthand (the tag may be empty)
            . '(?:(?<=[ \t\r\n])|^)(?<lang_sigil>:)(?<lang_tag>[a-zA-Z0-9]{1,8}(?:-[a-zA-Z0-9]{1,8})*)?(?=[ \t\r\n]|$)'
            . '/';

        $matches = [];
        self::safeMatchAll($pattern, $attrStr, $matches);

        foreach ($matches as $match) {
            if (($match[1] ?? '') !== '') {
                // key="double quoted value"
                self::assignKeyValue($attributes, $order, $match[1], self::processEscapes($match[2] ?? ''));
            } elseif (($match[3] ?? '') !== '') {
                // key='single quoted value'
                self::assignKeyValue($attributes, $order, $match[3], self::processEscapes($match[4] ?? ''));
            } elseif (($match[5] ?? '') !== '') {
                // key=unquoted
                self::assignKeyValue($attributes, $order, $match[5], $match[6] ?? '');
            } elseif (($match[7] ?? '') !== '') {
                // .class shorthand - accumulate classes
                self::appendClassValue($attributes, $order, $match[7]);
            } elseif (($match[8] ?? '') !== '') {
                // #id shorthand
                $attributes['id'] = $match[8];
                $order[] = '#id';
            } elseif (($match[9] ?? '') !== '') {
                // boolean attribute
                self::assignKeyValue($attributes, $order, $match[9], '');
            } elseif (($match['lang_sigil'] ?? '') === ':') {
                $attributes['lang'] = $match['lang_tag'] ?? '';
                $order[] = 'lang';
            }
        }

        return ['attributes' => $attributes, 'order' => $order];
    }

    private static function canonicalSlot(string $name): string
    {
        if ($name === 'id') {
            return '#id';
        }

        return $name === 'class' ? '.class' : $name;
    }

    /**
     * A `class` KEY-VALUE IS A SPELLING OF THE CLASS SLOT (`CARVE-P4-007`).
     *
     * `class=VALUE` and `.VALUE` are the same attribute, so the value joins the
     * class slot in source order instead of replacing it: `{.b class=a}` is
     * `class="b a"`, not `class="a"`. The two spellings stay distinct in SOURCE,
     * because `.` reads the `explicit_identifier` a fence word does while a
     * value reaches past it (markup-carve/carve#2435).
     *
     * @param array<string, string|list<string>> $attributes
     * @param list<string> $order
     * @param string $key
     * @param string $value
     */
    private static function assignKeyValue(array &$attributes, array &$order, string $key, string $value): void
    {
        if ($key === 'class') {
            self::appendClassValue($attributes, $order, $value);

            return;
        }

        $attributes[$key] = $value;
        $order[] = self::canonicalSlot($key);
    }

    /**
     * Append to the class slot without de-duplicating (grammar PART 15).
     *
     * Each value stays one entry, including its whitespace and an empty value.
     *
     * @param array<string, string|list<string>> $attributes
     * @param list<string> $order
     * @param string $value
     */
    private static function appendClassValue(array &$attributes, array &$order, string $value): void
    {
        $order[] = '.class';
        $attributes['class'] ??= [];
        if (is_string($attributes['class'])) {
            $attributes['class'] = [$attributes['class']];
        }
        $attributes['class'][] = $value;
    }

    /**
     * Parse attribute string and merge with existing attributes
     *
     * @param array<string, string|list<string>> $existing Existing attributes to merge with
     * @param string $attrStr The attribute string to parse
     *
     * @return array<string, string|list<string>> Merged attributes
     */
    public static function parseAndMerge(array $existing, string $attrStr): array
    {
        $parsed = self::parseOrdered($attrStr);

        // Special handling for class: merge rather than replace
        if (isset($parsed['class']) && isset($existing['class'])) {
            $parsed['class'] = [...(array)$existing['class'], ...(array)$parsed['class']];
        }

        return array_merge($existing, $parsed);
    }

    /**
     * Apply attributes from a string directly to a node
     *
     * Parses all attribute tokens in source order to preserve attribute ordering
     * in the rendered output (matching the reference JS implementation behavior).
     *
     * @param \MarkupCarve\Carve\Node\Node $node The node to apply attributes to
     * @param string $attrStr The attribute string to parse
     */
    public static function applyToNode(Node $node, string $attrStr): void
    {
        $attrStr = str_replace("\0", "\u{00A0}", $attrStr);
        // Single-pass regex that matches all token types in source order.
        // Order matters: quoted values and invalid unquoted values must be matched/skipped
        // first to prevent dots/hashes inside them from being matched as .class or #id.
        // Unquoted values exclude quotes, closing braces, pipes and backslashes.
        // Explicit ids/classes admit an ASCII digit first. Keys do not; the
        // invalid-value skip also prevents numeric keys becoming PHP ints.
        $pattern = '/'
            // Group 1,2: key="double quoted value"
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)="([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|'
            // Group 3,4: key='single quoted value'
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)=\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|'
            // Group 5,6: key=unquoted (must end at whitespace/}/end, not invalid chars)
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)=([^ \t\r\n"\'}|\\\\]+)(?=[ \t\r\n]|}|$)|'
            // Skip invalid unquoted values (e.g. key=a|b, 1=v) - consume but don't capture
            // This prevents .bar from being matched as a class
            . '(?:(?<=[ \t\r\n])|^)[a-zA-Z0-9_:-]+=[^ \t\r\n}]+|'
            // Group 7: .class shorthand
            . '\.([a-zA-Z0-9_][a-zA-Z0-9_-]*+)(?!:)|'
            // Group 8: #id shorthand
            . '#([a-zA-Z0-9_][a-zA-Z0-9_-]*+)(?!:)|'
            // Group 9: boolean attribute (bareword)
            . '(?:^|[ \t\r\n])([a-zA-Z][a-zA-Z0-9_-]*)(?=[ \t\r\n]|}|$)|'
            . '(?:(?<=[ \t\r\n])|^)(?<lang_sigil>:)(?<lang_tag>[a-zA-Z0-9]{1,8}(?:-[a-zA-Z0-9]{1,8})*)?(?=[ \t\r\n]|$)'
            . '/';

        $matches = [];
        self::safeMatchAll($pattern, $attrStr, $matches);

        foreach ($matches as $match) {
            if (($match[1] ?? '') !== '') {
                // key="double quoted value"
                self::setOnNode($node, $match[1], self::processEscapes($match[2] ?? ''));
            } elseif (($match[3] ?? '') !== '') {
                // key='single quoted value'
                self::setOnNode($node, $match[3], self::processEscapes($match[4] ?? ''));
            } elseif (($match[5] ?? '') !== '') {
                // key=unquoted
                self::setOnNode($node, $match[5], $match[6] ?? '');
            } elseif (($match[7] ?? '') !== '') {
                // .class shorthand -- source-order, no de-dup (§15).
                $node->appendClass($match[7]);
            } elseif (($match[8] ?? '') !== '') {
                // #id shorthand
                $node->setAttribute('id', $match[8]);
            } elseif (($match[9] ?? '') !== '') {
                // boolean attribute
                self::setOnNode($node, $match[9], '');
            } elseif (($match['lang_sigil'] ?? '') === ':') {
                $node->setAttribute('lang', $match['lang_tag'] ?? '');
            }
        }
    }

    /**
     * The node half of assignKeyValue(): a `class` key-value joins the class
     * slot rather than replacing it (`CARVE-P4-007`).
     */
    private static function setOnNode(Node $node, string $key, string $value): void
    {
        if ($key !== 'class') {
            $node->setAttribute($key, $value);

            return;
        }

        $node->appendClass($value);
    }

    /**
     * PART 4: THE INLINE INTERIOR IS SPACE-ONLY (markup-carve/carve#906).
     */
    public static function inlineInteriorIsSpaceOnly(string $attrStr): bool
    {
        if (strpbrk($attrStr, "\t\r\n") === false) {
            return true;
        }
        $length = strlen($attrStr);
        $quote = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $attrStr[$i];
            if ($quote !== null) {
                // A backslash escape inside a quoted value takes the next
                // character with it, so a quote it protects does not close.
                if ($char === '\\') {
                    $i++;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            // PART 7's four characters. `ctype_space()` also takes a VERTICAL TAB
            // and a FORM FEED, so `{k=v<VT>w}` was read as TWO attributes where
            // `{k=v!w}` is one (markup-carve/carve#963).
            if ($char !== ' ' && StringUtil::isWhitespaceChar($char)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `isValidPayload()` plus PART 4's space-only interior.
     *
     * FIVE PRODUCTIONS ALIAS THE INLINE BLOCK, not one. The clause is written
     * about "the inline attribute block", and `attributes` is also what
     * `item_attributes`, `row_attributes`, `cell_attributes` and a reference
     * definition's trailing slot resolve to. A tab-bearing block glued to a
     * list marker, to a cell's opening pipe, to a row's closing pipe, or
     * following a reference definition's destination reads the tab as a
     * separator too, and all four are narrowed by this.
     *
     * A SEPARATE METHOD rather than a flag on `isValidPayload()`, so a call
     * site added later has to say which surface it is on: `block_attributes`
     * keeps `whitespace` at all three of its slots, and a fix that narrowed
     * both at once fails on corpus category 273.
     */
    public static function isValidInlinePayload(string $attrStr): bool
    {
        return self::inlineInteriorIsSpaceOnly($attrStr) && self::isValidPayload($attrStr);
    }

    public static function isValidPayload(string $attrStr): bool
    {
        // Quoted key=values first, so dots/braces/% inside quotes are protected.
        $rest = preg_replace(
            '/(?:(?<=[ \t\r\n])|^)[a-zA-Z_][a-zA-Z0-9_-]*=(?:"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')/',
            ' ',
            $attrStr,
        ) ?? $attrStr;
        // See isValidAttrPayload(): PART 7's four characters, not PHP's default
        // trim charlist (markup-carve/carve#963).
        if (trim($rest, StringUtil::WHITESPACE_CHARS) === '') {
            return true;
        }
        $patterns = [
            '/(?:(?<=[ \t\r\n])|^):(?:[a-zA-Z0-9]{1,8}(?:-[a-zA-Z0-9]{1,8})*)?(?=[ \t\r\n]|$)/',
            '/(?:(?<=[ \t\r\n])|^)[a-zA-Z_][a-zA-Z0-9_-]*=[^ \t\r\n"\'}|\\\\]+/',
            '/\.[a-zA-Z0-9_][a-zA-Z0-9_-]*+(?!:)/',
            '/#[a-zA-Z0-9_][a-zA-Z0-9_-]*+(?!:)/',
            '/(?:(?<=[ \t\r\n])|^)[a-zA-Z][a-zA-Z0-9_-]*(?=[ \t\r\n]|$)/',
            '/[ \t\r\n]+/',
        ];
        foreach ($patterns as $pattern) {
            $rest = preg_replace($pattern, ' ', $rest) ?? $rest;
        }

        return trim($rest, StringUtil::WHITESPACE_CHARS) === '';
    }

    /**
     * Run preg_match_all defensively so a PCRE engine failure is never
     * mistaken for "no matches".
     *
     * The attribute value sub-patterns are unrolled (linear), so the classic
     * PREG_JIT_STACKLIMIT_ERROR on long quoted values is no longer reachable.
     * This guard is defense-in-depth: if PCRE ever reports an engine error
     * (JIT stack/back-track limit, recursion limit, etc.) we retry once with
     * the JIT compiler disabled rather than silently dropping every attribute
     * on the element (which would leak the literal `{...}` and could strip
     * security-relevant attributes such as rel="noopener" or a CSP nonce).
     *
     * @param-out list<array<string>> $matches
     *
     * @param string $pattern PCRE pattern.
     * @param string $subject Subject string.
     * @param array<int, array<int, string>> $matches Filled with PREG_SET_ORDER matches.
     *
     * @return int Number of full matches found (0 on a clean no-match).
     */
    protected static function safeMatchAll(string $pattern, string $subject, array &$matches): int
    {
        $count = preg_match_all($pattern, $subject, $matches, PREG_SET_ORDER);
        if ($count !== false && preg_last_error() === PREG_NO_ERROR) {
            return $count;
        }

        // Engine error (e.g. JIT stack limit). Retry with the JIT disabled so
        // the value is matched by the PCRE interpreter instead of being
        // silently dropped.
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        try {
            $count = preg_match_all($pattern, $subject, $matches, PREG_SET_ORDER);
        } finally {
            ini_set('pcre.jit', $jit === false ? '1' : $jit);
        }

        if ($count === false) {
            // Non-JIT PCRE still failed; fall back to a clean no-match result so
            // callers behave deterministically rather than dropping attributes.
            $matches = [];

            return 0;
        }

        return $count;
    }

    /**
     * Process escape sequences in attribute values
     *
     * Per djot spec, backslash escapes work on ASCII punctuation characters:
     * - \\ -> \ (escaped backslash)
     * - \" -> " (escaped quote)
     * - \* -> * (escaped asterisk)
     * - etc. for all ASCII punctuation
     *
     * Backslash before alphanumeric characters is kept literal:
     * - \n -> \n (not a newline)
     * - \t -> \t (not a tab)
     * - \U -> \U (literal)
     */
    public static function processEscapes(string $value): string
    {
        $result = '';
        $length = strlen($value);
        $i = 0;

        // ASCII punctuation that can be escaped
        // Includes: !"#$%&'()*+,-./:;<=>?@[\]^_`{|}~
        $punctuation = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

        while ($i < $length) {
            $char = $value[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $next = $value[$i + 1];
                // Escape if next char is ASCII punctuation
                if (strpos($punctuation, $next) !== false) {
                    $result .= $next;
                    $i += 2;

                    continue;
                }
            }

            $result .= $char;
            $i++;
        }

        return $result;
    }
}
