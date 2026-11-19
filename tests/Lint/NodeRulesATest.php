<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Lint\Context;
use MarkupCarve\Carve\Lint\Linter;
use MarkupCarve\Carve\Lint\NodeRulesA;
use MarkupCarve\Carve\Test\Support\Held;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected findings are the carve-js output for the same inputs.
 */
final class NodeRulesATest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<string, mixed>, array{string, string, int, int, string}}>
     */
    public static function rules(): array
    {
        $cases = [
            'deprecated-bare-underline' => [
                'a _x_ b',
                'a {_x_} b',
                [],
                [
                    'deprecated-bare-underline',
                    '/children/0/children/0',
                    2,
                    5,
                    'A bare underscore pair is text in 0.2. Write the braced underline, or the slash pair for emphasis.',
                ],
            ],
            'deprecated-bare-strike' => [
                'a ~x~ b',
                'a {~x~} b',
                [],
                ['deprecated-bare-strike', '/children/0/children/0', 2, 5, 'A bare tilde pair is text in 0.2. Write the braced strike.'],
            ],
            'deprecated-bare-highlight' => [
                'a =x= b',
                'a {=x=} b',
                [],
                [
                    'deprecated-bare-highlight',
                    '/children/0/children/0',
                    2,
                    5,
                    'A bare equals pair is text in 0.2. Write the braced highlight.',
                ],
            ],
            'markdown-strong-double-star' => [
                '**bold**',
                '*bold*',
                [],
                [
                    'markdown-strong-double-star',
                    '/children/0/children/0',
                    0,
                    8,
                    'Markdown `**strong**` is literal text in Carve, asterisks included. Carve strong is a single `*`.',
                ],
            ],
            'markdown-strikethrough-double-tilde' => [
                '~~x~~',
                '{~x~}',
                [],
                [
                    'markdown-strikethrough-double-tilde',
                    '/children/0/children/0',
                    0,
                    5,
                    'Markdown `~~strikethrough~~` is literal text in Carve. Carve strikethrough is the braced `{~x~}`.',
                ],
            ],
            'djot-emphasis-underscore' => [
                'a _x_ b',
                'a /x/ b',
                ['rules' => ['djot-emphasis-underscore' => 'on']],
                [
                    'djot-emphasis-underscore',
                    '/children/0/children/0',
                    2,
                    5,
                    'Djot emphasis `_x_` is literal text in Carve. Carve emphasis is `/x/`.',
                ],
            ],
            'djot-subscript-tilde' => [
                'a ~x~ b',
                'a {,x,} b',
                ['rules' => ['djot-subscript-tilde' => 'on']],
                [
                    'djot-subscript-tilde',
                    '/children/0/children/0',
                    2,
                    5,
                    'Djot subscript `~x~` is literal text in Carve. Carve subscript is the braced `{,x,}`.',
                ],
            ],
            'djot-superscript-caret' => [
                'a ^x^ b',
                'a {^x^} b',
                ['rules' => ['djot-superscript-caret' => 'on']],
                [
                    'djot-superscript-caret',
                    '/children/0/children/0',
                    2,
                    5,
                    'Djot superscript `^x^` is literal text in Carve — Carve superscript is the braced `{^x^}` only.',
                ],
            ],
            'a11y/image-alt' => [
                '![](/x)',
                '![x](/x)',
                [],
                ['a11y/image-alt', '/children/0/children/0', 0, 7, 'image has empty alternative text and is not marked decorative'],
            ],
            'empty-link-destination' => [
                '[x]()',
                '[x](/u)',
                [],
                ['empty-link-destination', '/children/0/children/0', 0, 5, 'This link has an empty destination.'],
            ],
            'deprecated-loose-attribute' => [
                "{loose}\n- a\n- b",
                "- a\n- b",
                [],
                [
                    'deprecated-loose-attribute',
                    '/children/0',
                    0,
                    15,
                    'The loose attribute was removed in 0.2 and is a plain attribute here. A list is loose when a blank '
                    . 'line stands between two of its items.',
                ],
            ],
            'deprecated-table-attribute' => [
                "{header-rows=1}\n| a |",
                "{header-cols=1}\n| a |",
                [],
                [
                    'deprecated-table-attribute',
                    '/children/0',
                    0,
                    21,
                    '(key) does not apply to a pipe table in 0.2. Mark header cells with the header mark and write '
                    . 'alignment on the cells.',
                ],
            ],
            'heading-level-skip' => [
                "# One\n\n### Two",
                "# One\n\n## Two",
                [],
                [
                    'heading-level-skip',
                    '/children/1',
                    7,
                    14,
                    'This heading skips from level 1 to 3; use the next heading level for a continuous outline.',
                ],
            ],
            'heading-marker-mixed' => [
                "= One\n\n# Two",
                "= One\n\n= Two",
                [],
                [
                    'heading-marker-mixed',
                    '/children/0',
                    0,
                    5,
                    'This document mixes the two heading markers. carve fmt rewrites the hash to the equals sign.',
                ],
            ],
            'heading-closing-hashes' => [
                '# Title ##',
                '# Title',
                [],
                [
                    'heading-closing-hashes',
                    '/children/0',
                    8,
                    10,
                    'The closing "##" run is heading text in Carve, not a closing marker. Delete it.',
                ],
            ],
            'heading-trailing-attribute' => [
                '# Title {.x}',
                "{.x}\n# Title",
                [],
                [
                    'heading-trailing-attribute',
                    '/children/0',
                    8,
                    12,
                    'Trailing "{.x}" on a heading is literal text in Carve, not an attribute block. Move it to a "{.x}" '
                    . 'line directly above the heading.',
                ],
            ],
            'duplicate-reference-url' => [
                "[a]: /u\n\n[b]: /u",
                "[a]: /u\n\n[b]: /v",
                [],
                ['duplicate-reference-url', '/children/1', 9, 16, 'This reference definition repeats an earlier URL.'],
            ],
            'deprecated-separator-row' => [
                "| a |\n|---|",
                '|= a |',
                [],
                [
                    'deprecated-separator-row',
                    '/children/0/children/0/children/1',
                    6,
                    11,
                    'The separator row was removed in 0.2 and is a row of dashes here. Mark the header cells with the '
                    . 'header mark and delete this row.',
                ],
            ],
            'table-row-cell-count' => [
                "| a | b |\n| c |",
                "| a | b |\n| c | d |",
                [],
                [
                    'table-row-cell-count',
                    '/children/0/children/0/children/1',
                    10,
                    15,
                    'This table row has 1 cells; the header has 2. Add or remove a cell to match the table.',
                ],
            ],
            'figure-group-empty' => [
                "::: figure\ntext\n:::",
                "::: figure\n| a |\n^ cap\n:::",
                [],
                [
                    'figure-group-empty',
                    '/children/0',
                    0,
                    19,
                    'This "::: figure" group holds no captionable panel; the panels wrapper renders around the preserved '
                    . 'content only.',
                ],
            ],
            'figure-group-single-panel' => [
                "::: figure\n| a |\n^ cap\n:::",
                "::: figure\n| a |\n^ cap\n| b |\n^ cap\n:::",
                [],
                [
                    'figure-group-single-panel',
                    '/children/0',
                    0,
                    26,
                    'This "::: figure" group holds a single panel; a plain captioned figure renders the same content '
                    . 'without the group wrapper.',
                ],
            ],
            'deprecated-substitution-arrow' => [
                'a {~old~>new~} b',
                'a {~old~} b',
                [],
                [
                    'deprecated-substitution-arrow',
                    '/children/0/children/1',
                    2,
                    14,
                    'The substitution arrow was removed in 0.2; this is a strike span. Write a deletion directly '
                    . 'followed by an insertion.',
                ],
            ],
            'frontmatter-shaped-thematic-break' => [
                "---\nplain",
                "---\ntitle: x\n---",
                [],
                [
                    'frontmatter-shaped-thematic-break',
                    '/children/0',
                    0,
                    9,
                    'Three hyphens on line 1 open frontmatter, not a thematic break. Write three stars for a break.',
                ],
            ],
            'bidi-control-in-source' => [
                "a \u{202E} b",
                'a b',
                [],
                [
                    'bidi-control-in-source',
                    '/children/0/children/0',
                    2,
                    5,
                    'Bidi override/isolate control U+202E is preserved by canonical Carve but not written to the text '
                    . 'and code of presentation output; remove it unless intentional.',
                ],
            ],
            'bidi-control-in-source/code' => [
                "a `x\u{2066}` b",
                'a `x` b',
                [],
                [
                    'bidi-control-in-source',
                    '/children/0/children/1',
                    4,
                    7,
                    'Bidi override/isolate control U+2066 is preserved by canonical Carve but not written to the text '
                    . 'and code of presentation output; remove it unless intentional.',
                ],
            ],
            'description-term-multi-line' => [
                ":: term\nmore\n: desc",
                ":: term\n: desc",
                [],
                [
                    'description-term-multi-line',
                    '/children/0/children/0',
                    8,
                    12,
                    'This line continues the term above. Add a blank line after the term if it is a paragraph.',
                ],
            ],
            'deprecated-indented-line-under-heading' => [
                "# Heading\n  continuation",
                "# Heading\ncontinuation",
                [],
                [
                    'deprecated-indented-line-under-heading',
                    '/children/0',
                    10,
                    24,
                    'This indented line directly below a heading is part of the heading in 0.2; in 0.1 it started a '
                    . 'paragraph. Remove the indent to make it a separate block again; if it then reads as a list marker '
                    . 'or another opener and a paragraph is meant, escape its first character.',
                ],
            ],
            'deprecated-indented-line-under-heading/comment' => [
                "# Heading\n%% note\n   continuation",
                "# Heading\n%% note\ncontinuation",
                [],
                [
                    'deprecated-indented-line-under-heading',
                    '/children/0',
                    18,
                    33,
                    'This indented line directly below a heading is part of the heading in 0.2; in 0.1 it started a '
                    . 'paragraph. Remove the indent to make it a separate block again; if it then reads as a list marker '
                    . 'or another opener and a paragraph is meant, escape its first character.',
                ],
            ],
            'figure-group-nested' => [
                "::: figure\n::: figure\n:::\n:::",
                "::: figure\n| a |\n^ cap\n:::",
                [],
                [
                    'figure-group-nested',
                    '/children/0/children/0',
                    11,
                    25,
                    'A "::: figure" inside a figure group is a figure group of its own and not a panel of the outer '
                    . 'group. Move it out of the enclosing group.',
                ],
            ],
            'figure-group-panel-number' => [
                "::: figure\n| a |\n^ # cap\n:::",
                "::: figure\n| a |\n^ cap\n:::",
                [],
                [
                    'figure-group-panel-number',
                    '/children/0/children/0',
                    11,
                    24,
                    'A "#" placeholder in a panel caption stays literal: panels are not numbering units, the group '
                    . 'caption carries the number (and panel ids resolve with its letter).',
                ],
            ],
            'footnotes-placement-in-container' => [
                "::: note\n::: footnotes\n:::\n:::",
                "::: footnotes\n:::",
                [],
                [
                    'footnotes-placement-in-container',
                    '/children/0/children/0',
                    9,
                    26,
                    'This "::: footnotes" marker is inside a container, where it does not place: it renders as a plain '
                    . 'typed div and the endnotes section goes where it would without the marker. Move the marker to '
                    . 'document level, or delete it to accept the default position.',
                ],
            ],
            'references-placement-in-container' => [
                "::: note\n::: references\n:::\n:::",
                "::: references\n:::",
                [],
                [],
            ],
            'semantic-attribute-outside-span' => [
                "{mark=foo}\n# H",
                '[x]{mark=foo}',
                [],
                [
                    'semantic-attribute-outside-span',
                    '/children/0',
                    0,
                    14,
                    '"mark" is a semantic span attribute (PART 9 §10) and only applies to an ordinary [content]{attrs} '
                    . 'span; on heading it stays a raw attribute and renders as mark="foo".',
                ],
            ],
            'semantic-attribute-value-ignored' => [
                '[x]{mark=foo}',
                '[x]{mark}',
                [],
                [
                    'semantic-attribute-value-ignored',
                    '/children/0/children/0',
                    0,
                    13,
                    'Value on the semantic attribute "mark" is discarded: it selects the <mark> element and reaches no '
                    . 'output. Only abbr, dfn and time carry a value (as title or datetime).',
                ],
            ],
            'table-column-arity' => [
                "{widths=10}\n| a | b |",
                "{widths=10,20}\n| a | b |",
                [],
                [
                    'table-column-arity',
                    '/children/0',
                    1,
                    7,
                    'widths supplies 1 column entries for a 2-column table; the unset tail is valid but may be '
                    . 'accidental.',
                ],
            ],
            'table-attribute-invalid' => [
                "{widths=x}\n| a |",
                "{widths=10}\n| a |",
                [],
                [
                    'table-attribute-invalid',
                    '/children/0',
                    1,
                    9,
                    'The value of widths is not valid, so the table ignores the whole attribute. Correct every entry, or '
                    . 'remove the attribute.',
                ],
            ],
            'table-row-count-clamped' => [
                "{table header-rows=5}\n```csv\na,b\nc,d\n```",
                "{table header-rows=2}\n```csv\na,b\nc,d\n```",
                [],
                [
                    'table-row-count-clamped',
                    '/children/0',
                    7,
                    20,
                    'header-rows=5 asks for more rows than the table has, so it takes 2. Write header-rows=2.',
                ],
            ],
            'table-row-count-clamped/footer-rows' => [
                "{footer-rows=3}\n| a |\n| b |",
                "{footer-rows=2}\n| a |\n| b |",
                [],
                [
                    'table-row-count-clamped',
                    '/children/0',
                    1,
                    14,
                    'footer-rows=3 asks for more rows than the table has, so it takes 2. Write footer-rows=2.',
                ],
            ],
            'comment-splits-table-row' => [
                "| a |\n%% x\n+ b |",
                "| a |\n+ b |\n%% x",
                [],
                [
                    'comment-splits-table-row',
                    '/children/0',
                    6,
                    10,
                    'This comment stands between a table row and its continuation, so it belongs after the whole row. '
                    . 'Move it above the row or below the continuation.',
                ],
            ],
            'comment-splits-table-row/trailing' => [
                "| a | %% x\n+ b |",
                "| a |\n+ b | %% x",
                [],
                [
                    'comment-splits-table-row',
                    '/children/0',
                    5,
                    10,
                    'This comment stands between a table row and its continuation, so it belongs after the whole row. '
                    . 'Move it above the row or below the continuation.',
                ],
            ],
            'table-width-total' => [
                "{widths=60,60}\n| a | b |",
                "{widths=50,50}\n| a | b |",
                [],
                ['table-width-total', '/children/0', 1, 7, 'The specified table column widths total more than 100%.'],
            ],
            'table-row-attribute-as-cell' => [
                '| a | {.x} |',
                '| a | b |',
                [],
                [
                    'table-row-attribute-as-cell',
                    '/children/0/children/0/children/0',
                    0,
                    12,
                    'A row attribute is the brace block glued after the closing pipe. Here it is cell content.',
                ],
            ],
            'emphasis-as-heading' => [
                "/Title/\n\ntext",
                '/Title/ and more',
                [],
                ['emphasis-as-heading', '/children/0', 0, 7, 'This emphasized paragraph looks like a heading.'],
            ],
            'comment-shaped-emphasis' => [
                'a /* x */ b',
                'a /x/ b',
                [],
                [
                    'comment-shaped-emphasis',
                    '/children/0/children/1',
                    2,
                    9,
                    'This emphasis looks like a C comment; use Carve comment syntax if you meant a comment.',
                ],
            ],
            'glob-shaped-strong' => [
                'glob *.js or src/* and',
                'glob and',
                [],
                [
                    'glob-shaped-strong',
                    '/children/0/children/1',
                    5,
                    18,
                    'This strong span looks like a file glob; escape the asterisk if you meant a glob.',
                ],
            ],
            'djot-subscript-tilde-braced' => [
                '{~x~}',
                '{,x,}',
                ['rules' => ['djot-subscript-tilde-braced' => 'on']],
                [
                    'djot-subscript-tilde-braced',
                    '/children/0/children/0',
                    0,
                    5,
                    'Djot subscript `{~x~}` renders as *strikethrough* in Carve.',
                ],
            ],
            'djot-highlight-braces' => [
                '{=x=}',
                '=x=',
                ['rules' => ['djot-highlight-braces' => 'on']],
                [
                    'djot-highlight-braces',
                    '/children/0/children/0',
                    0,
                    5,
                    'Djot highlight `{=x=}` is also Carve highlight. The bare `=x=` is literal text in Carve.',
                ],
            ],
            'unattached-inline-attribute' => [
                'text {.cls} here',
                '[text]{.cls} here',
                [],
                [
                    'unattached-inline-attribute',
                    '/children/0/children/0',
                    5,
                    11,
                    'This attribute block is attached to nothing and renders as text. Glue it to a span, link or code '
                    . 'span, or escape the brace.',
                ],
            ],
            'deprecated-cell-alignment' => [
                '|< a |',
                '|={<} a |',
                [],
                [
                    'deprecated-cell-alignment',
                    '/children/0/children/0/children/0/children/0',
                    1,
                    2,
                    'The table cell alignment prefix "<" is the deprecated 0.1.13 spelling, removed in 0.2.0. Alignment '
                    . 'symbols now lead the cell\'s attribute block: write "{<}". In 0.2 the old prefix is cell text, so '
                    . 'the formatter leaves it; the upgrade tool rewrites it.',
                ],
            ],
            'deprecated-cell-alignment/right' => [
                '|> a |',
                '|{>} a |',
                [],
                [
                    'deprecated-cell-alignment',
                    '/children/0/children/0/children/0/children/0',
                    1,
                    2,
                    'The table cell alignment prefix ">" is the deprecated 0.1.13 spelling, removed in 0.2.0. Alignment '
                    . 'symbols now lead the cell\'s attribute block: write "{>}". In 0.2 the old prefix is cell text, so '
                    . 'the formatter leaves it; the upgrade tool rewrites it.',
                ],
            ],
            'table-marker-run-padding' => [
                '|{.x}> a |',
                '|>{.x} a |',
                [],
                [
                    'table-marker-run-padding',
                    '/children/0/children/0/children/0/children/0',
                    1,
                    9,
                    '"{.x}>" writes a cell\'s attribute block before its alignment marker, which Carve no longer reads '
                    . 'as one. The block is part of the cell\'s marker run and the run ends at a space, so with the ">" '
                    . 'glued to it there is no run: the braces are content and the cell is neither attributed nor aligned. '
                    . 'Write ">{.x} " to align it, or "{.x} >" to keep the ">" as content deliberately.',
                ],
            ],
            'fence-closer-indented' => [
                "```\nx\n  ```",
                "```\nx\n```",
                [],
                [
                    'fence-closer-indented',
                    '/children/0',
                    8,
                    11,
                    'This line closes the block opened on line (n) although it is indented differently. If it is '
                    . 'content, make the outer fence longer.',
                ],
            ],
            'figure-group-opener-metadata' => [
                "::: figure \"T\"\ntext\n:::",
                "::: figure\ntext\n:::",
                [],
                [
                    'figure-group-opener-metadata',
                    '/children/0',
                    0,
                    14,
                    'A "::: figure" opener carrying a quoted title or [label] is not a figure group; it is a plain '
                    . 'container with the class "figure" (C2-B-127). Drop the title or label to open a figure group, and '
                    . 'use a caption line below it.',
                ],
            ],
            'comment-line-in-line-block' => [
                "::: |\n`\n%%\n:::\n",
                "::: |\n`\n\n:::\n",
                [],
                [
                    'comment-line-in-line-block',
                    '/children/0/children/1',
                    8,
                    10,
                    'A line comment is text inside a line block. Use the braced comment.',
                ],
            ],
            'broken-crossref' => [
                '</#no>',
                "# yes\n\n</#yes>",
                [],
                [
                    'broken-crossref',
                    '/children/0/children/0',
                    0,
                    6,
                    'Cross-reference </#no> has no matching heading id; it renders as the literal text "</#no>".',
                ],
            ],
            'broken-fragment-link' => [
                '[x](#no)',
                "# yes\n\n[x](#yes)",
                [],
                [
                    'broken-fragment-link',
                    '/children/0/children/0',
                    0,
                    8,
                    'Link to "#no" matches no id in this document, so the link goes nowhere.',
                ],
            ],
            'platform-issue-reference' => [
                'See #123.',
                'See item 123.',
                ['platforms' => ['github']],
                [
                    'platform-issue-reference',
                    '/children/0/children/0',
                    4,
                    8,
                    'GitHub re-linkifies a hash-number in published output, so "#123" becomes a link that notifies or '
                    . 'references something unrelated; move the example into a fenced code block, or rewrite it as "item '
                    . '1" / "point 1"',
                ],
            ],
            'platform-mention-token' => [
                'See @bob.',
                'See Bob.',
                ['platforms' => ['github']],
                [
                    'platform-mention-token',
                    '/children/0/children/1',
                    4,
                    8,
                    'GitHub re-linkifies a mention token in published output, so "@bob" becomes a link that notifies or '
                    . 'references something unrelated; move the example into a fenced code block, or strip the sigil and '
                    . 'rephrase',
                ],
            ],
        ];

        return array_filter($cases, static fn (string $id): bool => !Held::has('node-rules-a/' . $id), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param string $trigger
     * @param string $near
     * @param array<string, mixed> $options
     * @param array{0: string, 1: string, 2: int, 3: int, 4: string}|array{} $expected
     *
     * @return void
     */
    #[DataProvider('rules')]
    public function testRule(string $trigger, string $near, array $options, array $expected): void
    {
        if ($expected === []) {
            $this->assertSame([], self::findings($trigger, $options, 'references-placement-in-container'));

            return;
        }
        $found = self::findings($trigger, $options, $expected[0]);
        $this->assertNotSame([], $found);
        $this->assertSame($expected, $found[0]);
        $this->assertSame([], self::findings($near, $options, $expected[0]));
    }

    /**
     * @return void
     */
    public function testCollectFragment(): void
    {
        $c = new Context('[x](#no)', [], Linter::catalog());
        $link = ['type' => 'link', 'href' => '#no'];
        NodeRulesA::collectFragment($link, '/children/0/children/0', $c);
        NodeRulesA::collectFragment(['type' => 'link', 'href' => '/u'], '/children/0/children/1', $c);
        $this->assertSame([[$link, '/children/0/children/0']], $c->fragmentLinks);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param string $code
     *
     * @return array<array{0: string, 1: string, 2: int, 3: int, 4: string}>
     */
    private static function findings(string $source, array $options, string $code): array
    {
        $out = [];
        foreach (Carve::lint($source, $options) as $diagnostic) {
            if ($diagnostic->code === $code) {
                $out[] = [
                    $diagnostic->code,
                    (string)$diagnostic->path,
                    $diagnostic->startOffset,
                    $diagnostic->endOffset,
                    $diagnostic->message,
                ];
            }
        }

        return $out;
    }
}
