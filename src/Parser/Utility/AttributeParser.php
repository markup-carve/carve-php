<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Shared utility for parsing djot attribute strings.
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
     * @return array<string, string> Parsed attributes
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
     * @return array<string, string> Parsed attributes in source order
     */
    public static function parseOrdered(string $attrStr): array
    {
        return self::parseOrderedWithSlots($attrStr)['attributes'];
    }

    /**
     * Parse attribute string preserving source slot order.
     *
     * @return array{attributes: array<string, string>, order: list<string>}
     */
    public static function parseOrderedWithSlots(string $attrStr): array
    {
        // Remove comments before parsing
        $attrStr = self::removeComments($attrStr);

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
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)=([^ \t\r\n"\'{}]+)(?=[ \t\r\n]|}|$)|'
            // Skip invalid unquoted values (e.g. key=foo/bar, 1=v) - consume but don't capture
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
                $attributes[$match[1]] = self::processEscapes($match[2] ?? '');
                $order[] = $match[1];
            } elseif (($match[3] ?? '') !== '') {
                // key='single quoted value'
                $attributes[$match[3]] = self::processEscapes($match[4] ?? '');
                $order[] = $match[3];
            } elseif (($match[5] ?? '') !== '') {
                // key=unquoted
                $attributes[$match[5]] = $match[6] ?? '';
                $order[] = $match[5];
            } elseif (($match[7] ?? '') !== '') {
                // .class shorthand - accumulate classes
                $existing = $attributes['class'] ?? '';
                $attributes['class'] = $existing !== '' ? $existing . ' ' . $match[7] : $match[7];
                $order[] = '.class';
            } elseif (($match[8] ?? '') !== '') {
                // #id shorthand
                $attributes['id'] = $match[8];
                $order[] = '#id';
            } elseif (($match[9] ?? '') !== '') {
                // boolean attribute
                $attributes[$match[9]] = '';
                $order[] = $match[9];
            } elseif (($match['lang_sigil'] ?? '') === ':') {
                $attributes['lang'] = $match['lang_tag'] ?? '';
                $order[] = 'lang';
            }
        }

        return ['attributes' => $attributes, 'order' => $order];
    }

    /**
     * Parse attribute string and merge with existing attributes
     *
     * @param array<string, string> $existing Existing attributes to merge with
     * @param string $attrStr The attribute string to parse
     *
     * @return array<string, string> Merged attributes
     */
    public static function parseAndMerge(array $existing, string $attrStr): array
    {
        $parsed = self::parseOrdered($attrStr);

        // Special handling for class: merge rather than replace
        if (isset($parsed['class']) && isset($existing['class'])) {
            $parsed['class'] = trim($existing['class'] . ' ' . $parsed['class']);
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
        // Remove comments before parsing
        $attrStr = self::removeComments($attrStr);

        // Single-pass regex that matches all token types in source order.
        // Order matters: quoted values and invalid unquoted values must be matched/skipped
        // first to prevent dots/hashes inside them from being matched as .class or #id.
            // Unquoted values may contain any non-whitespace byte except
            // quotes and braces.
        // Explicit ids/classes admit an ASCII digit first. Keys do not; the
        // invalid-value skip also prevents numeric keys becoming PHP ints.
        $pattern = '/'
            // Group 1,2: key="double quoted value"
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)="([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|'
            // Group 3,4: key='single quoted value'
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)=\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|'
            // Group 5,6: key=unquoted (must end at whitespace/}/end, not invalid chars)
            . '(?:(?<=[ \t\r\n])|^)([a-zA-Z_][a-zA-Z0-9_-]*)=([^ \t\r\n"\'{}]+)(?=[ \t\r\n]|}|$)|'
            // Skip invalid unquoted values (e.g. key=foo/bar, 1=v) - consume but don't capture
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
                $node->setAttribute($match[1], self::processEscapes($match[2] ?? ''));
            } elseif (($match[3] ?? '') !== '') {
                // key='single quoted value'
                $node->setAttribute($match[3], self::processEscapes($match[4] ?? ''));
            } elseif (($match[5] ?? '') !== '') {
                // key=unquoted
                $node->setAttribute($match[5], $match[6] ?? '');
            } elseif (($match[7] ?? '') !== '') {
                // .class shorthand -- source-order, no de-dup (§15).
                $node->appendClass($match[7]);
            } elseif (($match[8] ?? '') !== '') {
                // #id shorthand
                $node->setAttribute('id', $match[8]);
            } elseif (($match[9] ?? '') !== '') {
                // boolean attribute
                $node->setAttribute($match[9], '');
            } elseif (($match['lang_sigil'] ?? '') === ':') {
                $node->setAttribute('lang', $match['lang_tag'] ?? '');
            }
        }
    }

    /**
     * Whether the WHOLE payload between `{...}` is valid attribute syntax.
     *
     * Strips every recognized token (quoted key=value, comments, unquoted
     * key=value, .class, #id, boolean); if anything non-whitespace remains the
     * block is invalid and must stay literal (§14). Explicit id/class names may
     * start with an ASCII digit; keys may not. A hyphen-first or COLON-bearing
     * name invalidates the whole block even mixed with valid tokens. A colon is still legal inside an
     * unquoted VALUE (`{k=a:b}`), which `unquoted_value` admits.
     */

    /**
     * PART 4: THE INLINE INTERIOR IS SPACE-ONLY (markup-carve/carve#906).
     *
     * Every whitespace slot of the INLINE attribute block is spelled `space`,
     * which is one character: the run after `{`, the run between two
     * attributes, the run before `}`, the boundary after an UNQUOTED value, and
     * the blessed empty block `{ }`. All five sit AFTER the first non-whitespace
     * character of their line, which is where PART 7's rule already says a tab
     * is not syntax. A tab at any of them makes the block unrecognized, and its
     * braces show.
     *
     * THE BLOCK-ATTRIBUTE LINE IS NOT NARROWED, and that distinction is the
     * ruling rather than an omission: it is the one construct whose interior can
     * hold a leading indentation run, because after a `continuation` the next
     * line's leading whitespace IS indentation. So this is a separate test with
     * its own name, applied at the inline call sites only - a fix that narrowed
     * both surfaces at once fails on corpus category 273.
     *
     * INSIDE A QUOTED VALUE a tab is CONTENT and does not move, so the scan
     * tracks quoting rather than looking for a byte.
     *
     * SPACE-ONLY, not tab-only. `whitespace` in the grammar is `' ' | '\t'` and
     * `space` is `' '`, so a vertical tab or a form feed was never a separator
     * under either spelling - this engine accepted them because PHP's `\s` did.
     * Narrowing the slot to one character answers both at once.
     */
    public static function inlineInteriorIsSpaceOnly(string $attrStr): bool
    {
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
        $rest = self::removeComments($rest);
        // See isValidAttrPayload(): PART 7's four characters, not PHP's default
        // trim charlist (markup-carve/carve#963).
        if (trim($rest, StringUtil::WHITESPACE_CHARS) === '') {
            return true;
        }
        $patterns = [
            '/(?:(?<=[ \t\r\n])|^):(?:[a-zA-Z0-9]{1,8}(?:-[a-zA-Z0-9]{1,8})*)?(?=[ \t\r\n]|$)/',
            '/(?:(?<=[ \t\r\n])|^)[a-zA-Z_][a-zA-Z0-9_-]*=[^ \t\r\n"\'{}]+/',
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

    /**
     * Remove comments from attribute string
     *
     * Supports two comment styles:
     * - Inline: % comment % (removed entirely)
     * - Trailing: % to end of string (removed)
     *
     * Comments are only recognized outside of quoted strings.
     * For example, title="100% done" keeps the % as part of the value.
     */
    protected static function removeComments(string $attrStr): string
    {
        $result = '';
        $length = strlen($attrStr);
        $i = 0;
        $inComment = false;

        while ($i < $length) {
            $char = $attrStr[$i];

            // Handle quoted strings - copy them verbatim (including any % inside)
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $result .= $char;
                $i++;

                // Copy until closing quote, handling escapes
                while ($i < $length) {
                    $c = $attrStr[$i];
                    if ($c === '\\' && $i + 1 < $length) {
                        // Escape sequence - copy both characters
                        $result .= $c . $attrStr[$i + 1];
                        $i += 2;
                    } elseif ($c === $quote) {
                        // Closing quote
                        $result .= $c;
                        $i++;

                        break;
                    } else {
                        $result .= $c;
                        $i++;
                    }
                }

                continue;
            }

            // Handle comments (only outside quotes)
            if ($char === '%') {
                if ($inComment) {
                    // End of inline comment
                    $inComment = false;
                    $i++;

                    continue;
                }

                // Check if this is start of inline comment (has closing %)
                $closePos = strpos($attrStr, '%', $i + 1);
                if ($closePos !== false) {
                    // Check if there's a quote before the closing % (would mean % is in a value)
                    $inlineContent = substr($attrStr, $i + 1, $closePos - $i - 1);
                    if (strpos($inlineContent, '"') === false && strpos($inlineContent, "'") === false) {
                        // Inline comment - skip to closing %
                        $inComment = true;
                        $i++;

                        continue;
                    }
                }

                // Trailing comment - skip rest of string
                break;
            }

            if (!$inComment) {
                $result .= $char;
            }
            $i++;
        }

        return $result;
    }
}
