<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use InvalidArgumentException;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;

/**
 * Converts BBCode markup to Djot
 *
 * Useful for migrating forum content to Djot format.
 */
class BbcodeToCarve
{
    use EscapesCarveConstructs;

    /**
     * Maximum input length. The converter runs many full-string regex passes,
     * so cost is super-linear on a single huge input; BBCode is bounded forum
     * content, so reject anything implausibly large to keep conversion bounded.
     *
     * @var int
     */
    public const MAX_INPUT_LENGTH = 262144;

    /**
     * The preferred first code point of the run picked for the stash key.
     *
     * @var int
     */
    protected const STASH_KEY_FIRST = 0xE001;

    /**
     * @var int
     */
    protected const STASH_KEY_CODE = 0xE010;

    /**
     * @var array<int, string>
     */
    protected array $codeSentinels = ['', ''];

    /**
     * The preferred first code point of the run picked for the list boundary.
     *
     * @var int
     */
    protected const BOUNDARY_KEY = 0xE020;

    /**
     * Stands in for the HARD LIST BOUNDARY (PART 9 §11 N1a) until cleanup() is
     * over, since cleanup() is what would collapse the three blank lines the
     * boundary is spelled with.
     *
     * PICKED FROM WHAT THE INPUT DOES NOT CONTAIN, like this converter's stash
     * keys and for the same reason: BBCode is untrusted forum text, so a fixed
     * marker is a string an author may write, and the expansion would then turn
     * their text into blank lines.
     *
     * @var string
     */
    protected string $listBoundary = '';

    /**
     * Convert BBCode to Djot markup
     *
     * @throws \InvalidArgumentException when the input exceeds MAX_INPUT_LENGTH bytes
     */
    public function convert(string $bbcode): string
    {
        if (strlen($bbcode) > self::MAX_INPUT_LENGTH) {
            throw new InvalidArgumentException(
                'BBCode input exceeds maximum length of ' . self::MAX_INPUT_LENGTH . ' bytes',
            );
        }

        // A U+0000 IN THE INPUT IS REPLACED BY U+FFFD, before anything reads the
        // text. An importer is the same boundary as an ingest, and PART 12 §21
        // states it as a SHOULD rather than a MUST because the format being read
        // may have a rule of its own - BBCode has none, so Carve's applies, and
        // a converter that emitted the byte was writing source the Carve parser
        // replaces on read.
        //
        // Not the same act as picking the stash key below: a picked key is drawn
        // from characters the input MAY legitimately carry, so it needs a scan
        // and a refusal when the private-use area is full. NUL is not a
        // character this converter may emit at all.
        $djot = str_replace("\0", "\u{FFFD}", $bbcode);

        // Normalize line endings
        $djot = str_replace("\r\n", "\n", $djot);
        $djot = str_replace("\r", "\n", $djot);
        $djot = $this->escapePlainBbcodeText($djot);

        // CODE CONTENT IS LITERAL, and it has to stay literal for the whole
        // pipeline rather than for one step of it. escapePlainBbcodeText()
        // stashes code while it escapes and restores before returning, so every
        // converter below saw the enclosed markup and rewrote it:
        // [code][b]not bold[/b][/code] came out as a fence containing
        // *not bold*, which is neither what the author wrote nor BBCode - and
        // showing markup is most of what [code] is used for on a forum
        // (markup-carve/carve-php#1206).
        //
        // Only the CONTENT is stashed; the tags stay visible so convertCode()
        // still recognizes the run and builds the fence. The sentinel is
        // restored at the very END, after cleanup, because cleanup strips
        // leftover BBCode tags and the content legitimately contains some.
        $codeStash = [];
        $djot = $this->stashCodeContent($djot, $codeStash);

        [$this->listBoundary] = DocumentSentinels::pick($djot, 1, self::BOUNDARY_KEY);

        // Links and images first (before basic formatting escapes brackets)
        $djot = $this->convertLinks($djot);
        $djot = $this->convertImages($djot);

        // Basic formatting
        $djot = $this->convertBasicFormatting($djot);

        // Code blocks and inline code
        $djot = $this->convertCode($djot);

        // LISTS BEFORE QUOTES. convertQuotes() is the one pass that PREFIXES
        // lines, and a pass that runs after it rewrites text whose block
        // context it cannot see: convertLists() matched straight across the
        // `> ` prefixes and returned a separator and item lines with none of
        // them, so a `[list]` inside a `[quote]` wrote an unquoted blank line
        // into the middle of the quote and one source quote came back as two
        // - four, with an empty one among them, for two adjacent lists
        // (markup-carve/carve-php#1619). Converting the list first means the
        // quote formatter below prefixes FINISHED Carve source, which is the
        // only text it can prefix correctly.
        $djot = $this->convertLists($djot);

        // Quotes
        $djot = $this->convertQuotes($djot);

        // Other elements
        $djot = $this->convertOther($djot);

        // Clean up
        $djot = $this->cleanup($djot);
        $djot = $this->expandListBoundaries($djot);

        $djot = $this->restoreCodeContent($djot, $codeStash);

        return $djot;
    }

