<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

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

        $djot = $bbcode;

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

        // Links and images first (before basic formatting escapes brackets)
        $djot = $this->convertLinks($djot);
        $djot = $this->convertImages($djot);

        // Basic formatting
        $djot = $this->convertBasicFormatting($djot);

        // Code blocks and inline code
        $djot = $this->convertCode($djot);

        // Quotes
        $djot = $this->convertQuotes($djot);

        // Lists
        $djot = $this->convertLists($djot);

        // Other elements
        $djot = $this->convertOther($djot);

        // Clean up
        $djot = $this->cleanup($djot);

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

        $protect = function (array $match) use (&$stash, $open, $close): string {
            $stash[] = $match[2];

            return $match[1] . $open . (count($stash) - 1) . $close . $match[3];
        };

        $patterns = [
            '/(\[code(?:=[^\]]*)?\])(.*?)(\[\/code\])/is',
            '/(\[(?:c|icode)\])(.*?)(\[\/(?:c|icode)\])/is',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, $protect, $text) ?? $text;
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
        $dropTags = function (array $match) use (&$stash, $open, $close): string {
            $stash[] = $match[1];

            return $open . (count($stash) - 1) . $close;
        };

        return preg_replace_callback('/\[noparse\](.*?)\[\/noparse\]/is', $dropTags, $text) ?? $text;
    }

    /**
     * Put the code content back, after every pass that could rewrite it.
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

        return preg_replace_callback(
            '/' . preg_quote($open, '/') . '(\d+)' . preg_quote($close, '/') . '/u',
            fn (array $match): string => $stash[(int)$match[1]],
            $text,
        ) ?? $text;
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
        // [code=lang]...[/code] -> ```lang\n...\n```
        $text = preg_replace_callback(
            '/\[code=([^\]]+)\](.*?)\[\/code\]/is',
            // Neutralize a leading `=` in the [code=..] language so untrusted
            // Bbcode cannot mint a Carve `=html` raw-HTML block (live HTML under
            // the default renderer). `[code= =html]` -> inert ```html block.
            fn ($m) => "\n\n```" . ltrim(ltrim(strtolower(trim($m[1])), '=')) . "\n" . trim($m[2]) . "\n```\n\n",
            $text,
        ) ?? $text;

        // [code]...[/code] -> ```\n...\n```
        $text = preg_replace_callback(
            '/\[code\](.*?)\[\/code\]/is',
            fn ($m) => "\n\n```\n" . trim($m[1]) . "\n```\n\n",
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
        $quoted = array_map(fn ($line) => '> ' . $line, $lines);

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

    protected function convertLists(string $text): string
    {
        // Ordered list [list=1]...[/list]
        $text = preg_replace_callback(
            '/\[list=1\](.*?)\[\/list\]/is',
            function ($m) {
                $content = $m[1];
                $counter = 1;
                $content = preg_replace_callback(
                    '/\[\*\](.*?)(?=\[\*\]|\z)/is',
                    function ($item) use (&$counter) {
                        $text = trim($item[1]);

                        return $counter++ . '. ' . $text . "\n";
                    },
                    $content,
                );

                // Ensure blank line before list for proper Djot block separation
                return "\n\n" . $content . "\n";
            },
            $text,
        ) ?? $text;

        // Unordered list [list]...[/list]. Alternate the bullet marker per
        // list so that two adjacent lists stay distinct in Carve (same-marker
        // lists separated only by a blank line merge into one).
        $bulletIndex = 0;
        $text = preg_replace_callback(
            '/\[list\](.*?)\[\/list\]/is',
            function ($m) use (&$bulletIndex) {
                $marker = $bulletIndex % 2 === 0 ? '-' : '*';
                $bulletIndex++;
                $content = $m[1];
                $content = preg_replace_callback(
                    '/\[\*\](.*?)(?=\[\*\]|\z)/is',
                    function ($item) use ($marker) {
                        $text = trim($item[1]);

                        return $marker . ' ' . $text . "\n";
                    },
                    $content,
                );

                // Ensure blank line before list for proper Carve block separation
                return "\n\n" . $content . "\n";
            },
            $text,
        ) ?? $text;

        return $text;
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
