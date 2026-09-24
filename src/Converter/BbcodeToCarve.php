<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;

/**
 * Converts BBCode markup to Carve
 *
 * Useful for migrating forum content to Carve format.
 */
class BbcodeToCarve
{
    use ReportsMigrationFidelity;
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
     * Convert BBCode to Carve markup
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

        $codeStash = [];
        $djot = $this->stashCodeContent($djot, $codeStash);

        // The second one marks each link the link and image passes write, so the
        // formatting pass can tell those from a link the post's own brackets
        // formed once a tag beside them turned literal. It is stripped again
        // before the formatting pass returns.
        // Only a post with a link or image to convert needs the second one.
        if (preg_match('/\[(?:url|email|img)\b/i', $djot) === 1) {
            [$this->listBoundary, $this->writtenLink] = DocumentSentinels::pick($djot, 2, self::BOUNDARY_KEY);
        } else {
            [$this->listBoundary] = DocumentSentinels::pick($djot, 1, self::BOUNDARY_KEY);
            $this->writtenLink = '';
        }

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

    public function convertWithFidelityReport(string $bbcode): MigrationResult
    {
        return $this->unverifiedMigrationResult($this->convert($bbcode), 'bbcode');
    }

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

        $patterns = [
            '/(\[code(?:=[^\]]*)?\])(.*?)(\[\/code\])/is' => true,
            '/(\[(?:c|icode)\])(.*?)(\[\/(?:c|icode)\])/is' => false,
        ];
        foreach ($patterns as $pattern => $trim) {
            $text = preg_replace_callback($pattern, $protect($trim), $text) ?? $text;
        }

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

    /**
     * @var array<string, string>
     */
    protected const MARK_DELIMS = ['b' => '*', 'i' => '/', 'u' => '_', 's' => '~'];

    /**
     * @var int
     */
    protected const REPAIR_ROUNDS = 16;

    /**
     * Block tags a later pass turns into structure; `sup` and `sub` become a
     * braced span, which is just as closed to its neighbors.
     *
     * @var string
     */
    protected const LATER_BLOCK_TAGS = '/^(?:\*|quote|list|code|c|icode|sup|sub|center|left|right|youtube|table|tr|td|th|noparse)$/i';

    protected ?CarveConverter $repairConverter = null;

    protected string $writtenLink = '';

    protected function convertBasicFormatting(string $text): string
    {
        $text = $this->repairUnwrittenConstructs($this->writeMarks($this->flattenSameKind($this->parseMarks($text)), '', ''));
        if ($this->writtenLink !== '') {
            $text = str_replace($this->writtenLink, '', $text);
        }

        // Size [size=X]...[/size] - no direct equivalent, strip tags
        $text = preg_replace('/\[size=[^\]]*\](.*?)\[\/size\]/is', '$1', $text) ?? $text;

        // Color [color=X]...[/color] - no direct equivalent, strip tags
        $text = preg_replace('/\[color=[^\]]*\](.*?)\[\/color\]/is', '$1', $text) ?? $text;

        // Font [font=X]...[/font] - no direct equivalent, strip tags
        $text = preg_replace('/\[font=[^\]]*\](.*?)\[\/font\]/is', '$1', $text) ?? $text;

        return $text;
    }

    /**
     * The formatting tags as a tree. A close tag that does not match the
     * innermost open one stays literal text, and so does an open tag never
     * closed (marked, and unwound by flattenSameKind()).
     *
     * @return array<int, string|\MarkupCarve\Carve\Converter\BbcodeMark>
     */
    protected function parseMarks(string $text): array
    {
        $root = new BbcodeMark('', '');
        $stack = [$root];
        $from = 0;
        preg_match_all('/\[(\/?)(b|i|u|s)\]/i', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];
            $kind = strtolower($match[2][0]);
            $top = $stack[count($stack) - 1];
            $top->children[] = substr($text, $from, $offset - $from);
            $from = $offset + strlen($whole);
            if ($match[1][0] === '') {
                $node = new BbcodeMark($kind, $whole);
                $top->children[] = $node;
                $stack[] = $node;
            } elseif ($top->kind === $kind) {
                array_pop($stack);
            }
            // A close tag that matches no open one is dropped here rather than by
            // cleanup(): left in, it would be the content of the tag around it,
            // and that tag written as a pair around nothing once cleanup() took it.
        }
        $stack[count($stack) - 1]->children[] = substr($text, $from);
        foreach (array_slice($stack, 1) as $node) {
            $node->unclosed = true;
        }