    /**
     * Stash the spans that must survive Carve escaping, then put them back.
     *
     * THE STASH KEY IS CHOSEN FROM WHAT THE INPUT DOES NOT CONTAIN. It used to
     * be the fixed `NUL B <index> NUL`, on the assumption that no forum post
     * carries a NUL - and unlike the Markdown converter next door, this one does
     * not strip input NULs, so the assumption was never enforced. A post
     * containing `<NUL>B0<NUL>` had that text REPLACED BY AN UNRELATED SPAN of
     * the same post, and one whose index was past the end of the stash raised an
     * uncaught TypeError out of the restore callback - a crash reachable from
     * ordinary untrusted input (markup-carve/carve-php#1087).
     *
     * Picking the delimiters instead removes both: a key the input cannot
     * contain cannot be authored, so there is no unrelated span to substitute
     * and no index that was not put there by this method.
     */

    /**
     * Replace the CONTENT of every code run with a sentinel.
     *
     * The tags are left in place so convertCode() still sees a code run and
     * builds its fence; only what the author wrote inside is hidden, which is
     * the part that has to survive verbatim. [noparse] carries the same
     * contract - its content is shown, not read - and is stashed with them.
     *
     * @param string $text
     * @param array<int, string> $stash
     */
    protected function stashCodeContent(string $text, array &$stash): string
    {
        [$open, $close] = DocumentSentinels::pick($text, 2, self::STASH_KEY_CODE);
        $this->codeSentinels = [$open, $close];

        $protect = function (bool $trim) use (&$stash, $open, $close): Closure {
            return function (array $match) use (&$stash, $open, $close, $trim): string {
                $stash[] = $trim ? trim($match[2]) : $match[2];

                return $match[1] . $open . (count($stash) - 1) . $close . $match[3];
            };
        };

        // THE FENCE TRIM HAPPENS HERE, not in convertCode(). A block fence is
        // built around the body, and once that body is a KEY there is no
        // whitespace left for a trim to find: the newlines sit inside the stash
        // and come back with the content, so every fence gained a blank line
        // above and below its code. A forum post's [code] almost always carries
        // a newline right after the opening tag, so it fired on ordinary input
        // (markup-carve/carve-php#1612). carve-js trims in the same place
        // (markup-carve/carve-js#1375).
        //
        // BLOCK FAMILY ONLY. The inline family is written verbatim between
        // backticks and has never been trimmed - `[c] a [/c]` is a code span
        // holding a space on each side - so it is stashed as it stands.
        $patterns = [
            '/(\[code(?:=[^\]]*)?\])(.*?)(\[\/code\])/is' => true,
            '/(\[(?:c|icode)\])(.*?)(\[\/(?:c|icode)\])/is' => false,
        ];
        foreach ($patterns as $pattern => $trim) {
            $text = preg_replace_callback($pattern, $protect($trim), $text) ?? $text;
        }

        // [noparse] has no Carve construct to become. Its whole effect is "the
        // enclosed text is literal", so the TAGS are consumed and the content
        // is escaped to stay literal - the same treatment ordinary text gets.
        // Keeping the tags emitted them into the output verbatim, and the
        // cleanup pass then ate the closer, leaving an unbalanced `[noparse]`
        // in a document that has no such construct (markup-carve/carve-php#1209).
        // The content is already escaped: escapePlainBbcodeText() ran over the
        // whole document before this, and it does not stash [noparse]. Escaping
        // again here doubled the backslash - `a *b* c` became `a \\*b* c`,
        // which renders a literal backslash AND the bold it was meant to
        // prevent. So the content is stashed as it stands and only the tags go.
        // ITS BLOCK OPENERS ARE ESCAPED HERE, because this is the last point
        // at which the body is known to be literal. escapePlainBbcodeText()
        // escaped what the body HOLDS and nothing it STARTS a line with, so
        // `[noparse]` was the one place in this converter where a `- ` marker
        // reached the document live: the text the author declared literal came
        // back as a list, and the blank run inside it as the hard boundary
        // between two of them (markup-carve/carve-php#1622). The code family
        // above needs no such escape because its body lands inside a fence,
        // which neutralizes everything; this one lands bare.
        $dropTags = function (array $match) use (&$stash, $open, $close): string {
            $stash[] = $this->escapeLineInitialBlockSyntax($match[1]);

            return $open . (count($stash) - 1) . $close;
        };

        return preg_replace_callback('/\[noparse\](.*?)\[\/noparse\]/is', $dropTags, $text) ?? $text;
    }

