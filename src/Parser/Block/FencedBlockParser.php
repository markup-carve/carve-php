<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Block;

use MarkupCarve\Carve\Util\StringUtil;

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
        // Fast early exit: code blocks start exactly at the container content
        // column. Container parsers strip their own prefixes before calling in.
        if (!isset($line[0]) || ($line[0] !== '`' && $line[0] !== '~')) {
            return null;
        }

        // Match opening fence: 3+ backticks or tildes, no residual indent.
        if (!preg_match('/^(`{3,}|~{3,})(.*)$/', $line, $matches)) {
            return null;
        }

        $indent = '';
        $fence = $matches[1];
        $fenceChar = $fence[0];
        $fenceLength = strlen($fence);
        // THE SLOT BEFORE THE INFO STRING IS A SPACE, U+0020 and nothing else.
        // PART 7's MARKER SEPARATORS AND PADDING SLOTS decides the terminal by
        // POSITION, not by role: a tab is syntax only in a line's leading
        // indentation run, and this slot sits after the fence run. The grammar
        // spells it `fenced_code_block = code_fence_open, [space],
        // [code_fence_info], newline` and says so outright - the slot is
        // PADDING, "but both roles take `space`".
        //
        // `trim()` was the trap. Its default charlist is `" \t\n\r\0\x0B"`, so
        // it admitted a tab AND a vertical tab, the latter a character the
        // grammar names nowhere at all. Trailing whitespace is not a slot in
        // the grammar and stays as tolerant as it was, so only the leading side
        // is narrowed (carve-php#951).
        //
        // EXACTLY ONE, and `ltrim($matches[2], ' ')` was the second trap: it
        // took the whole run, so ```` ```<SP><SP>php ```` still opened a php
        // block. PART 7's cardinality paragraph settles it - the slot is
        // spelled `[space]`, one character, and a second space is not padding
        // (carve#912). What is left over then reaches the info string, where
        // `language_info` cannot match a space, and the opener falls back to an
        // inline verbatim span in a paragraph.
        //
        // ORDER, and it was the third trap: the slot has to be read off the RAW
        // tail, because `rtrim()` runs first and a tail that is ONLY a tab
        // collapses to `''`. The leading-space test then never saw the tab at
        // all, `$info` came out empty, and the fence opened - so ```` ```<TAB>
        // ```` opened a bare code block while ```` ```<TAB>php ```` correctly
        // fell back, the difference being nothing but whether the info string
        // was empty. The EMPTY-INFO shape is the one the slot check could not
        // reach (carve-php#1329).
        //
        // POSITION DECIDES, and the explicit slot test that used to stand here
        // is gone because it could no longer fire (carve#1295). The ruling split
        // this line in two, and the halves never overlap:
        //
        // - a run BEFORE content is a SEPARATOR. The slot rule governs, the
        //   `space` terminal is not satisfied, and the fence does not open:
        //   ```` ```<TAB>php ```` is prose.
        // - a run at END OF LINE with nothing after it is TRAILING whitespace.
        //   PART 2 drops it, and the fence opens normally: ```` ```<TAB> ```` is
        //   a bare code block.
        //
        // Read as "a tab anywhere after the fence run disqualifies the opener",
        // the slot rule reached a position it does not own: this engine refused
        // ```` ```<TAB> ```` while opening ```` ```<SP><SP><SP> ```` - two tails
        // that are ENTIRELY whitespace, two answers, told apart by nothing but
        // which whitespace character the author typed. The grammar already
        // refuses that shape of rule at MARKER REQUIRES CONTENT: "The rule
        // ignores trailing whitespace, so `-` and `- ` behave identically (an
        // editor stripping the trailing space cannot change the meaning)." An
        // editor stripping a trailing TAB must not change this line either.
        //
        // This engine's own frontmatter opener already split it this way -
        // ```` ---<TAB> ```` consumes the block, ```` ---<TAB>yaml ```` falls
        // back - so the fence opener was the outlier inside carve-php rather
        // than a second reading of the rule.
        //
        // WHY NO GUARD REPLACES IT. Narrowed to "refuse only when content
        // follows", the test became one that cannot fail. When it would fire,
        // `$info` begins with that same non-space whitespace character, and two
        // branches below `language_info` refuses it: the character is neither
        // `"` nor `[`, so the language branch runs, and `[A-Za-z0-9_\-+#./]+`
        // cannot match a tab. Every input it would have caught returns null
        // there instead. Measured as well as argued - across 504 opener shapes
        // (both fence characters, seven whitespace runs, nine info tails, in a
        // paragraph, a blockquote, a list item and a div, rendered to HTML,
        // Markdown and canonical Carve) removing it changed no output at all.
        // A check that cannot fail is worse than no check: it tells the next
        // reader the slot is defended when the info parse is what defends it.
        // The separator half is pinned by a BEHAVIOR test instead, so a future
        // widening of the language token cannot quietly take it with it.
        $info = rtrim($matches[2], StringUtil::WHITESPACE_CHARS);
        if (($info[0] ?? '') === ' ') {
            $info = substr($info, 1);
        }

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
                // Header/label must be SPACE-separated from the language
                // (grammar: `code_fence_info = ( language_info, [space+,
                // quoted_title], [space+, label] ) | …`). A language glued to a
                // quote or bracket (```php"x", ```php[x]) is not valid metadata
                // -> fall back.
                //
                // A RUN, not a first character. `ctype_space($rest[0])` looked
                // at one character and then `ltrim($rest)` stripped the rest
                // with the default charlist, so a tab or a vertical tab in the
                // run reached the metadata either way - and
                // ```` ``` js<SP><TAB>"T" ```` still carried its title. Testing
                // the first character for a space and consuming only spaces
                // leaves any other whitespace in `$rest`, which every branch
                // below then rejects (carve-php#951).
                if ($rest !== '' && $rest[0] !== ' ') {
                    return null;
                }
                $rest = ltrim($rest, ' ');
            }
            // optional quoted "header" (no escape inside, like the admonition title)
            if ($rest !== '' && $rest[0] === '"') {
                if (!preg_match('/^"([^"]*)"/', $rest, $im)) {
                    return null;
                }
                $header = $im[1];
                $rest = substr($rest, strlen($im[0]));
                // A [label] must be SPACE-separated from the header (grammar:
                // `[space+, label]`, in BOTH alternatives that carry it - one
                // slot, one role, one terminal). A label glued to the header
                // (```php "x"[y]) is not valid metadata -> fall back. Same
                // run-versus-first-character correction as the slot above
                // (carve-php#951).
                if ($rest !== '' && $rest[0] !== ' ') {
                    return null;
                }
                $rest = ltrim($rest, ' ');
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
        // LINE PADDING, so PART 7's four characters and not `\s`. PCRE reads a
        // VERTICAL TAB and a FORM FEED as `\s`, so a fence followed by one closed
        // while the same fence followed by any other content character did not
        // (markup-carve/carve#963).
        $pattern = '/^(' . preg_quote($fenceChar, '/') . '+)[ \t]*$/';
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
        // order -- and NOTHING else. A typed opener needs whitespace after the
        // fence (`::: note`); only a typeless label may be glued (`:::[Tab]`).
        if (!preg_match('/^(:{3,})(.*)$/', $line, $matches)) {
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
        $tail = $matches[2];
        if ($tail === '') {
            $rest = '';
        } elseif ($tail[0] === '[') {
            $rest = $tail;
        } elseif ($tail[0] === ' ') {
            // THE SEPARATOR IS A SPACE, U+0020 and nothing else. PART 7's
            // MARKER SEPARATORS AND PADDING SLOTS is normative: the slot
            // immediately after the fence run is a marker separator, because
            // the token after it selects which of the four blocks the line
            // opens. A tab was accepted here, so a tabbed opener opened an
            // admonition, a div, a line block or a local hard-break block
            // where the grammar makes the line a paragraph (carve-php#941).
            //
            // A RUN, not a first character. `ltrim($tail, ' ')` consumes only
            // spaces, so a tab anywhere in the separator run leaves the type
            // token unreachable and every branch below rejects the line. The
            // former `trim($tail)` inspected the first character and then
            // stripped a tab that followed it, so `:::<SP><TAB>note` still
            // opened an admonition. Trailing whitespace is not a slot in the
            // grammar and stays as tolerant as it was.
            $rest = rtrim(ltrim($tail, ' '), StringUtil::WHITESPACE_CHARS);
        } else {
            return null;
        }

        $label = null;
        if ($rest !== '') {
            if (preg_match('/^\[([^\]]*)\]$/', $rest, $m)) {
                // bare [label], no type -- a typeless generic div (tab member)
                $label = $m[1];
                $rest = '';
            } elseif (preg_match('/^(\|)$/', $rest, $m)) {
                // THE BARE PIPE ONLY. `|` is the line-block opener, and it takes
                // no quoted header and no [label] - the pipe IS the whole info
                // string. Letting it through the general type-token branch below
                // meant `::: | [id]` opened a line block and `::: | "t"` opened a
                // div with a literal `| "t"` class, where the executable spec,
                // carve-js and carve-rs all read both as ordinary paragraph text
                // (carve-php#820). Nothing in the grammar gives a line block a
                // label or a title.
                $rest = $m[1];
            } elseif (preg_match('/^(>)$/', $rest, $m)) {
                // THE BARE MARKER ONLY, on the same terms as the pipe above.
                // `>` is the fenced block-quote opener and the marker IS the
                // whole info string, so it takes no quoted header and no
                // [label] (markup-carve/carve#1718). It is admitted HERE, in
                // the generic opener, and not only in the parser's own branch,
                // because this is what the nesting-aware body collector counts
                // openers with - without it a fenced quote inside a fenced
                // quote closed the outer one early and left the leftover fence
                // to open an empty div.
                $rest = $m[1];
            // PADDING, and a space all the same. PART 7 decides the terminal
            // by POSITION, not by role: a tab is syntax only in a line's
            // leading indentation run, and these slots sit after the fence
            // run. `admonition_open` is spelled `colon_fence:open, space,
            // admonition_type, [space+, quoted_title], [space+, label]`, and
            // its own prose names this case - "`::: note<TAB>\"T\"` is not an
            // admonition opener, the line stays prose". carve#886 read these
            // slots as `whitespace`; carve#905 reverted that reading, because
            // the question is not what a slot recognizes but where it sits.
            //
            // Not `\s` either, at any point: PCRE's class is `[ \t\n\r\f\v]`,
            // so a form feed or a vertical tab would open an admonition the
            // grammar names nowhere. That narrowing came in with #947 and is
            // a fortiori still right now that the slot is a space.
            } elseif (preg_match('/^([a-zA-Z0-9_][\w-]*(?: +"[^"]*")?)(?: +\[([^\]]*)\])?$/', $rest, $m)) {
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
     * @param int $fenceLength The exact fence length required
     *
     * @return bool True if this line closes the fence
     */
    public function isDivFenceCloser(string $line, int $fenceLength): bool
    {
        if (preg_match('/^(:+)[ \t]*$/', $line, $m) !== 1) {
            return false;
        }

        return strlen($m[1]) === $fenceLength;
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
        //
        // THE SLOT BEFORE THE `=` IS A SPACE, U+0020 and nothing else. This one
        // is a marker SEPARATOR rather than padding - the `=` after it selects
        // a raw block over a code block - but PART 7 gives it the same terminal
        // regardless: `raw_block = code_fence_open, [space], "=", format_name,
        // newline`, and the clause says so of this exact slot ("identical
        // shape, different role, same terminal").
        //
        // PCRE's `\s` is `[ \t\n\r\f\v]`, which made this the widest of the
        // three fence-adjacent slots in this engine: a tab, a form feed AND a
        // vertical tab all opened a raw block. The trailing `\s*$` is not a
        // slot in the grammar and stays as tolerant as it was (carve-php#951).
        if (!preg_match('/^([`~]{3,}) *=([a-zA-Z][\w-]*)[ \t]*$/', $line, $matches)) {
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
     * @return array{fence: string, length: int, tail: string}|null
     */
    public function parseFencedCommentOpener(string $line): ?array
    {
        // Fast early exit: fenced comments start with %
        if (!isset($line[0]) || $line[0] !== '%') {
            return null;
        }

        // Match the leading structural run only. Everything after it is an
        // insignificant tail, not an info string.
        if (!preg_match('/^(%{3,})(.*)$/', $line, $matches)) {
            return null;
        }

        return [
            'fence' => $matches[1],
            'length' => strlen($matches[1]),
            'tail' => ltrim($matches[2], " \t"),
        ];
    }

    /**
     * Check if a line closes a fenced comment block.
     *
     * @param string $line The line to check
     * @param int $fenceLength The exact fence length required
     *
     * @return bool True if this line closes the fence
     */

    /**
     * The opener seen from a position that CONSUMES the fence.
     *
     * A comment is recognized at ANY column (PART 9 §24 C3, carve#624), and the
     * line form `%%` already is. Reading the fence only at column 0 where it is
     * consumed left an indented opener to the line-comment path, which took the
     * opener and the closer one line at a time and rendered every line BETWEEN
     * them as ordinary text - a comment that hid its delimiters and showed its
     * contents (carve-php#770). Leading whitespace is not part of the
     * delimiter; the `%` run length is.
     *
     * @param string $line
     *
     * @return array{fence: string, length: int, tail: string}|null
     */
    public function parseFencedCommentOpenerAnyColumn(string $line): ?array
    {
        return $this->parseFencedCommentOpener(ltrim($line, " \t"));
    }

    /**
     * The closer counterpart of `parseFencedCommentOpenerAnyColumn()`.
     *
     * @param string $line
     * @param int $fenceLength
     */
    public function isFencedCommentCloserAnyColumn(string $line, int $fenceLength): bool
    {
        return $this->isFencedCommentCloser(ltrim($line, " \t"), $fenceLength);
    }

    public function isFencedCommentCloser(string $line, int $fenceLength): bool
    {
        if (preg_match('/^(%{3,})/', $line, $m) !== 1) {
            return false;
        }

        return strlen($m[1]) === $fenceLength;
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
        if ($indentLen > 0 && preg_match('/^([ \t]{0,' . $indentLen . '})(.*)$/', $line, $lineMatch)) {
            return $lineMatch[2];
        }

        return $line;
    }

    /**
     * Whether a CODE fence could open at `$at`, by the opener's own first byte.
     *
     * A caller that has to skip the branch WITHOUT cutting the line out of its
     * container prefix asks here, rather than building the suffix just to hand
     * it to {@see self::parseCodeFenceOpener()} - the copy per level that made
     * markup-carve/carve-php#1437 quadratic. *
     * The parser's own fast exit spells the same byte test inline, because it
     * runs on nearly every line the parser reads and one more call for it
     * measured against an ordinary document. The two are held together by
     * `OffsetHeadsAgreeWithTheirParsersTest`, which walks EVERY byte value and
     * asserts the head accepts a line exactly where the parser can, so the pair
     * cannot drift in silence - which is the failure
     * markup-carve/carve-php#969 was.
     */
    public function isCodeFenceHead(string $line, int $at = 0): bool
    {
        $char = $line[$at] ?? '';

        return $char === '`' || $char === '~';
    }

    /**
     * Whether a DIV fence could open at `$at`, by the opener's own first byte.
     *
     * The offset-side head for {@see self::parseDivFenceOpener()}, for the
     * reason {@see self::isCodeFenceHead()} gives, and pinned by the same test.
     */
    public function isDivFenceHead(string $line, int $at = 0): bool
    {
        return ($line[$at] ?? '') === ':';
    }
}
