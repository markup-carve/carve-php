<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Block;

/**
 * Parser utilities for fenced blocks (code blocks, divs, raw blocks).
 *
 * This class handles detection and content extraction for:
 * - Code blocks (``` or ~~~)
 * - Divs (:::)
 * - Raw blocks (``` =format)
 */
class FencedBlockParser
{
    /**
     * Check if a line opens a code block fence.
     *
     * @param string $line The line to check
     *
     * @return array{fence: string, char: string, length: int, info: string, header: string|null, label: string|null, indent: string}|null
     */
    public function parseCodeFenceOpener(string $line): ?array
    {
        // Fast early exit: code blocks start with ` or ~ (possibly after whitespace)
        $trimmed = ltrim($line);
        if ($trimmed === '' || ($trimmed[0] !== '`' && $trimmed[0] !== '~')) {
            return null;
        }

        // Match opening fence: 3+ backticks or tildes, optionally with leading whitespace
        if (!preg_match('/^(\s*)(`{3,}|~{3,})(.*)$/', $line, $matches)) {
            return null;
        }

        $indent = $matches[1];
        $fence = $matches[2];
        $fenceChar = $fence[0];
        $fenceLength = strlen($fence);
        $info = trim($matches[3]);

        // Check for inline code on a single line: ``` foo ``` should be inline code
        if ($fenceChar === '`' && self::hasRunAtLeast($info, '`', $fenceLength)) {
            return null;
        }

        // Info string (NORMATIVE, grammar PART 9 §2): an optional language
        // token, then an optional quoted "header", then an optional bracketed
        // [label], in that fixed order. The language token follows the grammar:
        // letters, digits, and - _ + # . / only. The "header" is a visible
        // title carried as the `title` attribute on the <pre> (rendering A);
        // the [label] is structured metadata a group extension (code-group)
        // uses as the tab name. Anything else -- a bare second word, a key=val
        // pair (```js title="x"), an inline {...} (``` php {.x}), or the
        // header/label in the wrong order (```php [l] "h") -- is NOT a fenced
        // code block and falls back to inline parsing. (Raw ```=FORMAT blocks
        // are matched by parseRawBlockOpener first; a leading `=` is never a
        // language token, and a raw block takes no header.)
        $language = '';
        $header = null;
        $label = null;
        if ($info !== '') {
            $rest = $info;
            // optional language token (skipped when a "header" or [label] leads)
            if ($rest[0] !== '"' && $rest[0] !== '[') {
                if (!preg_match('/^([A-Za-z0-9_\-+#.\/]+)/', $rest, $im)) {
                    return null;
                }
                $language = $im[1];
                // A leading `=` is the raw-block opener's territory, never a
                // language (parseRawBlockOpener runs first; a malformed raw
                // opener with trailing text must fall back, not become a code
                // fence with language `=FORMAT`).
                if ($language[0] === '=') {
                    return null;
                }
                $rest = substr($rest, strlen($language));
                // Header/label must be whitespace-separated from the language
                // (grammar: space+). A language glued to a quote or bracket
                // (```php"x", ```php[x]) is not valid metadata -> fall back.
                if ($rest !== '' && !ctype_space($rest[0])) {
                    return null;
                }
                $rest = ltrim($rest);
            }
            // optional quoted "header" (no escape inside, like the admonition title)
            if ($rest !== '' && $rest[0] === '"') {
                if (!preg_match('/^"([^"]*)"/', $rest, $im)) {
                    return null;
                }
                $header = $im[1];
                $rest = substr($rest, strlen($im[0]));
                // A [label] must be whitespace-separated from the header
                // (grammar: space+). A label glued to the header (```php "x"[y])
                // is not valid metadata -> fall back.
                if ($rest !== '' && !ctype_space($rest[0])) {
                    return null;
                }
                $rest = ltrim($rest);
            }
            // optional bracketed [label]; nothing else may follow
            if ($rest !== '') {
                if (!preg_match('/^\[([^\]]*)\]$/', $rest, $im)) {
                    return null;
                }
                $label = $im[1];
            }
        }

        return [
            'fence' => $fence,
            'char' => $fenceChar,
            'length' => $fenceLength,
            'info' => $language,
            'header' => $header,
            'label' => $label,
            'indent' => $indent,
        ];
    }

    /**
     * Check if a line closes a code block fence.
     *
     * @param string $line The line to check
     * @param string $fenceChar The fence character (` or ~)
     * @param int $fenceLength The minimum fence length required
     *
     * @return bool True if this line closes the fence
     */
    public function isCodeFenceCloser(string $line, string $fenceChar, int $fenceLength): bool
    {
        $pattern = '/^\s*(' . preg_quote($fenceChar, '/') . '+)\s*$/';
        if (preg_match($pattern, $line, $m) !== 1) {
            return false;
        }

        return strlen($m[1]) >= $fenceLength;
    }