    /**
     * Put the code content back, after every pass that could rewrite it.
     *
     * ONE PASS IS NOT ENOUGH WHEN THE RUNS NEST. stashCodeContent() hides the
     * two families in turn - the code runs first, [noparse] second - so a
     * [noparse] body can hold a key that was spliced in moments earlier, and a
     * single preg_replace_callback continues scanning AFTER each replacement
     * and never looks at what it just wrote. The raw private-use pair reached
     * the output for `[noparse][code]x[/code][/noparse]`, which is a sentinel
     * escaping into user-visible text (markup-carve/carve-php#1611).
     *
     * Restoring in a bounded loop closes it. carve-js does the same in
     * `stashLiteralRuns` (markup-carve/carve-js#1375).
     *
     * @param string $text
     * @param array<int, string> $stash
     */
    protected function restoreCodeContent(string $text, array $stash): string
    {
        if ($stash === []) {
            return $text;
        }

        [$open, $close] = $this->codeSentinels;
        $pattern = '/' . preg_quote($open, '/') . '(\d+)' . preg_quote($close, '/') . '/u';

        // The bound is the number of stashed spans, hoisted out of the loop
        // condition: every pass that changes the text consumes at least one
        // span, so no input can spin, and a pass that changes nothing breaks.
        $bound = count($stash);

        $restored = $text;
        for ($pass = 0; $pass <= $bound; $pass++) {
            $subject = $restored;
            // MATCHED WITH OFFSETS, so each restored body can be given the
            // BLOCK CONTEXT of the line its key sits on. A stashed body is many
            // lines and the key standing for it is one, so convertQuotes()
            // prefixed the key's LINE and lines 2..n of the body arrived
            // afterwards at column 0 - outside the quote, and outside the fence
            // convertCode() built around it. One quoted code run came back as
            // two empty quoted fences with the body's own text parsed as markup
            // between them (markup-carve/carve-php#1620).
            $put = function (array $match) use ($stash, $subject): string {
                $body = $stash[(int)$match[1][0]] ?? '';

                return $this->indentToBlockContext($body, $this->blockPrefixAt($subject, (int)$match[0][1]));
            };

            $next = preg_replace_callback($pattern, $put, $restored, -1, $count, PREG_OFFSET_CAPTURE) ?? $restored;
            if ($next === $restored) {
                break;
            }

            $restored = $next;
        }

        return $restored;
    }

