<?php

declare(strict_types=1);

namespace Carve\Parser\Utility;

use Carve\Node\Node;

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
        // Remove comments before parsing
        $attrStr = self::removeComments($attrStr);

        $attributes = [];

        // Strip quoted values and unquoted key=value pairs before matching .class and #id
        // to avoid matching dots/hashes inside attribute values like key="file.txt"
        // or partial matches from invalid unquoted values like key=foo/bar
        $strippedForShorthand = preg_replace('/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/', '', $attrStr) ?? $attrStr;
        $strippedForShorthand = preg_replace("/'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'/", '', $strippedForShorthand) ?? $strippedForShorthand;
        // Strip unquoted key=value tokens entirely (up to whitespace) to prevent
        // invalid chars like slashes from being misinterpreted as shorthand
        $strippedForShorthand = preg_replace('/[a-zA-Z][a-zA-Z0-9_:-]*=[^\s]+/', '', $strippedForShorthand) ?? $strippedForShorthand;

        // Parse .class -- the class name is a grammar identifier and may not
        // start with a digit (a `class="123"` is also invalid CSS), so a
        // digit-first `.123` is not matched. The whole block then yields no
        // attribute and stays literal (§14).
        if (preg_match_all('/\.([a-zA-Z_][a-zA-Z0-9_:-]*)/', $strippedForShorthand, $classMatches)) {
            $attributes['class'] = implode(' ', $classMatches[1]);
        }

        // Parse #id -- the id is a grammar identifier (no leading digit).
        if (preg_match('/#([a-zA-Z_][a-zA-Z0-9_:-]*)/', $strippedForShorthand, $idMatch)) {
            $attributes['id'] = $idMatch[1];
        }

        // Parse key="double quoted value", key='single quoted value', or key=unquoted
        // The regex uses the unrolled form [^"\\]*(?:\\.[^"\\]*)* to match
        // content with escaped characters in linear time (the naive
        // ([^"\\]|\\.)* shape is catastrophic and trips the PCRE JIT
        // stacklimit on long values, see the engine-error guards below).
        // Per carve-js, unquoted values may contain any non-whitespace byte
        // except quotes and braces.
        // Unquoted values must be followed by whitespace or } to be valid.
        // Keys can contain letters, digits, underscore, hyphen, colon (permissive like JS reference)
        // The key is a grammar identifier and may not start with a digit, so
        // a digit-first key (`123=v`) is not matched -- the block then yields
        // no attribute and stays literal (§14). This also avoids a numeric
        // string key being cast to int when used as an array key.
        $kvPattern = '/(?:(?<=\s)|^)([a-zA-Z_][a-zA-Z0-9_:-]*)="([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|'
            . '(?:(?<=\s)|^)([a-zA-Z_][a-zA-Z0-9_:-]*)=\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\''
            . '|(?:(?<=\s)|^)([a-zA-Z_][a-zA-Z0-9_:-]*)=([^\s"\'{}]+)(?=\s|}|$)/';

        $kvMatches = [];
        if (self::safeMatchAll($kvPattern, $attrStr, $kvMatches)) {
            foreach ($kvMatches as $match) {
                if (($match[1] ?? '') !== '') {
                    // key="double quoted value"
                    $attributes[$match[1]] = self::processEscapes($match[2] ?? '');
                } elseif (($match[3] ?? '') !== '') {
                    // key='single quoted value'
                    $attributes[$match[3]] = self::processEscapes($match[4] ?? '');
                } elseif (($match[5] ?? '') !== '') {
                    // key=unquoted
                    $attributes[$match[5]] = $match[6] ?? '';
                }
            }
        }

        // Parse boolean attributes (bare words like "reversed", "hidden")
        // First, strip out quoted values and key=value pairs to avoid matching words inside them
        $strippedAttr = preg_replace('/(?:(?<=\s)|^)[a-zA-Z0-9_:-]+="[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/', '', $attrStr) ?? $attrStr;
        $strippedAttr = preg_replace("/(?:(?<=\s)|^)[a-zA-Z0-9_:-]+='[^'\\\\]*(?:\\\\.[^'\\\\]*)*'/", '', $strippedAttr) ?? $strippedAttr;
        $strippedAttr = preg_replace('/(?:(?<=\s)|^)[a-zA-Z0-9_:-]+=[^\s"\'{}]+/', '', $strippedAttr) ?? $strippedAttr;

        // Now match bare words (must not start with . or #)
        if (preg_match_all('/(?:^|\s)([a-zA-Z][a-zA-Z0-9_-]*)(?=\s|$)/', $strippedAttr, $boolMatches)) {
            foreach ($boolMatches[1] as $boolAttr) {
                $attributes[$boolAttr] = '';
            }
        }

        return $attributes;
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
        // An attribute name (key, class, id) is a grammar identifier: it may
        // not start with a digit. A digit-first name is not captured (and a
        // digit-first key=value is consumed by the invalid-value skip), so
        // the block yields no such attribute and stays literal (§14).
        $pattern = '/'
            // Group 1,2: key="double quoted value"
            . '(?:(?<=\s)|^)([a-zA-Z_][a-zA-Z0-9_:-]*)="([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|'
            // Group 3,4: key='single quoted value'
            . '(?:(?<=\s)|^)([a-zA-Z_][a-zA-Z0-9_:-]*)=\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|'
            // Group 5,6: key=unquoted (must end at whitespace/}/end, not invalid chars)
            . '(?:(?<=\s)|^)([a-zA-Z_][a-zA-Z0-9_:-]*)=([^\s"\'{}]+)(?=\s|}|$)|'
            // Skip invalid unquoted values (e.g. key=foo/bar, 1=v) - consume but don't capture
            . '(?:(?<=\s)|^)[a-zA-Z0-9_:-]+=[^\s}]+|'
            // Group 7: .class shorthand
            . '\.([a-zA-Z_][a-zA-Z0-9_:-]*)|'
            // Group 8: #id shorthand
            . '#([a-zA-Z_][a-zA-Z0-9_:-]*)|'
            // Group 9: boolean attribute (bareword)
            . '(?:^|\s)([a-zA-Z][a-zA-Z0-9_-]*)(?=\s|}|$)'
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
     * @param \Carve\Node\Node $node The node to apply attributes to
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
        // An attribute name (key, class, id) is a grammar identifier and may
        // not start with a digit, so a digit-first name is not captured (a
        // digit-first key=value is consumed by the invalid-value skip). This
        // also prevents a numeric key being cast to int and crashing escape().
        $pattern = '/'
            // Group 1,2: key="double quoted value"
            . '(?:(?<=\s)|^)([a-zA-Z_][a-zA-Z0-9_:-]*)="([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|'
            // Group 3,4: key='single quoted value'
            . '(?:(?<=\s)|^)([a-zA-Z_][a-zA-Z0-9_:-]*)=\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|'
            // Group 5,6: key=unquoted (must end at whitespace/}/end, not invalid chars)
            . '(?:(?<=\s)|^)([a-zA-Z_][a-zA-Z0-9_:-]*)=([^\s"\'{}]+)(?=\s|}|$)|'
            // Skip invalid unquoted values (e.g. key=foo/bar, 1=v) - consume but don't capture
            // This prevents .bar from being matched as a class
            . '(?:(?<=\s)|^)[a-zA-Z0-9_:-]+=[^\s}]+|'
            // Group 7: .class shorthand
            . '\.([a-zA-Z_][a-zA-Z0-9_:-]*)|'
            // Group 8: #id shorthand
            . '#([a-zA-Z_][a-zA-Z0-9_:-]*)|'
            // Group 9: boolean attribute (bareword)
            . '(?:^|\s)([a-zA-Z][a-zA-Z0-9_-]*)(?=\s|}|$)'
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
            }
        }
    }

    /**
     * Whether the WHOLE payload between `{...}` is valid attribute syntax.
     *
     * Strips every recognized token (quoted key=value, comments, unquoted
     * key=value, .class, #id, boolean); if anything non-whitespace remains the
     * block is invalid and must stay literal (§14). A name (key/class/id) is a
     * grammar identifier (letter/`_` first), so a digit-first or hyphen-first
     * name invalidates the whole block even mixed with valid tokens -- matching
     * carve-js / carve-rs and the inline attribute rule.
     */
    public static function isValidPayload(string $attrStr): bool
    {
        // Quoted key=values first, so dots/braces/% inside quotes are protected.
        $rest = preg_replace(
            '/(?:(?<=\s)|^)[a-zA-Z_][a-zA-Z0-9_:-]*=(?:"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')/',
            ' ',
            $attrStr,
        ) ?? $attrStr;
        $rest = self::removeComments($rest);
        if (trim($rest) === '') {
            return true;
        }
        $patterns = [
            '/(?:(?<=\s)|^)[a-zA-Z_][a-zA-Z0-9_:-]*=[^\s"\'{}]+/',
            '/\.[a-zA-Z_][a-zA-Z0-9_:-]*/',
            '/#[a-zA-Z_][a-zA-Z0-9_:-]*/',
            '/(?:(?<=\s)|^)[a-zA-Z][a-zA-Z0-9_-]*(?=\s|$)/',
            '/\s+/',
        ];
        foreach ($patterns as $pattern) {
            $rest = preg_replace($pattern, ' ', $rest) ?? $rest;
        }

        return trim($rest) === '';
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