    /**
     * Check if a line opens a div fence.
     *
     * @param string $line The line to check
     *
     * @return array{fence: string, length: int, className: string, label: string|null}|null
     */
    public function parseDivFenceOpener(string $line): ?array
    {
        // Fast early exit: divs start with :
        if (!isset($line[0]) || $line[0] !== ':') {
            return null;
        }

        // Match opening fence: 3+ colons, then an optional type word, an
        // optional quoted "header", an optional bracketed [label] -- in that
        // order -- and NOTHING else.
        if (!preg_match('/^(:{3,})\s*(.*)$/', $line, $matches)) {
            return null;
        }

        // STRICT (djot): the opener carries no inline {...} attributes. The
        // text after the fence must be empty (bare div), a type token, a type
        // token + quoted header, optionally followed by a [label] -- or a bare
        // [label] with no type. A type token is a word or the bare pipe `|`
        // (the line-block opener). The header keeps its role (admonition title
        // / summary); the [label] is a grouping id the core renderer ignores (a
        // group extension such as tabs consumes it). Any trailing `{...}` or
        // other text makes the line an ordinary paragraph, not a fence;
        // class/id attach via a preceding block-attribute line (§15).
        $rest = trim($matches[2]);
        $label = null;
        if ($rest !== '') {
            if (preg_match('/^\[([^\]]*)\]$/', $rest, $m)) {
                // bare [label], no type -- a typeless generic div (tab member)
                $label = $m[1];
                $rest = '';
            } elseif (preg_match('/^((?:\||[a-zA-Z_][\w-]*)(?:\s+"[^"]*")?)(?:\s+\[([^\]]*)\])?$/', $rest, $m)) {
                $rest = $m[1];
                if (isset($m[2])) {
                    $label = $m[2];
                }
            } else {
                return null;
            }
        }

        return [
            'fence' => $matches[1],
            'length' => strlen($matches[1]),
            'className' => $rest,
            'label' => $label,
        ];
    }

    /**
     * Check if a line closes a div fence.
     *
     * @param string $line The line to check
     * @param int $fenceLength The minimum fence length required
     *
     * @return bool True if this line closes the fence
     */
    public function isDivFenceCloser(string $line, int $fenceLength): bool
    {
        if (preg_match('/^(:+)\s*$/', $line, $m) !== 1) {
            return false;
        }

        return strlen($m[1]) >= $fenceLength;
    }

    /**
     * Check if a line opens a raw block.
     *
     * @param string $line The line to check
     *
     * @return array{fence: string, length: int, format: string}|null
     */
    public function parseRawBlockOpener(string $line): ?array
    {
        // Fast early exit: raw blocks start with a code fence (` or ~).
        if (!isset($line[0]) || ($line[0] !== '`' && $line[0] !== '~')) {
            return null;
        }

        // Carve raw block opener: ```=FORMAT (djot raw-block syntax, §4.15). The
        // leading `=`, immediately followed by the format name, is the block
        // parallel of the inline raw `{=format}` attribute; the former
        // ```raw FORMAT keyword form was removed.
        if (!preg_match('/^([`~]{3,})\s*=([a-zA-Z][\w-]*)\s*$/', $line, $matches)) {
            return null;
        }

        return [
            'fence' => $matches[1],
            'length' => strlen($matches[1]),
            'format' => $matches[2],
        ];
    }

    /**
     * Check if a line opens a fenced comment block (%%%).
     *
     * This supports multi-line comments with blank lines.
     *
     * @param string $line The line to check
     *
     * @return array{fence: string, length: int}|null
     */
    public function parseFencedCommentOpener(string $line): ?array
    {
        $trimmed = trim($line);

        // Fast early exit: fenced comments start with %
        if (!isset($trimmed[0]) || $trimmed[0] !== '%') {
            return null;
        }

        // Match opening fence: 3+ percent signs
        if (!preg_match('/^(%{3,})\s*$/', $trimmed, $matches)) {
            return null;
        }

        return [
            'fence' => $matches[1],
            'length' => strlen($matches[1]),
        ];
    }

    /**
     * Check if a line closes a fenced comment block.
     *
     * @param string $line The line to check
     * @param int $fenceLength The minimum fence length required
     *
     * @return bool True if this line closes the fence
     */
    public function isFencedCommentCloser(string $line, int $fenceLength): bool
    {
        if (preg_match('/^\s*(%+)\s*$/', $line, $m) !== 1) {
            return false;
        }

        return strlen($m[1]) >= $fenceLength;
    }

    /**
     * Does $haystack contain a run of $char at least $min long? Length-agnostic
     * match + strlen compare, NOT a `{$min,}` quantifier: PCRE rejects a
     * quantifier bound above 65535, so interpolating a huge fence length there
     * throws "number too big in {} quantifier" on adversarial input.
     */
    private static function hasRunAtLeast(string $haystack, string $char, int $min): bool
    {
        if (preg_match_all('/' . preg_quote($char, '/') . '+/', $haystack, $m) === 0) {
            return false;
        }
        foreach ($m[0] as $run) {
            if (strlen($run) >= $min) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove indent from a content line.
     *
     * @param string $line The line to process
     * @param int $indentLen Maximum indent to remove
     *
     * @return string The line with indent removed
     */
    public function removeIndent(string $line, int $indentLen): string
    {
        if ($indentLen > 0 && preg_match('/^(\s{0,' . $indentLen . '})(.*)$/', $line, $lineMatch)) {
            return $lineMatch[2];
        }

        return $line;
    }
}