        return $root->children;
    }

    /**
     * An unclosed tag becomes its literal text followed by its content. Carve
     * has no second level of one kind (E3), so a tag inside an open tag of its
     * own kind adds nothing and is replaced by its content. Iterative, because
     * nesting depth is the author's; with four kinds the tree left behind is at
     * most four deep, which keeps writeMarks() recursion bounded.
     *
     * @param array<int, string|\MarkupCarve\Carve\Converter\BbcodeMark> $pieces
     *
     * @return array<int, string|\MarkupCarve\Carve\Converter\BbcodeMark>
     */
    protected function flattenSameKind(array $pieces): array
    {
        $root = new BbcodeMark('', '');
        /** @var array<int, array{src: array<int, string|\MarkupCarve\Carve\Converter\BbcodeMark>, i: int, out: \MarkupCarve\Carve\Converter\BbcodeMark, open: array<string, true>}> $frames */
        $frames = [['src' => $pieces, 'i' => 0, 'out' => $root, 'open' => []]];
        while ($frames !== []) {
            $last = count($frames) - 1;
            if ($frames[$last]['i'] >= count($frames[$last]['src'])) {
                array_pop($frames);

                continue;
            }
            $frame = $frames[$last];
            $piece = $frame['src'][$frame['i']];
            $frames[$last]['i']++;
            if (is_string($piece)) {
                $frame['out']->children[] = $piece;
            } elseif ($piece->unclosed) {
                $frame['out']->children[] = $piece->open;
                $frames[] = ['src' => $piece->children, 'i' => 0, 'out' => $frame['out'], 'open' => $frame['open']];
            } elseif (isset($frame['open'][$piece->kind])) {
                $frames[] = ['src' => $piece->children, 'i' => 0, 'out' => $frame['out'], 'open' => $frame['open']];
            } else {
                $node = new BbcodeMark($piece->kind, $piece->open);
                $frame['out']->children[] = $node;
                $frames[] = ['src' => $piece->children, 'i' => 0, 'out' => $node, 'open' => $frame['open'] + [$piece->kind => true]];
            }
        }

        return $root->children;
    }

    /**
     * For each piece, the first character written after it, or '' when nothing
     * is. One pass from the right, so a run of empty tags costs nothing per tag.
     *
     * @param array<int, string|\MarkupCarve\Carve\Converter\BbcodeMark> $pieces
     *
     * @return array<int, string>
     */
    protected function markNextChars(array $pieces): array
    {
        $next = [];
        $after = '';
        for ($k = count($pieces) - 1; $k >= 0; $k--) {
            $next[$k] = $after;
            $piece = $pieces[$k];
            if (is_string($piece)) {
                if ($piece !== '') {
                    $after = $piece[0];
                }
            } elseif ($piece->hasContent()) {
                $after = '{';
            }
        }

        return $next;
    }

    /**
     * Write the tree the way the Carve writer would. An empty tag holds nothing
     * a reader sees and has no spelling, so it goes (ruling
     * markup-carve/carve-rs#1719). A bare pair the CARVE-P3-013 guards would not
     * read back, or that the writer would brace, takes the braced form, as does
     * a `/` around content that would read back as bold-italic.
     *
     * @param array<int, string|\MarkupCarve\Carve\Converter\BbcodeMark> $pieces
     * @param string $outerNext
     * @param string $prev
     *
     * @return array{text: string, marks: array<int, array{int, int}>} The text,
     *   and each written pair as [start, end) of the whole mark, in bytes.
     */
    protected function writeMarks(array $pieces, string $outerNext, string $prev): array
    {
        // Chunks rather than one growing string, so escaping the byte before an
        // opener rewrites only the text chunk holding it.
        $texts = [];
        $partMarks = [];
        $last = $prev;
        $textPart = -1;
        $escapeBrace = false;
        $nexts = $this->markNextChars($pieces);
        foreach ($pieces as $index => $piece) {
            if (is_string($piece)) {
                if ($piece === '') {
                    continue;
                }
                // A `{` before a bare opener and a `}` after its closer would read
                // as the braced form, eating both braces; the writer escapes the `}`.
                $chunk = $escapeBrace && str_starts_with($piece, '}') ? '\\' . $piece : $piece;
                $texts[] = $chunk;
                $partMarks[] = [];
                $last = $chunk[strlen($chunk) - 1];
                $escapeBrace = false;
                $textPart = count($texts) - 1;

                continue;
            }
            $delim = self::MARK_DELIMS[$piece->kind];
            // The parent's own delimiters are not text: a child at its edge has
            // no neighbor there, which is how the writer spells `/_x_/`.
            $inner = $this->writeMarks($piece->children, '', '');
            $body = $inner['text'];
            if ($body === '') {
                continue;
            }
            // The post's own text was escaped while the tags were still tags, so a
            // literal delimiter now touching an opener was never seen: escape it.
            if ($textPart >= 0 && $textPart === count($texts) - 1 && str_contains('*/_~=', $last)) {
                $chunk = $texts[$textPart];
                // Already escaped only behind an ODD run of backslashes.
                $run = 0;
                $at = strlen($chunk) - 2;
                while ($at - $run >= 0 && $chunk[$at - $run] === '\\') {
                    $run++;
                }
                if ($run % 2 === 0) {
                    $texts[$textPart] = substr($chunk, 0, -1) . '\\' . $last;
                }
            }
            $before = $last;
            $next = $nexts[$index] !== '' ? $nexts[$index] : $outerNext;
            $braced = preg_match('/^[ \t\r\n]|[ \t\r\n]$/', $body) === 1
                || preg_match('/^[A-Za-z0-9_]$/', $before) === 1
                || $before === $delim
                || ($before === '/' && ($delim === '/' || $delim === '_'))
                // `#_x_` is a hashtag, `@_x_` a mention, `:_x_:` a symbol.
                || ($delim === '_' && ($before === '#' || $before === '@' || $before === ':'))
                || preg_match('/^[A-Za-z0-9_]$/', $next) === 1
                || str_starts_with($body, $delim)
                || str_ends_with($body, $delim)
                || ($delim === '/' && str_starts_with($body, '*') && str_ends_with($body, '*'));
            $open = $braced ? '{' . $delim : $delim;
            $close = $braced ? $delim . '}' : $delim;
            $chunk = $open . $body . $close;
            $marks = [[0, strlen($chunk)]];
            foreach ($inner['marks'] as [$from, $to]) {
                $marks[] = [strlen($open) + $from, strlen($open) + $to];
            }
            $texts[] = $chunk;
            $partMarks[] = $marks;
            $last = $chunk[strlen($chunk) - 1];
            $escapeBrace = !$braced && $before === '{';
        }
        $text = '';
        $marks = [];
        foreach ($texts as $k => $chunk) {
            foreach ($partMarks[$k] as [$from, $to]) {
                $marks[] = [strlen($text) + $from, strlen($text) + $to];
            }
            $text .= $chunk;
        }

        return ['text' => $text, 'marks' => $marks];
    }

    /**
     * The post's text was escaped while the tags were still tags, so once they
     * are delimiters a literal character can combine with them, or with text a
     * dropped tag brought together, into a construct nobody wrote: `#[/i]x`
     * reads as the hashtag `#x`, and `~}[s]x[/s]` as a strikethrough of `}`.
     * Rather than predict every such construct, parse the result and escape the
     * first character of each one that is not a written pair, until none is
     * left. A written pair that closes early was closed by a literal delimiter
     * inside it, and that delimiter is the one escaped.
     *
     * @param array{text: string, marks: array<int, array{int, int}>} $written
     */
    protected function repairUnwrittenConstructs(array $written): string
    {
        $text = $written['text'];
        $marks = $written['marks'];
        if ($this->writtenTextNeedsNoRepairParse($text, $marks)) {
            return $text;
        }
        for ($round = 0; $round < self::REPAIR_ROUNDS; $round++) {
            [$copy, $origin] = $this->asLaterPassesLeaveIt($text);
            $bytes = $this->codepointBytes($copy);
            $index = static fn (int $offset): int => $origin[$bytes[$offset] ?? strlen($copy)];
            $pairs = [];
            foreach ($marks as [$from, $to]) {
                $pairs[$from] = $to;
            }
            $escapeAt = [];
            $stack = $this->repairParser()->parse($copy)->getChildren();
            while ($stack !== []) {
                $node = array_pop($stack);
                foreach ($node->getChildren() as $child) {
                    $stack[] = $child;
                }
                $pos = $node->getPos();
                if (!$node instanceof InlineNode || $pos === null) {
                    continue;
                }
                $at = $index($pos->startOffset);
                if ($node instanceof Strong || $node instanceof Emphasis || $node instanceof Underline || $node instanceof Strike) {
                    if (!isset($pairs[$at])) {
                        $escapeAt[$at] = true;
                    } else {
                        $end = $index($pos->endOffset - 1) + 1;
                        if ($end < $pairs[$at]) {
                            $escapeAt[$end - 1] = true;
                        }
                    }
                } elseif ($this->isUnwritten($node)) {
                    $escapeAt[$at] = true;
                } elseif ($node instanceof Link || $node instanceof Image) {
                    // The link and image passes mark what they write; any other
                    // link was formed by the post's own brackets once a tag beside
                    // them turned literal (`[x[b](y)`), or is a reference link,
                    // which as an unresolved one shows its label raw.
                    $mark = strlen($this->writtenLink);
                    if ($mark === 0 || $at < $mark || substr($text, $at - $mark, $mark) !== $this->writtenLink) {
                        $escapeAt[$node instanceof Image ? $at + 1 : $at] = true;
                    }
                }
            }
            if ($escapeAt === []) {
                break;
            }
            $cuts = array_keys($escapeAt);
            sort($cuts);
            $out = '';
            $copied = 0;
            foreach ($cuts as $at) {
                $out .= substr($text, $copied, $at - $copied) . '\\';
                $copied = $at;
            }
            $text = $out . substr($text, $copied);
            // Each offset moves by the number of escapes inserted before it.
            $shift = static function (int $k) use ($cuts): int {
                $lo = 0;
                $hi = count($cuts);
                while ($lo < $hi) {
                    $mid = ($lo + $hi) >> 1;
                    if ($cuts[$mid] < $k) {
                        $lo = $mid + 1;
                    } else {
                        $hi = $mid;
                    }
                }

                return $k + $lo;
            };
            $marks = array_map(static fn (array $mark): array => [$shift($mark[0]), $shift($mark[1])], $marks);
        }

        return $text;
    }

    /**
     * Prove the common case without asking the full parser to prove it again.
     *
     * writeMarks() has already chosen a safe spelling for every generated pair.
     * If everything else is words, whitespace, or escaped ASCII punctuation,
     * there is no remaining Carve opener that a removed or converted tag could
     * have manufactured. Anything less obvious keeps the parser repair path.
     *
     * @param string $text
     * @param array<int, array{int, int}> $marks
     */
    protected function writtenTextNeedsNoRepairParse(string $text, array $marks): bool
    {
        $generated = [];
        foreach ($marks as [$from, $to]) {
            if ($text[$from] === '{') {
                $generated[$from] = true;
                $generated[$from + 1] = true;
                $generated[$to - 2] = true;
                $generated[$to - 1] = true;
            } else {
                $generated[$from] = true;
                $generated[$to - 1] = true;
            }
        }

        $length = strlen($text);
        for ($at = 0; $at < $length; $at++) {
            if (isset($generated[$at])) {
                continue;
            }
            $byte = ord($text[$at]);
            if ($byte >= 0x80 || ctype_alnum($text[$at]) || str_contains(" \t\r\n", $text[$at])) {
                continue;
            }
            if ($text[$at] === '\\' && $at + 1 < $length && !isset($generated[$at + 1])) {
                $escaped = ord($text[$at + 1]);
                if ($escaped >= 0x21 && $escaped <= 0x7E && !ctype_alnum($text[$at + 1])) {
                    $at++;

                    continue;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Inline constructs bbcode has no way to ask for at this stage. Links,
     * images and autolinks were converted before it, as inline links; a
     * reference link or image came from the post's own brackets, and an
     * unresolved one shows its label raw, backslashes and all.
     */
    protected function isUnwritten(Node $node): bool
    {
        // A mention or hashtag is a Link subclass here, and never written.
        if ($node instanceof Mention) {
            return true;
        }
        if ($node instanceof Link || $node instanceof Image) {
            return false;
        }

        return !($node instanceof Text || $node instanceof RawText || $node instanceof EscapedText
            || $node instanceof SoftBreak || $node instanceof HardBreak || $node instanceof SmartPunctuation);
    }

    /**
     * The text as the later passes will leave it around inline content, so the
     * repair parse sees the neighbors the reader will: a close tag or a valued
     * open tag is deleted, which joins the text either side of it, and a block
     * tag becomes structure, which separates it. A formatting tag left unclosed,
     * or one this pass does not know, stays the literal text it is.
     *
     * @return array{string, array<int, int>} The copy, and for each of its bytes
     *   (and one past its end) the byte in `$text` it came from.
     */
    protected function asLaterPassesLeaveIt(string $text): array
    {
        $copy = '';
        $origin = [];
        $from = 0;
        preg_match_all('/\[(\/?)(\*|[a-z][a-z0-9]*)(=[^\]\n]*)?\]/i', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$whole, $at] = $match[0];
            if ($at > 0 && $text[$at - 1] === '\\') {
                continue;
            }
            $removed = $match[1][0] !== '' || (isset($match[3]) && $match[3][1] >= 0);
            if (!$removed && preg_match(self::LATER_BLOCK_TAGS, $match[2][0]) !== 1) {
                continue;
            }
            for ($k = $from; $k < $at; $k++) {
                $origin[] = $k;
            }
            $copy .= substr($text, $from, $at - $from);
            if (!$removed) {
                $width = strlen($whole);
                for ($k = 0; $k < $width; $k++) {
                    $origin[] = $at + $k;
                }
                $copy .= str_repeat("\x01", $width);
            }
            $from = $at + strlen($whole);
        }
        $length = strlen($text);
        for ($k = $from; $k < $length; $k++) {
            $origin[] = $k;
        }
        $copy .= substr($text, $from);
        $origin[] = strlen($text);

        return [$copy, $origin];
    }

    /**
     * Byte offset of each codepoint offset in `$text`, plus one past the end.
     *
     * @return array<int, int>
     */
    protected function codepointBytes(string $text): array
    {
        $bytes = [];
        $length = strlen($text);
        for ($k = 0; $k < $length; $k++) {
            if ((ord($text[$k]) & 0xC0) !== 0x80) {
                $bytes[] = $k;
            }
        }
        $bytes[] = $length;

        return $bytes;
    }

    protected function repairParser(): CarveConverter
    {
        if ($this->repairConverter === null) {
            $this->repairConverter = new CarveConverter();
            $this->repairConverter->getParser()->enablePositionTracking();
        }

        return $this->repairConverter;
    }

    protected function convertLinks(string $text): string
    {
        // [url=http://...]text[/url] -> [text](url)
        $text = preg_replace(
            '/\[url=([^\]]+)\](.*?)\[\/url\]/is',
            $this->writtenLink . '[$2]($1)',
            $text,
        ) ?? $text;

        // [url]http://...[/url] -> <url> (autolink)
        $text = preg_replace(
            '/\[url\](.*?)\[\/url\]/is',
            $this->writtenLink . '<$1>',
            $text,
        ) ?? $text;

        // [email]...[/email] -> <mailto:...>
        $text = preg_replace(
            '/\[email\](.*?)\[\/email\]/is',
            $this->writtenLink . '<mailto:$1>',
            $text,
        ) ?? $text;

        return $text;
    }

    protected function convertImages(string $text): string
    {
        // [img]url[/img] -> ![](url)
        $text = preg_replace(
            '/\[img\](.*?)\[\/img\]/is',
            $this->writtenLink . '![]($1)',
            $text,
        ) ?? $text;

        // [img=WxH]url[/img] -> ![](url)
        $text = preg_replace(
            '/\[img=[^\]]*\](.*?)\[\/img\]/is',
            $this->writtenLink . '![]($1)',
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
            // Copied up to the next `[` in one step: the `\G` matches below
            // scan ahead for a `[` before failing, O(n) per byte (#2214).
            if ($text[$i] !== '[') {
                $next = strpos($text, '[', $i);
                $end = $next === false ? $length : $next;
                $contents[$top] .= substr($text, $i, $end - $i);
                $i = $end;

                continue;
            }

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
     * Format content as a Carve blockquote.
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

        // Ensure blank line before blockquote for proper Carve block separation
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
            // Copied up to the next `[` in one step: the `\G` matches below
            // scan ahead for a `[` before failing, O(n) per byte (#2214).
            if ($text[$i] !== '[') {
                $next = strpos($text, '[', $i);
                $end = $next === false ? $length : $next;
                $contents[$top] .= substr($text, $i, $end - $i);
                $i = $end;

                continue;
            }

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
        // intraword (e.g. E=mc[sup]2[/sup]), where a bare ^2^ is literal. An
        // empty one has no spelling and goes (ruling markup-carve/carve-rs#1719).
        $text = preg_replace_callback(
            '/\[sup\](.*?)\[\/sup\]/is',
            fn (array $m): string => $m[1] === '' ? '' : '{^' . $m[1] . '^}',
            $text,
        ) ?? $text;

        // [sub]...[/sub] -> {,...,}. Forced brace form for the same reason.
        $text = preg_replace_callback(
            '/\[sub\](.*?)\[\/sub\]/is',
            fn (array $m): string => $m[1] === '' ? '' : '{,' . $m[1] . ',}',
            $text,
        ) ?? $text;

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
