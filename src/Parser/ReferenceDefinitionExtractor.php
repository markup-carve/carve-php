<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\LinkDestination;
use MarkupCarve\Carve\Util\StringUtil;

class ReferenceDefinitionExtractor
{
    /**
     * The inline parser answers ONE question here: is a trailing `{...}` a
     * valid attribute block? §14's rule is that a single invalid name
     * invalidates the WHOLE block, and it is the inline parser that knows it -
     * a second copy of the predicate would drift from the one every other
     * attribute site uses.
     */
    public function __construct(private ?InlineParser $inlineParser = null)
    {
    }

    /**
     * Is a `: ` line here actually a definition list's DESCRIPTION?
     *
     * Only when a term opened the entry above it. A description line with no
     * term above it is not a description at all - it is paragraph text, and the
     * definition-shaped content in it defines nothing (corpus
     * `216-a-description-line-needs-a-term-above-it`). Without this test the
     * marker was stripped from every `: ` line and a definition in a bare one
     * was collected, which is the opposite of what 216 pins.
     *
     * The previous line is enough to decide it: an entry is opened by a `::`
     * term and continued by a further `: ` description.
     *
     * @param string $previousLine The line above the one being tested
     *
     * @return bool
     */
    public static function opensDefinitionEntry(string $previousLine): bool
    {
        $trimmed = ltrim($previousLine, " \t");
        while (true) {
            $before = $trimmed;
            $trimmed = ContainerPrefix::quoteContent($trimmed) ?? $trimmed;
            $trimmed = preg_replace('/^(?:[-*]|[0-9]+[.)]) +(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/', '', $trimmed) ?? $trimmed;
            $trimmed = ltrim($trimmed, " \t");
            if ($trimmed === $before) {
                break;
            }
        }

        return preg_match('/^::(?!:)[ \t]/', $trimmed) === 1
            || preg_match('/^:[ \t]/', $trimmed) === 1;
    }

    /**
     * Read a line as a reference definition, ANCHORED AT END OF LINE.
     *
     * @param string $line
     *
     * @return array{label: string, url: string, title: string|null, attrs: array<string, string>}|null
     */
    public function matchDefinitionLine(string $line): ?array
    {
        // The production ends at its first newline. List lazy-continuation can
        // store several physical source lines in one parser entry; letting the
        // `/s` matcher cross that boundary turned `[d]: ` plus the next line
        // into a definition whose destination came from that next line, and
        // both lines then disappeared from the item.
        if (str_contains($line, "\n") || str_contains($line, "\r")) {
            return null;
        }

        // `[^…]:` with a NON-EMPTY label is a footnote definition and takes
        // precedence, so it is excluded here. `[^]:` is not: `footnote_label`
        // is one-or-more characters, so an empty label never forms a footnote
        // definition and the line falls through to a reference definition with
        // the label `^` - which `reference_label` admits, being neither `]`
        // nor `@`. Excluding every `[^` left that line as paragraph text, where
        // carve-js and carve-rs both render nothing.
        if (($line[0] ?? '') !== '[' || preg_match('/^\[(?!@)(?!\^[^\]]+\]:)([^\]]+)\]: [ \t]*(.*)$/s', $line, $matches) !== 1) {
            return null;
        }

        // Preserve the authored spelling on the definition. The caller keys it
        // with LabelKey so links, images and definitions share one algorithm.
        $label = $matches[1];

        // The LEADING side of the destination is trimmed and the trailing side
        // is not, which is the anchor's whole point. A Unicode space before the
        // destination is padding the separator did not name and the destination
        // does not start with (corpus 121 pins `[a]: <U+202F>javascript:…`
        // resolving, with the scheme probe emptying the href); the same
        // character AFTER the destination is content, and content after the
        // production is what the anchor rejects.
        $tail = self::ltrimUnicodeWhitespace($matches[2]);

        // `link_destination` reads to the first whitespace, and it reads the
        // braces of `[a]: /u{.c}` along with everything else - which is why that
        // line is still a definition with `href="/u{.c}"` and is a DIFFERENT
        // SHAPE from `[a]: /u {.c}` rather than another spelling of it.
        if (preg_match('/^([^\p{Z}\x{0009}-\x{000D}\x{0085}]+)(.*)$/us', $tail, $dm) !== 1) {
            return null;
        }
        // The run still has to BE a `link_destination`: a parenthesis reaches
        // one only through `balanced_parens` or `destination_escape`, so
        // `[a]: a(b` and `[a]: a)b` leave content over and the anchor below
        // disposes of them like any other leftover.
        $url = LinkDestination::value($dm[1]);
        if ($url === null) {
            return null;
        }
        $rest = $dm[2];

        // EXACTLY ONE SPACE before the quoted title, and it is a SPACE. This is
        // `link_title`, the same production the inline form reads, and PART 7's
        // cardinality paragraph names it among the four slots spelled `space`
        // (markup-carve/carve#912). Both narrowings are visible only because the
        // line is anchored: while the tail swallowed the remainder,
        // `[a]: /u<TAB>"T"` merely lost its title instead of failing.
        $title = null;
        if (
            preg_match(
                '/^ (?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\')(.*)$/s',
                $rest,
                $tm,
                PREG_UNMATCHED_AS_NULL,
            ) === 1
        ) {
            $title = AttributeParser::processEscapes(($tm[1] ?? null) !== null ? $tm[1] : (string)$tm[2]);
            $rest = (string)$tm[3];
        }

        $attrs = [];
        if (($rest[0] ?? '') === ' ' && ($rest[1] ?? '') === '{') {
            $parsed = $this->readTrailingAttributes(substr($rest, 1));
            if ($parsed === null) {
                return null;
            }
            $attrs = $parsed;
            $rest = '';
        }

        // THE ANCHOR. `whitespace` is a space or a tab, so anything else here -
        // a word, a quote the title slot refused, a no-break space - fails the
        // production and the line is an ordinary paragraph.
        if (preg_match('/^[ \t]*$/', $rest) !== 1) {
            return null;
        }

        return [
            'label' => $label,
            'url' => $url,
            'title' => $title,
            'attrs' => $attrs,
        ];
    }