    /**
     * The BLOCK PREFIX the line at that offset carries.
     *
     * The leading run of block-quote markers and indentation, and nothing else:
     * what a container puts in front of EVERY line it holds, so a payload that
     * gains lines can be given the same. Text further along the line is that
     * line's content and is not repeated - an inline code span sits mid-line
     * and its continuation belongs under the block, not under the words in
     * front of it.
     *
     * A payload landing at column 0 gets an empty prefix, which is what it had.
     *
     * @param string $subject
     * @param int $offset
     */
    protected function blockPrefixAt(string $subject, int $offset): string
    {
        $lineStart = strrpos(substr($subject, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        // SCANNED RATHER THAN MATCHED. The equivalent pattern can match the
        // empty string, so it matches at every offset and a guard on its return
        // value would be a check that cannot fail. A scan bounded by the key's
        // own offset states the same rule and has no branch that cannot be
        // reached.
        $prefix = '';
        for ($at = $lineStart; $at < $offset; $at++) {
            $char = $subject[$at];
            if ($char !== ' ' && $char !== "\t" && $char !== '>') {
                break;
            }
            $prefix .= $char;
        }

        return $prefix;
    }

    /**
     * Give every line after the first the prefix its block context carries.
     *
     * A BLANK LINE TAKES THE PREFIX WITHOUT ITS TRAILING SPACE. Inside a fence
     * the prefix's own trailing space would be content - a blank line of code
     * would come back holding one - and `>` alone quotes an empty line just as
     * `> ` does.
     *
     * @param string $body
     * @param string $prefix
     */
    protected function indentToBlockContext(string $body, string $prefix): string
    {
        if ($prefix === '' || !str_contains($body, "\n")) {
            return $body;
        }

        $lines = explode("\n", $body);
        $out = (string)array_shift($lines);
        foreach ($lines as $line) {
            $out .= "\n" . ($line === '' ? rtrim($prefix) : $prefix . $line);
        }

        return $out;
    }

    protected function escapePlainBbcodeText(string $bbcode): string
    {
        [$open, $close] = DocumentSentinels::pick($bbcode, 2, self::STASH_KEY_FIRST);
        $protected = [];
        $protect = function (array $match) use (&$protected, $open, $close): string {
            $protected[] = $match[0];

            return $open . (count($protected) - 1) . $close;
        };

        $text = preg_replace_callback('/\[code(?:=[^\]]*)?\].*?\[\/code\]/is', $protect, $bbcode) ?? $bbcode;
        $text = preg_replace_callback('/\[(?:c|icode)\].*?\[\/(?:c|icode)\]/is', $protect, $text) ?? $text;
        $text = preg_replace_callback('/\[url\].*?\[\/url\]/is', $protect, $text) ?? $text;
        $text = preg_replace_callback('/\[img(?:=[^\]]*)?\].*?\[\/img\]/is', $protect, $text) ?? $text;
        $text = preg_replace_callback('/\[\/?[a-z][a-z0-9]*(?:=[^\]]*)?\]/i', $protect, $text) ?? $text;
        // `[*]` is the list-item tag, and the pattern above cannot see it - it
        // requires a letter after the bracket. Left unprotected, two of them on
        // a line are a `*…*` pair to the escaper, which then backslashes the
        // opener of the very marker convertLists() is about to read.
        $text = preg_replace_callback('/\[\*\]/', $protect, $text) ?? $text;

        // BBCode has no backslash escape either, so a backslash here is the
        // author's character. Doubled after the tags and code spans are stashed,
        // so only real text is touched.
        $text = $this->escapePlainCarveInlineSyntax(
            $this->escapeAttributeBlockOpener($this->escapeVerbatimDelimiter($this->escapeLiteralBackslashes($text))),
            self::HANDLED_PLAIN,
        );

        return preg_replace_callback(
            '/' . preg_quote($open, '/') . '(\d+)' . preg_quote($close, '/') . '/u',
            fn (array $match): string => $protected[(int)$match[1]],
            $text,
        ) ?? $text;
    }

    protected function convertBasicFormatting(string $text): string
    {
        // Bold [b]...[/b] -> *...*
        $text = preg_replace('/\[b\](.*?)\[\/b\]/is', '*$1*', $text) ?? $text;

        // Italic [i]...[/i] -> /.../
        $text = preg_replace('/\[i\](.*?)\[\/i\]/is', '/$1/', $text) ?? $text;

        // Underline [u]...[/u] -> _..._
        $text = preg_replace('/\[u\](.*?)\[\/u\]/is', '_$1_', $text) ?? $text;

        // Strikethrough [s]...[/s] -> ~...~
        $text = preg_replace('/\[s\](.*?)\[\/s\]/is', '~$1~', $text) ?? $text;

        // Size [size=X]...[/size] - no direct equivalent, strip tags
        $text = preg_replace('/\[size=[^\]]*\](.*?)\[\/size\]/is', '$1', $text) ?? $text;

        // Color [color=X]...[/color] - no direct equivalent, strip tags
        $text = preg_replace('/\[color=[^\]]*\](.*?)\[\/color\]/is', '$1', $text) ?? $text;

        // Font [font=X]...[/font] - no direct equivalent, strip tags
        $text = preg_replace('/\[font=[^\]]*\](.*?)\[\/font\]/is', '$1', $text) ?? $text;

        return $text;
    }

    protected function convertLinks(string $text): string
    {
        // [url=http://...]text[/url] -> [text](url)
        $text = preg_replace(
            '/\[url=([^\]]+)\](.*?)\[\/url\]/is',
            '[$2]($1)',
            $text,
        ) ?? $text;

        // [url]http://...[/url] -> <url> (autolink)
        $text = preg_replace(
            '/\[url\](.*?)\[\/url\]/is',
            '<$1>',
            $text,
        ) ?? $text;

        // [email]...[/email] -> <mailto:...>
        $text = preg_replace(
            '/\[email\](.*?)\[\/email\]/is',
            '<mailto:$1>',
            $text,
        ) ?? $text;

        return $text;
    }

    protected function convertImages(string $text): string
    {
        // [img]url[/img] -> ![](url)
        $text = preg_replace(
            '/\[img\](.*?)\[\/img\]/is',
            '![]($1)',
            $text,
        ) ?? $text;

        // [img=WxH]url[/img] -> ![](url)
        $text = preg_replace(
            '/\[img=[^\]]*\](.*?)\[\/img\]/is',
            '![]($1)',
            $text,
        ) ?? $text;

        return $text;
    }

    protected function convertCode(string $text): string
    {
        // THE BODY IS A KEY BY NOW, not the author's text. stashCodeContent()
        // runs before every converter, so a trim here has no whitespace to find
        // and the newlines it used to remove sit inside the stash - which is why
        // it moved there rather than being kept in both places
        // (markup-carve/carve-php#1612). One rule, one spelling: a trim left
        // here would be a check that cannot fail.
        //
        // [code=lang]...[/code] -> ```lang\n...\n```
        $text = preg_replace_callback(
            '/\[code=([^\]]+)\](.*?)\[\/code\]/is',
            // Neutralize a leading `=` in the [code=..] language so untrusted
            // Bbcode cannot mint a Carve `=html` raw-HTML block (live HTML under
            // the default renderer). `[code= =html]` -> inert ```html block.
            fn ($m) => "\n\n```" . ltrim(ltrim(strtolower(trim($m[1])), '=')) . "\n" . $m[2] . "\n```\n\n",
            $text,
        ) ?? $text;

        // [code]...[/code] -> ```\n...\n```
        $text = preg_replace_callback(
            '/\[code\](.*?)\[\/code\]/is',
            fn ($m) => "\n\n```\n" . $m[1] . "\n```\n\n",
            $text,
        ) ?? $text;

        // Inline [c]...[/c] or [icode]...[/icode] -> `...`
        $text = preg_replace('/\[c\](.*?)\[\/c\]/is', '`$1`', $text) ?? $text;
        $text = preg_replace('/\[icode\](.*?)\[\/icode\]/is', '`$1`', $text) ?? $text;

        return $text;
    }

    protected function convertQuotes(string $text): string
    {
        // Use depth tracking to handle nested quotes properly
        return $this->parseQuotesWithDepth($text);
    }

    /**
     * Parse BBCode quotes with proper nesting support.
     *
     * Uses depth tracking to correctly match opening and closing tags,
     * then recursively processes nested quotes.
     */
    protected function parseQuotesWithDepth(string $text): string
    {
        $length = strlen($text);
        $i = 0;
        // Single left-to-right pass with a stack of open-quote content buffers
        // (O(n)). The previous version recursed on each closed quote's inner
        // content and re-scanned it, which is O(n^2) on deeply nested
        // `[quote]` (a converter DoS). Index 0 accumulates the top-level output;
        // each `[quote]` pushes a level, each `[/quote]` pops one, formats it as
        // a blockquote, and folds it into its parent -- producing the same
        // output the recursion did for well-formed input.
        /** @var array<int, string> $contents */
        $contents = [''];
        /** @var array<int, string|null> $authors */
        $authors = [null];
        $top = 0;

        while ($i < $length) {
            if (preg_match('/\\G\[quote(?:[= ]([^\]]*))?\]/i', $text, $m, 0, $i)) {
                $contents[] = '';
                $authors[] = $m[1] ?? null;
                $top++;
                $i += strlen($m[0]);

                continue;
            }

            if (preg_match('/\\G\[\/quote\]/i', $text, $m, 0, $i)) {
                $i += strlen($m[0]);
                if ($top > 0) {
                    $blockquote = $this->formatAsBlockquote($contents[$top], $authors[$top]);
                    array_pop($contents);
                    array_pop($authors);
                    $top--;
                    $contents[$top] .= $blockquote;
                }
                // A stray `[/quote]` with no open quote is dropped.

                continue;
            }

            $contents[$top] .= $text[$i];
            $i++;
        }

        // Unclosed quotes: format each remaining open level as a blockquote,
        // innermost first, folding into its parent (matches the previous
        // "content runs to end of input" behavior).
        while ($top > 0) {
            $blockquote = $this->formatAsBlockquote($contents[$top], $authors[$top]);
            array_pop($contents);
            array_pop($authors);
            $top--;
            $contents[$top] .= $blockquote;
        }

        $result = $contents[0];

        return $result;
    }

    /**
     * Format content as a Djot blockquote.
     */
    protected function formatAsBlockquote(string $content, ?string $author): string
    {
        $content = trim($content);
        $lines = explode("\n", $content);
        // A BLANK LINE IN THE QUOTE IS PREFIXED WITH THE BARE MARKER. `> ` is
        // the same block to the parser, but the trailing space is whitespace
        // the author did not write, and inside a fence in the quote it would be
        // content rather than layout.
        $quoted = array_map(fn ($line) => $line === '' ? '>' : '> ' . $line, $lines);

        // Ensure blank line before blockquote for proper Djot block separation
        $output = "\n\n" . implode("\n", $quoted) . "\n";

        if ($author !== null && $author !== '') {
            $output .= '^ ' . $this->formatAttribution($author) . "\n";
        }

        return $output . "\n";
    }

    /**
     * Parse BBCode quote attribution and format as "name (datetime) #id".
     *
     * Handles formats like:
     * - username
     * - username date="2024-01-01"
     * - "9" name="user" date="2024-01-01 12:30"
     * - id="9" name="user" date="2024-01-01"
     */
    protected function formatAttribution(string $attribution): string
    {
        $attribution = trim($attribution);
        $remaining = $attribution;

        $id = null;
        $name = null;
        $datetime = null;

        // Extract id="..." or bare "..." at start (post/message ID)
        if (preg_match('/^["\'](\d+)["\']/', $remaining, $m)) {
            $id = $m[1];
            $remaining = trim(substr($remaining, strlen($m[0])));
        } elseif (preg_match('/\bid=["\']?(\d+)["\']?/i', $remaining, $m)) {
            $id = $m[1];
            $remaining = str_replace($m[0], '', $remaining);
        }

        // Extract name="..."
        if (preg_match('/\bname=["\']([^"\']+)["\']/i', $remaining, $m)) {
            $name = $m[1];
            $remaining = str_replace($m[0], '', $remaining);
        }

        // Extract date="..." (may include time)
        if (preg_match('/\bdate=["\']([^"\']+)["\']/i', $remaining, $m)) {
            $datetime = $m[1];
            $remaining = str_replace($m[0], '', $remaining);
        }

        // Extract time="..." separately if present
        if (preg_match('/\btime=["\']([^"\']+)["\']/i', $remaining, $m)) {
            $datetime = $datetime !== null ? $datetime . ' ' . $m[1] : $m[1];
            $remaining = str_replace($m[0], '', $remaining);
        }

        // If no name attribute found, use remaining text as name
        $remaining = trim($remaining);
        if ($name === null && $remaining !== '') {
            $name = $remaining;
        }

        // Build output: name (datetime) #id
        $output = $name ?? '';

        if ($datetime !== null) {
            $output .= ' (' . $datetime . ')';
        }

        if ($id !== null) {
            $output .= ' #' . $id;
        }

        return trim($output);
    }

    /**
     * Convert every BBCode list, at any depth, into Carve list source.
     *
     * DEPTH-TRACKED, NOT MATCHED BY A REGEX, for the reason
     * parseQuotesWithDepth() is: `/\[list\](.*?)\[\/list\]/is` is non-greedy,
     * so an outer `[list]` CLOSES ON THE INNER `[/list]`. The leftover opener
     * survived the passes below too - cleanup() strips `[/tag]` and
     * `[tag=value]`, never a bare `[tag]` - so a nested list leaked a literal
     * `[list]` into its first item, flattened the inner item to a sibling of
     * the outer ones and left the second outer item as a paragraph carrying a
     * literal `[*]` (markup-carve/carve-php#1623).
     *
     * The same single left-to-right pass with a stack of open-list buffers, and
     * the same O(n) bound: each opener pushes a level, each `[/list]` pops one,
     * formats it and folds it into the buffer that holds it. An unclosed list
     * runs to the end of the input, which is what an unclosed quote does.
     *
     * `[list=X]` IS THE ORDERED FORM WHATEVER X IS. Only `[list=1]` was read as
     * one, so `[list=a]` matched no branch at all: cleanup() ate its tags and
     * the bare `[*]` markers were left as text - a pair of them on one line
     * then read as an emphasis span, so `[*]one` came back as `[<strong>]one`.
     * Carve has one ordered spelling, so the style X names is not carried, but
     * the list is.
     */
    protected function convertLists(string $text): string
    {
        $length = strlen($text);
        $i = 0;
        /** @var array<int, string> $contents */
        $contents = [''];
        /** @var array<int, bool> $ordered */
        $ordered = [false];
        // Where each level's last folded list block ENDED, so an adjacent
        // sibling can be recognized by there being nothing but whitespace since.
        /** @var array<int, int|null> $listEnded */
        $listEnded = [null];
        $top = 0;

        while ($i < $length) {
            if (preg_match('/\G\[list(?:=([^\]]*))?\]/i', $text, $m, 0, $i) === 1) {
                $contents[] = '';
                $ordered[] = ($m[1] ?? '') !== '';
                $listEnded[] = null;
                $top++;
                $i += strlen($m[0]);

                continue;
            }

            if (preg_match('/\G\[\/list\]/i', $text, $m, 0, $i) === 1) {
                $i += strlen($m[0]);
                if ($top > 0) {
                    $block = $this->formatAsList($contents[$top], $ordered[$top]);
                    array_pop($contents);
                    array_pop($ordered);
                    array_pop($listEnded);
                    $top--;
                    $contents[$top] = $this->appendListBlock($contents[$top], $block, $top === 0, $listEnded[$top]);
                    $listEnded[$top] = strlen($contents[$top]);
                }
                // A stray `[/list]` with no open list is dropped.

                continue;
            }

            $contents[$top] .= $text[$i];
            $i++;
        }

        while ($top > 0) {
            $block = $this->formatAsList($contents[$top], $ordered[$top]);
            array_pop($contents);
            array_pop($ordered);
            array_pop($listEnded);
            $top--;
            $contents[$top] = $this->appendListBlock($contents[$top], $block, $top === 0, $listEnded[$top]);
            $listEnded[$top] = strlen($contents[$top]);
        }

        return $contents[0];
    }

    /**
     * Append a formatted list block to the buffer that holds it, parted from an
     * ADJACENT SIBLING list by the hard list boundary.
     *
     * Two lists with only a blank line between them are ONE list to the parser,
     * so `[list=1][*]one[/list][list=1][*]two[/list]` came back as a single
     * `<ol>` of two items and the second list's restart at 1 went with it
     * (markup-carve/carve-php#1621). The unordered path dodged that by
     * ALTERNATING the bullet marker per list, which invents a marker the source
     * never carried and has nothing left to say about a third list. The HTML
     * importer gave the same trick up for the boundary in
     * markup-carve/carve-php#1598; this is that rule, spelled the same way, and
     * it now covers both axes instead of one.
     *
     * PART 9 §11 N1a: the boundary is a run of three blank lines. It cannot be
     * written as three blank lines HERE, because cleanup() collapses every such
     * run to one - it has to, the passes above leave runs everywhere - so a
     * sentinel line stands in for it and expandListBoundaries() writes it out
     * once cleanup is over.
     *
     * @param string $buffer
     * @param string $block
     * @param bool $topLevel
     * @param int|null $previousListEnd Where this buffer's last list block ended, if it has one.
     */
    protected function appendListBlock(string $buffer, string $block, bool $topLevel, ?int $previousListEnd): string
    {
        if ($previousListEnd !== null && trim(substr($buffer, $previousListEnd)) === '') {
            return substr($buffer, 0, $previousListEnd) . "\n" . $this->listBoundary . "\n" . $block . "\n";
        }

        // The trailing whitespace the tags left behind is dropped first, so the
        // separator is the one this decides rather than that plus whatever the
        // source laid out: a blank line at the top level, and a SINGLE newline
        // for a list nested in an item, where a blank line would make the
        // holding list loose and wrap every item in a paragraph.
        return rtrim($buffer, " \t\n") . ($topLevel ? "\n\n" : "\n") . $block . "\n";
    }

    /**
     * Format one list's content as Carve list source.
     *
     * @param string $content The text between this list's own tags, with every nested list already formatted.
     * @param bool $ordered
     */
    protected function formatAsList(string $content, bool $ordered): string
    {
        if (preg_match_all('/\[\*\](.*?)(?=\[\*\]|\z)/is', $content, $matches) === 0) {
            // A list holding no item is not a list. Its text is kept, so
            // nothing the author wrote leaves with the tags.
            return trim($content);
        }

        $items = [];
        $loose = false;
        $counter = 1;
        foreach ($matches[1] as $raw) {
            $body = trim($raw);
            // A BLANK LINE INSIDE AN ITEM MAKES THE LIST LOOSE, and a loose
            // list parts its items with one too - otherwise the second item
            // abuts the first item's second paragraph and reads as part of it.
            if (preg_match('/\n[ \t]*\n/', $body) === 1) {
                $loose = true;
            }
            $items[] = $this->indentItemBody($ordered ? $counter++ . '. ' : '- ', $body);
        }

        return implode($loose ? "\n\n" : "\n", $items);
    }

    /**
     * Write one item, with its continuation lines at the item's CONTENT COLUMN.
     *
     * The item callback used to write the body as it stood, so every line after
     * the first landed at column 0. There a blank line ENDS THE LIST and the
     * `[*]` after it is just a line of the paragraph that follows, so a
     * two-item list came back as one item plus a paragraph that had swallowed
     * the second (markup-carve/carve-php#1623). PART 9 §11: a continuation line
     * belongs to the item when it reaches the item's content column, which is
     * where the marker ends.
     *
     * A blank line is written EMPTY rather than as the indent, because inside a
     * fence in that item the indent would be content rather than layout.
     *
     * @param string $marker
     * @param string $body
     */
    protected function indentItemBody(string $marker, string $body): string
    {
        $indent = str_repeat(' ', strlen($marker));
        $lines = explode("\n", $body);
        $out = rtrim($marker . (string)array_shift($lines));
        foreach ($lines as $line) {
            $out .= "\n" . (trim($line) === '' ? '' : $indent . $line);
        }

        return $out;
    }

    /**
     * Write every boundary sentinel out as the three blank lines it stands for.
     *
     * Runs after cleanup(), which is the pass the sentinel exists to survive.
     * The blank line the layout above left on either side is absorbed, so the
     * run is exactly three however the two lists were laid out.
     *
     * WHATEVER PREFIX ENDED UP TO THE SENTINEL'S LEFT is what the three lines
     * carry, rather than their being written as bare blank lines: inside a
     * block quote three EMPTY lines end the quote and drop the second list out
     * of it. The HTML importer's expansion answers the same question the same
     * way (markup-carve/carve-php#1598).
     *
     * @param string $text
     */
    protected function expandListBoundaries(string $text): string
    {
        if ($this->listBoundary === '' || !str_contains($text, $this->listBoundary)) {
            return $text;
        }

        $expanded = [];
        foreach (explode("\n", $text) as $line) {
            $at = strpos($line, $this->listBoundary);
            if ($at === false) {
                $expanded[] = $line;

                continue;
            }

            $prefix = rtrim(substr($line, 0, $at));
            if ($expanded !== [] && rtrim((string)end($expanded)) === $prefix) {
                array_pop($expanded);
            }
            $expanded[] = $prefix;
            $expanded[] = $prefix;
            $expanded[] = $prefix;
        }

        return implode("\n", $expanded);
    }

    protected function convertOther(string $text): string
    {
        // [hr] -> ---
        $text = preg_replace('/\[hr\]/i', "\n---\n", $text) ?? $text;

        // [center]...[/center] - no equivalent, strip tags
        $text = preg_replace('/\[center\](.*?)\[\/center\]/is', '$1', $text) ?? $text;

        // [left]...[/left] - no equivalent, strip tags
        $text = preg_replace('/\[left\](.*?)\[\/left\]/is', '$1', $text) ?? $text;

        // [right]...[/right] - no equivalent, strip tags
        $text = preg_replace('/\[right\](.*?)\[\/right\]/is', '$1', $text) ?? $text;

        // [spoiler]...[/spoiler] -> ::: spoiler\n...\n:::
        $text = preg_replace_callback(
            '/\[spoiler(?:=([^\]]+))?\](.*?)\[\/spoiler\]/is',
            function ($m) {
                $titleAttr = !empty($m[1]) ? '{title="' . trim($m[1]) . "\"}\n" : '';
                $content = trim($m[2]);

                return "{$titleAttr}::: spoiler\n{$content}\n:::\n";
            },
            $text,
        ) ?? $text;

        // [table]...[/table] - basic table conversion
        $text = $this->convertTables($text);

        // [youtube]ID[/youtube] -> ![](https://youtube.com/watch?v=ID)
        $text = preg_replace(
            '/\[youtube\]([a-zA-Z0-9_-]+)\[\/youtube\]/i',
            '![YouTube Video](https://www.youtube.com/watch?v=$1)',
            $text,
        ) ?? $text;

        // [sup]...[/sup] -> {^...^}. Forced brace form: BBCode tags are often
        // intraword (e.g. E=mc[sup]2[/sup]), where a bare ^2^ is literal.
        $text = preg_replace('/\[sup\](.*?)\[\/sup\]/is', '{^$1^}', $text) ?? $text;

        // [sub]...[/sub] -> {,...,}. Forced brace form for the same reason.
        $text = preg_replace('/\[sub\](.*?)\[\/sub\]/is', '{,$1,}', $text) ?? $text;

        return $text;
    }

    protected function convertTables(string $text): string
    {
        return preg_replace_callback(
            '/\[table\](.*?)\[\/table\]/is',
            function ($m) {
                $content = $m[1];
                $rows = [];

                // Extract rows
                preg_match_all('/\[tr\](.*?)\[\/tr\]/is', $content, $rowMatches);

                foreach ($rowMatches[1] as $row) {
                    // Check whether the row contains header cells ([th]) or body cells ([td]).
                    // A row is treated as a header row when it has at least one [th] cell.
                    $hasHeader = (bool)preg_match('/\[th\]/i', $row);

                    if ($hasHeader) {
                        // Extract [th] cells and emit Carve native |= header markers.
                        $cells = [];
                        preg_match_all('/\[th\](.*?)\[\/th\]/is', $row, $cellMatches);
                        foreach ($cellMatches[1] as $cell) {
                            $cells[] = '|= ' . trim($cell);
                        }

                        if ($cells) {
                            $rows[] = implode(' ', $cells) . ' |';
                        }
                    } else {
                        // Extract [td] cells and emit normal body rows.
                        $cells = [];
                        preg_match_all('/\[td\](.*?)\[\/td\]/is', $row, $cellMatches);
                        foreach ($cellMatches[1] as $cell) {
                            $cells[] = trim($cell);
                        }

                        if ($cells) {
                            $rows[] = '| ' . implode(' | ', $cells) . ' |';
                        }
                    }
                }

                // Ensure blank line before table for proper Carve block separation.
                return "\n\n" . implode("\n", $rows) . "\n\n";
            },
            $text,
        ) ?? $text;
    }

    protected function cleanup(string $text): string
    {
        // Remove any remaining BBCode closing tags [/tag]
        $text = preg_replace('/\[\/[a-z][a-z0-9]*\]/i', '', $text) ?? $text;

        // Remove remaining BBCode opening tags with = attribute [tag=value]
        $text = preg_replace('/\[[a-z][a-z0-9]*=[^\]]*\]/i', '', $text) ?? $text;

        // Normalize multiple blank lines
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        // Trim
        return trim($text) . "\n";
    }
}
