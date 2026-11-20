<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Closure;
use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;

/**
 * The line rules of carve-js `spelling.ts`, in its order. A line arrives with its opaque leaves masked by U+0000 bytes.
 */
final class LineRules
{
    /**
     * @var array<array{0: string, 1: \Closure}>|null
     */
    private static ?array $all = null;

    /**
     * @return array<array{0: string, 1: \Closure}>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $rules = [
            'deprecated-continuation-marker' => 'deprecatedContinuation',
            'thematic-break-spelling' => 'thematicBreak',
            'setext-heading-underline' => 'setextHeading',
            'deprecated-spaced-thematic-break' => 'spacedBreak',
            'block-marker-as-text' => 'tildeFence',
            'html-comment-renders-as-text' => 'htmlComment',
            'bare-html-tag' => 'bareHtml',
            'empty-reference-definition' => 'emptyReference',
            'blank-reference-label' => 'blankReference',
            'table-shaped-paragraph' => 'tableShaped',
            'blockquote-marker-without-space' => 'quoteWithoutSpace',
            'block-opener-without-blank-line' => 'openerWithoutBlank',
            'list-marker-without-blank-line' => 'listWithoutBlank',
            'indented-code-block' => 'indentedCode',
            'unattached-caption' => 'unattachedCaption',
            'unattached-block-attribute' => 'unattachedAttribute',
            'markdown-definition-paragraph' => 'markdownDefinition',
            'empty-include-path' => 'emptyInclude',
            'link-gap-before-destination' => 'linkGap',
            'djot-plus-bullet' => 'djotPlus',
            'definition-in-container' => 'definitionInContainer',
            'definition-term-block-folded' => 'foldedTerm',
            'github-alert-marker' => 'githubAlert',
            'djot-heading-continuation' => 'djotHeadingContinuation',
            'deprecated-indented-line-under-heading' => 'indentedHeadingContinuation',
            'quote-fence-ends-the-quote-above' => 'quoteFenceSibling',
            'region-marker-renders-as-text' => 'regionAsText',
            'caption-looking-paragraph' => 'captionLooking',
            'description-separator-below-content-column' => 'descriptionColumn',
            'table-row-without-closing-pipe' => 'rowWithoutPipe',
        ];
        self::$all = [];
        foreach ($rules as $id => $method) {
            self::$all[] = [$id, Closure::fromCallable([self::class, $method])];
        }

        return self::$all;
    }

    /**
     * The text of a line without leading spaces and tabs and without trailing whitespace, and where it starts.
     *
     * @param \MarkupCarve\Carve\Lint\Line $line
     *
     * @return array{0: string, 1: int}
     */
    private static function trimmed(Line $line): array
    {
        $at = strspn($line->text, " \t");

        return [Support::trimEnd(substr($line->text, $at)), $line->start + $at];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function afterQuote(Line $line): array
    {
        // C2-B-031: a quote mark is `>` and a space; a glued `>` is text.
        if (preg_match('/^(?:[ \t]*> [ \t]*)+/', $line->text, $match) === 1) {
            $at = strlen($match[0]);

            return [Support::trimEnd(substr($line->text, $at)), $line->start + $at];
        }

        return self::trimmed($line);
    }

    /**
     * The text of the line behind its container prefixes (C2-B-027): quote marks, item, description and footnote markers
     * and indentation, as the tree places the line's content.
     *
     * @return array{0: string, 1: int}
     */
    private static function lineText(Line $line): array
    {
        if ($line->content === null || $line->content < $line->start || $line->content > $line->end) {
            return self::afterQuote($line);
        }

        return [Support::trimEnd(substr($line->text, $line->content - $line->start)), $line->content];
    }

    /**
     * A spaced run parses as nested list markers, so it reaches back from the line's text over its own markers.
     *
     * @return array{0: string, 1: int}
     */
    private static function spacedText(Line $line): array
    {
        if ($line->content === null) {
            return self::afterQuote($line);
        }
        [$own, $start] = self::lineText($line);
        $marker = $own[0] ?? '';
        $at = $start - $line->start;
        while ($at > 0 && in_array($line->text[$at - 1], [' ', "\t", $marker], true)) {
            $at--;
        }
        $at += strspn($line->text, " \t", $at);

        return [Support::trimEnd(substr($line->text, $at)), $line->start + $at];
    }

    private static function quoteDepth(Line $line): int
    {
        return preg_match('/^(?:[ \t]*>[ \t]*)+/', $line->text, $match) === 1
            ? substr_count($match[0], '>') : 0;
    }

    /**
     * The text behind the line's own quote marks: C2-B-031 makes a mark `>` followed by a space or the line's end.
     *
     * @return array{0: string, 1: int}
     */
    private static function afterQuotes(Line $line, int $depth): array
    {
        $at = 0;
        for ($i = 0; $i < $depth; $i++) {
            $mark = $at + strspn($line->text, " \t", $at);
            if (($line->text[$mark] ?? '') !== '>' || !in_array($line->text[$mark + 1] ?? '', ['', ' '], true)) {
                break;
            }
            $at = $mark + (($line->text[$mark + 1] ?? '') === ' ' ? 2 : 1);
        }
        $at += strspn($line->text, " \t", $at);

        return [Support::trimEnd(substr($line->text, $at)), $line->start + $at];
    }

    /**
     * @return array{0: string, 1: int, 2: \MarkupCarve\Carve\Lint\Line}|null
     */
    private static function openerContent(Line $line, Context $c): ?array
    {
        $previous = self::prior($line, $c);
        if ($previous === null || !$line->paragraph || $previous->path !== $line->path || self::blank($previous)) {
            return null;
        }
        // C2-B-019, C2-B-200: a candidate is a line of a paragraph or caption, not a later line of a term.
        if (!in_array($c->nodeTypes[$line->path] ?? null, ['paragraph', 'caption', 'cell_paragraph'], true)) {
            return null;
        }
        // C2-B-024, C2-B-025: a lazy line's content starts at its first marker, a quote mark included (carve#6178).
        [$text, $start] = self::lineText($line);

        return [$text, $start, $previous];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function paragraphContent(Line $line, Context $c): array
    {
        $start = $c->paragraphStarts[$line->path] ?? null;
        if ($start !== null && $start >= $line->start && $start < $line->end) {
            return [Support::trimEnd(substr($line->text, $start - $line->start)), $start];
        }

        return self::trimmed($line);
    }

    /**
     * The text of a line from `$from` as a message shows it: a masked character is one U+0000 per byte here and one per
     * UTF-16 unit in carve-js.
     *
     * @param \MarkupCarve\Carve\Lint\Line $line
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param int $from Offset in the line.
     * @param int $length
     *
     * @return string
     */
    private static function shown(Line $line, Context $c, int $from, int $length): string
    {
        $masked = substr($line->text, $from, $length);
        if (!str_contains($masked, "\0")) {
            return $masked;
        }
        $original = substr($c->lines[$line->number - 1]->text ?? '', $from, $length);
        $out = '';
        $n = strlen($masked);
        for ($i = 0; $i < $n; $i++) {
            $byte = ord($original[$i] ?? "\0");
            if ($masked[$i] !== "\0" || $byte === 0) {
                $out .= $masked[$i];
            } elseif (($byte & 0xC0) !== 0x80) {
                $out .= $byte >= 0xF0 ? "\0\0" : "\0";
            }
        }

        return $out;
    }

    private static function blank(?Line $line): bool
    {
        return $line !== null && Support::trim($line->text) === '';
    }

    /**
     * C2-W-143: how a line reads for the rules inside `$depth` quotes: blank when only spaces and tabs follow their quote
     * marks, outside when it lacks one of them, so those quotes ended above it, and text otherwise.
     */
    private static function blankness(?Line $line, int $depth): string
    {
        if ($line === null) {
            return 'outside';
        }
        $at = 0;
        for (; $depth > 0; $depth--) {
            $mark = $at + strspn($line->text, " \t", $at);
            if (($line->text[$mark] ?? '') !== '>' || !in_array($line->text[$mark + 1] ?? '', ['', ' '], true)) {
                return 'outside';
            }
            $at = $mark + 1;
        }

        return trim(substr($line->text, $at), " \t") === '' ? 'blank' : 'text';
    }

    private static function prior(Line $line, Context $c): ?Line
    {
        return $c->lines[$line->number - 2] ?? null;
    }

    /**
     * @param string $id
     * @param \MarkupCarve\Carve\Lint\Line $line
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param array<string, string> $values
     *
     * @return void
     */
    private static function reportLine(string $id, Line $line, Context $c, array $values = []): void
    {
        [$text, $start] = self::trimmed($line);
        $c->report($id, $start, $start + strlen($text), $line->path, $values);
    }

    private static function hasAncestor(string $path, Context $c, string $type): bool
    {
        $current = $path;
        while ($current !== '') {
            $split = strrpos($current, '/children/');
            if ($split === false) {
                return false;
            }
            $current = substr($current, 0, $split);
            if (($c->nodeTypes[$current] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    private static function isRun(string $text, string $chars, int $minimum): bool
    {
        if (strlen($text) < $minimum || !str_contains($chars, $text[0])) {
            return false;
        }

        return strspn($text, $text[0]) === strlen($text);
    }

    private static function isSpacedRun(string $text): bool
    {
        if ($text === '' || !str_contains('*-_', $text[0])) {
            return false;
        }
        $count = substr_count($text, $text[0]);
        $spaces = strlen($text) - $count;

        return strspn($text, $text[0] . " \t") === strlen($text) && $count >= 3 && $spaces > 0;
    }

    private static function deprecatedContinuation(Line $line, Context $c): void
    {
        $pattern = '/^(?:[ \t]*>[ \t]*)*[ \t]*(?:(?<marker>[-*]|\d+[.)]|[A-Za-z][.)])[ \t]+)?(?<plus>\+)[ \t]*$/';
        if (preg_match($pattern, $line->text, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === 1) {
            $offset = $match['plus'][1];
            $markerLine = $match['marker'][0] !== null;
            $variant = $markerLine ? 2 : (self::hasAncestor($line->path, $c, 'list_item') ? 1 : 0);
            $c->report('deprecated-continuation-marker', $line->start + $offset, $line->start + $offset + 1, $line->path, [], $variant);
        }
    }

    private static function thematicBreak(Line $line, Context $c): void
    {
        [$text, $start] = self::lineText($line);
        if (!self::isRun($text, '-_', 3)) {
            return;
        }
        $previous = self::prior($line, $c);
        if (
            $text[0] === '-' && $previous !== null && !self::blank($previous)
            && ($c->nodeTypes[$previous->path] ?? null) === 'paragraph'
        ) {
            return;
        }
        $raw = self::shown($line, $c, $start - $line->start, strlen($text));
        $c->report('thematic-break-spelling', $start, $start + strlen($text), $line->path, ['raw' => $raw]);
    }

    private static function setextHeading(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        [$text, $start] = self::afterQuote($line);
        if (
            (self::isRun($text, '-', 3) || self::isRun($text, '=', 3))
            && $previous !== null && ($c->nodeTypes[$previous->path] ?? null) === 'paragraph' && !self::blank($previous)
        ) {
            $c->report('setext-heading-underline', $start, $start + strlen($text), $line->path);
        }
    }

    private static function spacedBreak(Line $line, Context $c): void
    {
        [$text, $start] = self::spacedText($line);
        if (self::isSpacedRun($text)) {
            $c->report('deprecated-spaced-thematic-break', $start, $start + strlen($text), $line->path);
        }
    }

    /**
     * C2-B-105: one or more attribute blocks and only spaces after them.
     *
     * @param string $text
     *
     * @return bool
     */
    private static function isAttributeLine(string $text): bool
    {
        $n = strlen($text);
        $p = 0;
        while ($p < $n && $text[$p] === '{') {
            $next = AttributeParser::read($text, $p, $n, new Attrs());
            if ($next <= $p) {
                break;
            }
            $p = $next;
        }

        return $p > 0 && $p + strspn($text, " \t", $p) === $n;
    }

    private static function tildeFence(Line $line, Context $c): void
    {
        if (!$line->paragraph) {
            return;
        }
        [$text, $start] = self::paragraphContent($line, $c);
        if (str_starts_with($text, ':::') && strspn($text, ':') >= 3) {
            $c->report('block-marker-as-text', $start, $start + strlen($text), $line->path, ['what' => 'a div opener']);

            return;
        }
        if (str_starts_with($text, '~~~')) {
            $c->report('block-marker-as-text', $start, $start + strlen($text), $line->path, ['what' => 'a tilde fence']);

            return;
        }
        $second = $text[1] ?? '';
        $opens = $second === '.' || $second === '#' || $second === '_' || ($second !== '' && ctype_alpha($second));
        if (str_starts_with($text, '{') && $opens && !self::isAttributeLine($text)) {
            $c->report('block-marker-as-text', $start, $start + strlen($text), $line->path, ['what' => 'an attribute line']);
        }
    }

    private static function htmlComment(Line $line, Context $c): void
    {
        $at = strpos($line->text, '<!--');
        if ($at === false || !$line->paragraph || ($at > 0 && $line->text[$at - 1] === '\\')) {
            return;
        }
        $end = strpos($line->text, '-->', $at + 4);
        if ($end !== false) {
            $c->report('html-comment-renders-as-text', $line->start + $at, $line->start + $end + 3, $line->path);

            return;
        }
        for ($number = $line->number + 1; isset($c->lines[$number - 1]); $number++) {
            $next = $c->lines[$number - 1];
            if ($next->path !== $line->path) {
                break;
            }
            $end = strpos($next->text, '-->');
            if ($end !== false) {
                $c->report('html-comment-renders-as-text', $line->start + $at, $next->start + $end + 3, $line->path);

                break;
            }
        }
    }

    private static function bareHtml(Line $line, Context $c): void
    {
        if (!$line->paragraph) {
            return;
        }
        $from = 0;
        while (($at = strpos($line->text, '<', $from)) !== false) {
            $from = $at + 1;
            if (($at > 0 && $line->text[$at - 1] === '\\') || substr_compare($line->text, '<!--', $at, 4) === 0) {
                continue;
            }
            $after = $line->text[$at + 1] ?? '';
            if ($after === '' || !(ctype_alpha($after) || $after === '/')) {
                continue;
            }
            $end = strpos($line->text, '>', $at + 2);
            if ($end === false) {
                continue;
            }
            $inside = substr($line->text, $at + 1, $end - $at - 1);
            if (str_contains($inside, '://') || str_contains($inside, '@') || str_starts_with($inside, '/#')) {
                continue;
            }
            $c->report('bare-html-tag', $line->start + $at, $line->start + $end + 1, $line->path);

            return;
        }
    }

    private static function emptyReference(Line $line, Context $c): void
    {
        [$text, $start] = self::lineText($line);
        if (!str_starts_with($text, '[') || !str_ends_with($text, ']:') || strlen($text) < 4) {
            return;
        }
        $label = substr($text, 1, -2);
        if (strpos($text, ']') === strlen($text) - 2 && Support::trim($label) !== '' && $label !== '^') {
            $c->report('empty-reference-definition', $start, $start + strlen($text), $line->path, [], str_starts_with($label, '^') ? 1 : 0);
        }
    }

    /**
     * C2-I-079: a footnote label, a full reference's label or a definition label of only spaces and tabs; a task box
     * and a bracket pair with no reference glued to it are no label.
     */
    private static function blankReference(Line $line, Context $c): void
    {
        if (!str_contains($line->text, '[')) {
            return;
        }
        [$leaf, $from] = self::paragraphContent($line, $c);
        if (str_starts_with(Support::trimStart($leaf), '> ')) {
            [$leaf, $from] = self::afterQuotes($line, $c->layout->quoteDepth($line->path));
        }
        if (preg_match_all('/\[\^[ \t]+\]|(?<=\])\[[ \t]+\]|^\[[ \t]+\]:/', $leaf, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return;
        }
        foreach ($matches[0] as [$text, $at]) {
            $c->report('blank-reference-label', $from + $at, $from + $at + strlen($text), $line->path, [
                'text' => $text,
            ]);
        }
    }

    private static function tableShaped(Line $line, Context $c): void
    {
        [$text, $start] = self::paragraphContent($line, $c);
        $closing = str_ends_with($text, '|');
        if ($closing) {
            $before = substr($text, 0, -1);
            $slashes = strlen($before) - strlen(rtrim($before, '\\'));
            $closing = $slashes % 2 === 0;
        }
        $owner = $c->nodeTypes[$line->path] ?? 'paragraph';
        if (strlen($text) > 1 && str_starts_with($text, '|') && !$closing && ($line->paragraph || $owner === 'heading')) {
            $c->report('table-shaped-paragraph', $start, $start + strlen($text), $line->path, ['owner' => $owner]);
        }
    }

    private static function quoteWithoutSpace(Line $line, Context $c): void
    {
        [$text, $start] = self::trimmed($line);
        if ($start !== $line->start || !str_starts_with($text, '>') || strlen($text) < 2 || $text[1] === ' ') {
            return;
        }
        $value = self::shown($line, $c, $start - $line->start + 1, strlen($text) - 1);
        $end = $start + strlen(rtrim(substr($line->text, $start - $line->start), " \t"));
        $c->report('blockquote-marker-without-space', $start, $end, $line->path, ['text' => $value]);
    }

    public static function frontmatterQuote(Line $line, Context $c): void
    {
        if (($line->text[0] ?? '') !== '>' || strlen($line->text) < 2 || $line->text[1] === ' ') {
            return;
        }
        $value = substr($line->text, 1);
        $end = $line->start + strlen(rtrim($line->text, " \t"));
        $c->report('blockquote-marker-without-space', $line->start, $end, $line->path, ['text' => $value], 1);
    }

    /**
     * A comment inside a quote or item ends when its owner ends. The parser may still mark a later physical line opaque;
     * recover the two spelling rules whose candidates begin on that escaped line.
     */
    public static function escapedOpaque(Line $line, Context $c): void
    {
        $startLine = $c->blockCommentStartLines[$line->number] ?? null;
        if (
            $startLine === null || $startLine === $line->number
            || (!preg_match('/^>[^ ]/', $line->text) && preg_match('/<[A-Za-z\/]/', $line->text) !== 1)
        ) {
            return;
        }
        $opener = $c->lines[$startLine - 1] ?? null;
        if ($opener === null || preg_match('/%{3,}/', $opener->text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return;
        }
        $before = substr($opener->text, 0, $match[0][1]);
        $quoted = preg_match('/^(?:[ \t]*> )+/', $before) === 1;
        $listed = preg_match('/(?:^|[ \t])(?:[-*]|[0-9A-Za-z]+[.)])[ \t]+/', $before) === 1;
        $prior = $c->lines[$startLine - 2] ?? null;
        $nested = strspn($opener->text, " \t") > 0 && $prior !== null
            && self::hasAncestor($prior->path, $c, 'list_item')
            && preg_match('/(?:^|[ \t])(?:[-*]|[0-9A-Za-z]+[.)])[ \t]+.*%{3,}/', $prior->text) === 1;
        if (!$quoted && !$listed && !$nested) {
            return;
        }
        if (($quoted && str_starts_with($line->text, '> ')) || (($listed || $nested) && strspn($line->text, " \t") > 0)) {
            return;
        }
        $visible = new Line($line->text, $line->start, $line->end, $line->number, '', false, true);
        if ($c->active('blockquote-marker-without-space')) {
            self::quoteWithoutSpace($visible, $c);
        }
        if ($c->active('bare-html-tag')) {
            self::bareHtml($visible, $c);
        }
    }

    private static function isListMarker(string $text): bool
    {
        if (strlen($text) < 2) {
            return false;
        }
        if (str_contains('-*+', $text[0]) && $text[1] === ' ') {
            return true;
        }
        $at = min(9, strspn($text, '0123456789'));

        return $at > 0 && (($text[$at] ?? '') === '.' || ($text[$at] ?? '') === ')') && ($text[$at + 1] ?? '') === ' ';
    }

    private static function startsBlock(string $text): bool
    {
        foreach (['> ', ':: ', ': '] as $opener) {
            if (str_starts_with($text, $opener)) {
                return true;
            }
        }
        // Any valid attribute line, spaced or with an id or a key (C2-B-090, carve#6197).
        if (str_starts_with($text, '{') && self::isAttributeLine($text)) {
            return true;
        }

        return self::isHeading($text) || (str_starts_with($text, '[') && str_contains($text, ']:'));
    }

    /**
     * C2-B-151, C2-B-152: one to six `=` or `#` and a space.
     */
    private static function isHeading(string $text): bool
    {
        $run = strspn($text, ($text[0] ?? '') === '#' ? '#' : '=');

        return $run >= 1 && $run <= 6 && ($text[$run] ?? '') === ' ';
    }

    private static function openerWithoutBlank(Line $line, Context $c): void
    {
        $content = self::openerContent($line, $c);
        if ($content === null) {
            return;
        }
        [$text, $start, $previous] = $content;
        if (!self::startsBlock($text)) {
            $original = $c->lines[$line->number - 1] ?? $line;
            [$unmasked, $unmaskedStart] = self::lineText($original);
            if (str_starts_with($unmasked, '> ')) {
                [$text, $start] = [$unmasked, $unmaskedStart];
            }
        }
        if (!self::startsBlock($text) || (str_starts_with($text, '{') && !str_ends_with($text, '}'))) {
            return;
        }
        if (str_starts_with($text, '[') && self::hasAncestor($line->path, $c, 'footnote')) {
            return;
        }
        // A lazy line carries no mark of the quote before its content, so its own `>` is the candidate (carve#6178).
        $prefix = $line->content === null ? '>' : substr($line->text, 0, max(0, $line->content - $line->start));
        $ownMark = str_contains($prefix, '>');
        if (
            str_starts_with($text, '> ') && $ownMark && str_starts_with(Support::trimStart($previous->text), '> ')
            && self::hasAncestor($line->path, $c, 'block_quote')
        ) {
            return;
        }
        $opener = match (true) {
            self::isHeading($text) => 'a heading',
            str_starts_with($text, '> ') => 'a quote',
            str_starts_with($text, '{') => 'an attribute line',
            str_starts_with($text, ':: ') => 'a term',
            str_starts_with($text, ': ') => 'a description',
            default => 'a definition',
        };
        $width = self::openerWidth($text, $opener);
        $c->report('block-opener-without-blank-line', $start, $start + $width, $line->path, ['opener' => $opener]);
    }

    /**
     * The catalog's range: the marker, the whole attribute block, or the bracketed label and colon.
     */
    private static function openerWidth(string $text, string $opener): int
    {
        if ($opener === 'a heading') {
            return strspn($text, $text[0]);
        }
        if ($opener === 'a definition') {
            $close = strpos($text, ']:');

            return $close === false ? 1 : $close + 2;
        }

        return str_starts_with($text, '{') ? strlen($text) : (str_starts_with($text, ':: ') ? 2 : 1);
    }

    private static function listWithoutBlank(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        if ($previous === null || !$line->paragraph || $previous->path !== $line->path || self::blank($previous)) {
            return;
        }
        [$text, $start] = self::afterQuotes($line, $c->layout->quoteDepth($line->path));
        if (!str_starts_with($text, '+ ') && self::isListMarker($text)) {
            $owner = $c->nodeTypes[$line->path] ?? null;
            $variant = in_array($owner, ['definition_term', 'definition_description'], true) ? 1 : 0;
            $c->report('list-marker-without-blank-line', $start, $start + strlen($text), $line->path, [], $variant);
        }
    }

    private static function indentedCode(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        if (
            $line->paragraph && $line->number > 1 && self::blank($previous)
            && ($c->firstLines[$line->path] ?? null) === $line->number
            && count(explode('/', $line->path)) <= 3 && str_starts_with($line->text, '    ') && !self::blank($line)
        ) {
            self::reportLine('indented-code-block', $line, $c);
        }
    }

    private static function unattachedCaption(Line $line, Context $c): void
    {
        [$text, $start] = self::paragraphContent($line, $c);
        if (!str_starts_with($text, '^ ')) {
            [$text, $start] = self::afterQuotes($line, $c->layout->quoteDepth($line->path));
        }
        if (!str_starts_with($text, '^ ') || !$line->paragraph || self::hasAncestor($line->path, $c, 'caption')) {
            return;
        }
        $c->report('unattached-caption', $start, $start + strlen($text), $line->path);
    }

    private static function unattachedAttribute(Line $line, Context $c): void
    {
        if (!$line->paragraph || !str_contains($line->text, '{')) {
            return;
        }
        $first = ($c->firstLines[$line->path] ?? null) === $line->number;
        if (!$first && ($c->nodeTypes[$line->path] ?? null) === 'paragraph') {
            return;
        }
        if (!$first && ($c->nodeTypes[$line->path] ?? null) === 'definition_term') {
            return;
        }
        // Only a block's first line starts behind container prefixes; on a later line a `> ` or marker is text.
        [$text, $start] = $first ? self::paragraphContent($line, $c) : self::trimmed($line);
        if (
            !str_starts_with($text, '{') || !str_ends_with($text, '}') || strlen($text) < 3
            || !self::isAttributeLine($text)
        ) {
            return;
        }
        if (isset($c->attachedLines[$line->number])) {
            return;
        }
        $next = $c->lines[$line->number] ?? null;
        $blank = self::blankness($next, $c->layout->quoteDepth($line->path));
        if (
            $next !== null && $blank === 'text' && !$next->paragraph
            && preg_match('/^[ \t]*(?:> )*[ \t]*:{3,}[ \t]*$/', $next->text) !== 1
        ) {
            return;
        }
        $c->report('unattached-block-attribute', $start, $start + strlen($text), $line->path, [], $blank === 'blank' ? 1 : 0);
    }

    private static function markdownDefinition(Line $line, Context $c): void
    {
        if (!$line->paragraph || !str_contains($line->text, ': ')) {
            return;
        }
        [$text, $start] = self::afterQuotes($line, $c->layout->quoteDepth($line->path));
        $previous = self::prior($line, $c);
        // C2-B-074: a later paragraph line below a line that is no `::` term.
        if (
            ($c->nodeTypes[$line->path] ?? null) === 'paragraph' && str_starts_with($text, ': ')
            && $previous !== null && $previous->path === $line->path && !self::blank($previous)
            && ($c->firstLines[$line->path] ?? $line->number) < $line->number
            && !str_starts_with(self::paragraphContent($previous, $c)[0], ':: ')
            && self::belowListColumn($line, $c) === null
            && !str_starts_with(self::afterQuotes($previous, $c->layout->quoteDepth($line->path))[0], ':: ')
        ) {
            $c->report('markdown-definition-paragraph', $start, $start + strlen($text), $line->path);
        }
    }

    private static function emptyInclude(Line $line, Context $c): void
    {
        $at = strpos($line->text, '{{');
        if ($at === false) {
            return;
        }
        $end = strpos($line->text, '}}', $at + 2);
        if ($end === false) {
            return;
        }
        $body = substr($line->text, $at + 2, $end - $at - 2);
        $empty = Support::trim($body) === '' || preg_match('/^[ \t]+(?:""|“”|[\#@])/', $body) === 1;
        if (!$empty) {
            return;
        }
        $text = self::shown($line, $c, $at, $end + 2 - $at);
        $c->report('empty-include-path', $line->start + $at, $line->start + $end + 2, $line->path, ['text' => $text]);
    }

    private static function linkGap(Line $line, Context $c): void
    {
        if (!$line->paragraph) {
            return;
        }
        if (preg_match_all('/\[[^\]\n]+\][ \t]+\([ \t]*[^)\s]/', $line->text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return;
        }
        foreach ($matches[0] as [$match, $at]) {
            if ($at > 0 && $line->text[$at - 1] === '\\') {
                continue;
            }
            $c->report('link-gap-before-destination', $line->start + $at, $line->start + $at + strlen($match), $line->path);
        }
    }

    private static function djotPlus(Line $line, Context $c): void
    {
        [$text, $start] = self::paragraphContent($line, $c);
        if (str_starts_with($text, '>') && str_contains($line->text, '>')) {
            [$text, $start] = self::afterQuote($line);
        }
        if ($line->paragraph && preg_match('/^\+[ \t]+\S/', $text) === 1) {
            $c->report('djot-plus-bullet', $start, $start + strlen($text), $line->path);
        }
    }

    private static function definitionInContainer(Line $line, Context $c): void
    {
        if (!str_contains($line->text, ']:')) {
            return;
        }
        [$text, $start] = self::paragraphContent($line, $c);
        if (!str_starts_with($text, '[') && str_contains($line->text, '>')) {
            [$text, $start] = self::afterQuote($line);
        }
        $close = strpos($text, ']:');
        if (!$line->paragraph || !str_starts_with($text, '[') || $close === false || $close <= 1 || $close + 2 >= strlen($text)) {
            return;
        }
        if (self::hasAncestor($line->path, $c, 'footnote')) {
            return;
        }
        $previous = self::prior($line, $c);
        $indented = ($line->text[0] ?? '') === ' ' || ($line->text[0] ?? '') === "\t";
        if (
            ($c->nodeTypes[$line->path] ?? null) === 'definition_term'
            && ($c->firstLines[$line->path] ?? $line->number) < $line->number
            && !$indented
        ) {
            return;
        }
        if (
            $previous !== null && $previous->path === $line->path
            && $c->layout->quoteDepth($line->path) === 0
            && preg_match('/^[ \t]*> /', $line->text) === 1
        ) {
            return;
        }
        if (!$indented && count(explode('/', $line->path)) <= 3) {
            return;
        }
        $c->report('definition-in-container', $start, $start + $close + 2, $line->path);
    }

    private static function foldedTerm(Line $line, Context $c): void
    {
        if (($c->nodeTypes[$line->path] ?? null) !== 'definition_term' || ($c->firstLines[$line->path] ?? null) !== $line->number) {
            return;
        }
        [$text, $start] = self::trimmed($line);
        if (!str_starts_with($text, ':: ')) {
            return;
        }
        $term = substr($text, 3);
        if (str_starts_with($term, '> ') || self::isListMarker($term)) {
            $c->report('definition-term-block-folded', $start + 3, $start + 4, $line->path);
        }
    }

    private static function githubAlert(Line $line, Context $c): void
    {
        if (!str_contains($line->text, '[!')) {
            return;
        }
        if (preg_match('/^[ \t]*>:/', $line->text) === 1) {
            return;
        }
        if (preg_match('/^[ \t]*>(?: |\[!)/', $line->text) !== 1) {
            return;
        }
        $at = strpos($line->text, '[!');
        if ($at === false || !str_contains(substr($line->text, $at), ']') || !str_starts_with(Support::trimStart($line->text), '>')) {
            return;
        }
        if (preg_match('/^\[!(?:NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]/', substr($line->text, $at)) !== 1) {
            return;
        }
        $c->report('github-alert-marker', $line->start + $at, $line->end, $line->path);
    }

    private static function djotHeadingContinuation(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        if ($previous === null || !$line->paragraph || self::blank($line)) {
            return;
        }
        if (str_starts_with($previous->text, '# ') && !str_starts_with($line->text, ' ')) {
            self::reportLine('djot-heading-continuation', $previous, $c);
        }
    }

    private static function indentedHeadingContinuation(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        if (
            $previous === null || !$line->paragraph || !str_starts_with($line->text, ' ')
            || ($c->nodeTypes[$previous->path] ?? null) !== 'heading'
            || self::quoteDepth($previous) !== self::quoteDepth($line)
        ) {
            return;
        }
        $marker = $c->layout->startOffset($previous->path);
        if ($marker === null) {
            return;
        }
        $quotes = $c->layout->quoteDepth($previous->path);
        $indent = strspn($line->text, " \t>");
        if ($c->layout->columnOf($line->start + $indent, $quotes) <= $c->layout->columnOf($marker, $quotes)) {
            return;
        }
        $c->report('deprecated-indented-line-under-heading', $line->start, $line->end, $line->path);
    }

    private static function quoteFenceSibling(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        if ($previous === null || !str_starts_with(self::trimmed($line)[0], '::: >')) {
            return;
        }
        if (!str_starts_with(Support::trimStart($previous->text), '>')) {
            return;
        }
        self::reportLine('quote-fence-ends-the-quote-above', $line, $c);
    }

    private static function regionAsText(Line $line, Context $c): void
    {
        $original = $c->lines[$line->number - 1] ?? $line;
        [$text, $start] = self::trimmed($original);
        if (str_starts_with($text, '%%')) {
            if (
                preg_match('/^%%[ \t]*#(?:region|endregion)(?![A-Za-z0-9_])/i', $text) === 1
                && preg_match('/^%%[ \t]*#(?:region|endregion)(?![A-Za-z0-9_])/', $text) !== 1
            ) {
                $c->report('region-marker-renders-as-text', $start, $original->end, $line->path, [], 1);
            }

            return;
        }
        $candidate = preg_replace('/^(?:(?:\/\/|--|\/\*|<!--)[ \t]*)?/', '', $text);
        if (is_string($candidate) && preg_match('/^#(?:region|endregion)(?![A-Za-z0-9_])/i', $candidate) === 1) {
            $c->report('region-marker-renders-as-text', $start, $original->end, $line->path);
        }
    }

    private static function captionLooking(Line $line, Context $c): void
    {
        if (!$line->paragraph || str_contains($line->path, '/caption/')) {
            return;
        }
        [$text, $start] = self::paragraphContent($line, $c);
        if (preg_match('/^\*?\s*(?:Caption|Table|Tabelle|Figure|Image)(?:\s+\d+)?\s*:|^:\s+\S/i', $text) !== 1) {
            return;
        }
        $below = false;
        $above = false;
        for ($distance = 1; $distance <= 2; $distance++) {
            $next = $c->lines[$line->number - 1 + $distance] ?? null;
            $prev = $c->lines[$line->number - 1 - $distance] ?? null;
            $below = $below || ($next !== null && str_starts_with(self::neighborContent($next, $c->layout->quoteDepth($line->path)), '|'));
            $above = $above || ($prev !== null && (str_starts_with(self::neighborContent($prev, $c->layout->quoteDepth($line->path)), '|')
                || str_contains($prev->text, '![')));
        }
        if ($below || $above) {
            $c->report('caption-looking-paragraph', $start, $start + 1, $line->path, [], $below ? 0 : 1);
        }
    }

    private static function neighborContent(Line $line, int $depth): string
    {
        $text = self::afterQuotes($line, $depth)[0];

        return preg_replace('/^(?:[-*] |[0-9]+[.)] )[ \t]*/', '', $text) ?? $text;
    }

    private static function descriptionColumn(Line $line, Context $c): void
    {
        $found = self::belowListColumn($line, $c);
        if ($found === null) {
            return;
        }
        [$text, $start, $column, $host] = $found;
        $c->report('description-separator-below-content-column', $start, $start + strlen($text), $line->path, [
            'name' => str_starts_with($text, '::') ? 'term' : 'description',
            'column' => (string)($column + 1),
            'host.column' => (string)($host + 1),
        ]);
    }

    /**
     * C2-B-067, C2-B-071, C2-B-074: a colon mark that opens no entry, below a description list whose first term mark
     * stands further right; columns count tab stops (C2-B-004).
     *
     * @return array{0: string, 1: int, 2: int, 3: int}|null
     */
    private static function belowListColumn(Line $line, Context $c): ?array
    {
        if (isset($c->descriptionEntryLines[$line->number])) {
            return null;
        }
        if (!str_contains($line->text, ':')) {
            return null;
        }
        $depth = $c->layout->quoteDepth($line->path);
        [$text, $start] = self::afterQuotes($line, $depth);
        if (preg_match('/^::?(?:[ \t]|$)/', $text) !== 1 || self::opensEntry($line, $c)) {
            return null;
        }
        $host = self::listColumnAbove($line, $depth, $c);
        $column = $c->layout->columnOf($start, $depth);

        return $host !== null && $host > $column ? [$text, $start, $column, $host] : null;
    }

    private static function opensEntry(Line $line, Context $c): bool
    {
        if (($c->nodeTypes[$line->path] ?? null) === 'definition_term' && ($c->firstLines[$line->path] ?? null) === $line->number) {
            return true;
        }
        $description = self::ancestorOf($line->path, $c, 'definition_description');

        return $description !== null && ($c->firstLines[$description] ?? null) === $line->number;
    }

    /**
     * The column of the first term mark of the description list the nearest non-blank line above at the same quote
     * depth lies in.
     */
    private static function listColumnAbove(Line $line, int $depth, Context $c): ?int
    {
        for ($at = $line->number - 2; $at >= 0; $at--) {
            $host = $c->lines[$at] ?? null;
            if ($host === null || self::blank($host) || $c->layout->quoteDepth($host->path) !== $depth) {
                continue;
            }
            $list = self::ancestorOf($host->path, $c, 'definition_list');
            $start = $list === null ? null : $c->layout->startOffset($list);

            return $list === null || $start === null ? null : $c->layout->columnOf($start, $c->layout->quoteDepth($list));
        }

        return null;
    }

    private static function ancestorOf(string $path, Context $c, string $type): ?string
    {
        $current = $path;
        while ($current !== '') {
            if (($c->nodeTypes[$current] ?? null) === $type) {
                return $current;
            }
            $split = strrpos($current, '/children/');
            if ($split === false) {
                return null;
            }
            $current = substr($current, 0, $split);
        }

        return null;
    }

    private static function rowWithoutPipe(Line $line, Context $c): void
    {
        [$text, $start] = self::trimmed($line);
        if ($line->paragraph && str_starts_with($text, '|') && !str_ends_with($text, '|')) {
            $c->report('table-row-without-closing-pipe', $start, $start + strlen($text), $line->path);
        }
    }
}