    /**
     * Read the definition's trailing attribute block, which must end the line.
     *
     * @param string $tail The line from its `{` to its end.
     *
     * @return non-empty-array<string, string>|null
     */
    private function readTrailingAttributes(string $tail): ?array
    {
        $length = strlen($tail);
        $quote = null;
        for ($j = 1; $j < $length; $j++) {
            $char = $tail[$j];
            if ($char === '\\' && $j + 1 < $length) {
                $j++;

                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char !== '}') {
                continue;
            }

            // The block must END the line - only `whitespace` may follow it,
            // which is the anchor applied one token early rather than a second
            // rule.
            if (preg_match('/^[ \t]*$/', substr($tail, $j + 1)) !== 1) {
                return null;
            }
            $payload = substr($tail, 1, $j - 1);
            // The ORDERED parser: `parse()` hoists `class` to the front
            // regardless of where the author wrote it, and these attributes are
            // applied to a link in array order, so the hoist would reorder the
            // rendered attributes of every link resolving the label. The inline
            // path already preserves source order; this has to match it.
            // `[space, attributes]` in the production is the INLINE block
            // (PART 4, markup-carve/carve#906), not the attribute LINE.
            if ($this->inlineParser !== null && !$this->inlineParser->isValidInlineAttrPayload($payload)) {
                // One invalid name invalidates the whole block (§14), exactly as
                // it does on a block-attribute line and inline - so `{#}` and
                // `{.a\}b}` are not `attributes`. The block is handed BACK as
                // content, and the anchor makes the line prose.
                return null;
            }
            $parsed = AttributeParser::parseOrderedWithSlots($payload);
            $attrs = $parsed['attributes'];
            if ($attrs === []) {
                // A payload that IS space-only, and so passes the check above,
                // still yields no `attribute` - and `attribute_list` needs one.
                // `{}` and `{ }` land here.
                return null;
            }
            $ordered = [];
            foreach ($parsed['order'] as $slot) {
                $key = match ($slot) {
                    '.class' => 'class',
                    '#id' => 'id',
                    default => $slot,
                };
                if (isset($attrs[$key])) {
                    $ordered[$key] = $attrs[$key];
                }
            }

            return $ordered === [] ? $attrs : $ordered;
        }

        return null;
    }

    /**
     * Strip Unicode whitespace from the destination's LEADING side only.
     *
     * `trim()` only knows ASCII, which left invisible characters at the front
     * of a link destination - the spoofing shape the scheme probe exists to
     * catch (carve#352, carve#404). Zero-width characters (U+200B, U+FEFF) are
     * not whitespace and are deliberately preserved.
     *
     * The TRAILING side used to be stripped by the same call and is not any
     * more: after the destination a Unicode space is content, and content after
     * the production is what the end-of-line anchor rejects
     * (markup-carve/carve#911).
     */
    private static function ltrimUnicodeWhitespace(string $value): string
    {
        $trimmed = preg_replace('/^[\p{Z}\x{0009}-\x{000D}\x{0085}]+/u', '', $value);

        return $trimmed ?? ltrim($value);
    }

    /**
     * Whether a reference definition could start at `$at`, by its first byte.
     *
     * A walk crossing a container prefix reads this at an offset instead of
     * cutting the tail out to hand {@see self::matchDefinitionLine()}, which is
     * the copy per level markup-carve/carve-php#1437 removed. The footnote
     * spelling `[^label]:` shares the bracket, so one head covers both
     * definition kinds. *
     * The parser's own fast exit spells the same byte test inline, because it
     * runs on nearly every line the parser reads and one more call for it
     * measured against an ordinary document. The two are held together by
     * `OffsetHeadsAgreeWithTheirParsersTest`, which walks EVERY byte value and
     * asserts the head accepts a line exactly where the parser can, so the pair
     * cannot drift in silence - which is the failure
     * markup-carve/carve-php#969 was.
     */
    public static function isDefinitionHead(string $line, int $at = 0): bool
    {
        return ($line[$at] ?? '') === '[';
    }
}
