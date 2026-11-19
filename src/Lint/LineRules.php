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
        if (preg_match('/^(?:[ \t]*>[ \t]*)+/', $line->text, $match) === 1) {
            $at = strlen($match[0]);

            return [Support::trimEnd(substr($line->text, $at)), $line->start + $at];
        }

        return self::trimmed($line);
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
        $text = self::afterQuote($line)[0];
        if (!self::isRun($text, '-_', 3)) {
            return;
        }
        $previous = self::prior($line, $c);
        if ($text[0] === '-' && $previous !== null && !self::blank($previous) && $previous->paragraph) {
            return;
        }
        [$text, $start] = self::afterQuote($line);
        $raw = self::shown($line, $c, $start - $line->start, strlen($text));
        $c->report('thematic-break-spelling', $start, $start + strlen($text), $line->path, ['raw' => $raw]);
    }

    private static function setextHeading(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        if (
            (self::isRun(self::trimmed($line)[0], '-', 3) || self::isRun(self::trimmed($line)[0], '=', 3))
            && $previous !== null && $previous->paragraph && !self::blank($previous)
        ) {
            self::reportLine('setext-heading-underline', $line, $c);
        }
    }

    private static function spacedBreak(Line $line, Context $c): void
    {
        if (self::isSpacedRun(self::trimmed($line)[0])) {
            self::reportLine('deprecated-spaced-thematic-break', $line, $c);
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
        [$text, $start] = self::trimmed($line);
        if (!str_starts_with($text, '[') || !str_ends_with($text, ']:') || strlen($text) < 4) {
            return;
        }
        $label = substr($text, 1, -2);
        if (strpos($text, ']') === strlen($text) - 2 && Support::trim($label) !== '' && $label !== '^') {
            $c->report('empty-reference-definition', $start, $start + strlen($text), $line->path, [], str_starts_with($label, '^') ? 1 : 0);
        }
    }

    private static function blankReference(Line $line, Context $c): void
    {
        if (preg_match_all('/\[\^?[ \t]+\]/', $line->text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return;
        }
        foreach ($matches[0] as [$text, $at]) {
            $c->report('blank-reference-label', $line->start + $at, $line->start + $at + strlen($text), $line->path, [
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
        $c->report('blockquote-marker-without-space', $start, $start + strlen($text), $line->path, ['text' => $value]);
    }

    public static function frontmatterQuote(Line $line, Context $c): void
    {
        if (($line->text[0] ?? '') !== '>' || strlen($line->text) < 2 || $line->text[1] === ' ') {
            return;
        }
        $value = substr($line->text, 1);
        $c->report('blockquote-marker-without-space', $line->start, $line->start + 1, $line->path, ['text' => $value], 1);
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
        foreach (['# ', '= ', '> ', ':: ', ': ', '{.'] as $opener) {
            if (str_starts_with($text, $opener)) {
                return true;
            }
        }

        return str_starts_with($text, '[') && str_contains($text, ']:');
    }

    private static function openerWithoutBlank(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        if ($previous === null || !$line->paragraph || $previous->path !== $line->path || self::blank($previous)) {
            return;
        }
        [$text, $start] = self::trimmed($line);
        if (!self::startsBlock($text) || (str_starts_with($text, '{') && !str_ends_with($text, '}'))) {
            return;
        }
        if (str_starts_with($text, '[') && self::hasAncestor($line->path, $c, 'footnote')) {
            return;
        }
        if (str_starts_with($text, '> ') && str_starts_with(Support::trimStart($previous->text), '> ')) {
            return;
        }
        $opener = match (true) {
            str_starts_with($text, '# '), str_starts_with($text, '= ') => 'a heading',
            str_starts_with($text, '> ') => 'a quote',
            str_starts_with($text, '{') => 'an attribute line',
            str_starts_with($text, ':: ') => 'a term',
            str_starts_with($text, ': ') => 'a description',
            default => 'a definition',
        };
        $markerLength = str_starts_with($text, '{') ? strlen($text) : (str_starts_with($text, ':: ') ? 2 : 1);
        if ($opener === 'a definition') {
            $close = strpos($text, ']:');
            $markerLength = $close === false ? 1 : $close + 2;
        }
        $c->report('block-opener-without-blank-line', $start, $start + $markerLength, $line->path, ['opener' => $opener]);
    }

    private static function listWithoutBlank(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        if ($previous === null || !$line->paragraph || $previous->path !== $line->path || self::blank($previous)) {
            return;
        }
        [$text, $start] = self::afterQuote($line);
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
        [$text, $start] = self::trimmed($line);
        if (!str_starts_with($text, '^ ') || !$line->paragraph || self::hasAncestor($line->path, $c, 'caption')) {
            return;
        }
        $previous = self::prior($line, $c);
        // C2-B-149: below any other block the line is text; below a definition, which is no block, it is left alone.
        if ($previous !== null && !$previous->paragraph) {
            $lead = Support::trimStart($previous->text);
            if (str_starts_with($lead, '[') || str_starts_with($lead, '*[')) {
                return;
            }
        }
        $c->report('unattached-caption', $start, $start + strlen($text), $line->path);
    }

    private static function unattachedAttribute(Line $line, Context $c): void
    {
        [$text, $start] = self::trimmed($line);
        if (!$line->paragraph || !str_starts_with($text, '{') || !str_ends_with($text, '}') || strlen($text) < 3) {
            return;
        }
        $second = $text[1];
        if ($second !== '.' && $second !== '#' && !($second >= 'a' && $second <= 'z')) {
            return;
        }
        if (isset($c->attachedLines[$line->number])) {
            return;
        }
        $next = $c->lines[$line->number] ?? null;
        if ($next !== null && !self::blank($next) && !$next->paragraph) {
            return;
        }
        $c->report('unattached-block-attribute', $start, $start + strlen($text), $line->path, [], self::blank($next) ? 1 : 0);
    }

    private static function markdownDefinition(Line $line, Context $c): void
    {
        [$text, $start] = self::trimmed($line);
        $previous = self::prior($line, $c);
        if (
            $line->paragraph && !self::hasAncestor($line->path, $c, 'definition_description') && str_starts_with($text, ': ')
            && ($previous === null || !self::blank($previous))
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
        $indented = ($line->text[0] ?? '') === ' ' || ($line->text[0] ?? '') === "\t";
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
        ) {
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
            $below = $below || ($next !== null && str_starts_with(Support::trimStart($next->text), '|'));
            $above = $above || ($prev !== null && (str_starts_with(Support::trimStart($prev->text), '|')
                || str_starts_with(Support::trimStart($prev->text), '![')));
        }
        if ($below || $above) {
            $c->report('caption-looking-paragraph', $start, $start + 1, $line->path, [], $below ? 0 : 1);
        }
    }

    private static function descriptionColumn(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        [$text, $start] = self::trimmed($line);
        if ($previous === null || (!str_starts_with($text, ': ') && !str_starts_with($text, ':: '))) {
            return;
        }
        while ($previous !== null && self::blank($previous) && $previous->number > 1) {
            $previous = $c->lines[$previous->number - 2] ?? null;
        }
        if ($previous === null) {
            return;
        }
        [$host, $hostStart] = self::trimmed($previous);
        if (!str_starts_with($host, ': ') && !str_starts_with($host, ':: ')) {
            return;
        }
        $hostContent = $hostStart - $previous->start + (str_starts_with($host, '::') ? 3 : 2);
        $column = $start - $line->start;
        if ($column === $hostStart - $previous->start || $column >= $hostContent) {
            return;
        }
        $c->report('description-separator-below-content-column', $start, $start + strlen($text), $line->path, [
            'name' => str_starts_with($text, '::') ? 'term' : 'description',
            'column' => (string)($start - $line->start + 1),
            'host.column' => (string)($hostStart - $previous->start + 1),
        ]);
    }

    private static function rowWithoutPipe(Line $line, Context $c): void
    {
        [$text, $start] = self::trimmed($line);
        if ($line->paragraph && str_starts_with($text, '|') && !str_ends_with($text, '|')) {
            $c->report('table-row-without-closing-pipe', $start, $start + strlen($text), $line->path);
        }
    }
}
