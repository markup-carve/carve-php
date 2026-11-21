<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Held;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The node rules of the second half of carve-js `lint/structure.ts`; the expected findings come from carve-js.
 */
final class NodeRulesBTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array{string, string, int, int, string}, 2: string, 3: array<string, mixed>}>
     */
    public static function rules(): array
    {
        $cases = [
            'delimited-table-wide-row' => [
                "{table}\n```csv\na,b\nc,d,e\n```",
                [
                    'delimited-table-wide-row',
                    '/children/0',
                    0,
                    28,
                    'A CSV/TSV record has more fields than the first record; the table keeps the extra columns.',
                ],
                "{table}\n```csv\na,b\nc,d\n```",
                [],
            ],
            'delimited-table-wide-row/tsv' => [
                "{table}\n```tsv\na\tb\nc\td\te\n```",
                [
                    'delimited-table-wide-row',
                    '/children/0',
                    0,
                    28,
                    'A CSV/TSV record has more fields than the first record; the table keeps the extra columns.',
                ],
                "{table}\n```tsv\na\tb\nc\td\n```",
                [],
            ],
            'djot-intraword-underscore' => [
                'a_x_b',
                [
                    'djot-intraword-underscore',
                    '/children/0/children/0',
                    1,
                    4,
                    'Djot emphasizes this intraword `_x_`; the migration leaves it literal, so the emphasis is '
                        . 'lost. Brace it as `{/x/}` if it was meant.',
                ],
                'a _x_ b',
                ['rules' => ['djot-intraword-underscore' => 'on']],
            ],
            'emphasis-marker-before-attributes' => [
                "a*{_k=b_}\n",
                [
                    'emphasis-marker-before-attributes',
                    '/children/0/children/0',
                    1,
                    9,
                    'This * closes no span, so it and the attribute block after it stay text. Brace the span '
                        . 'you mean ({*text*}{…}) to attach the block, or escape the * to keep both as text.',
                ],
                "a\n",
                [],
            ],
            'emphasis-marker-before-attributes/slash' => [
                'x/{a} y',
                [
                    'emphasis-marker-before-attributes',
                    '/children/0/children/0',
                    1,
                    5,
                    'This / closes no span, so it and the attribute block after it stay text. Brace the span '
                        . 'you mean ({/text/}{…}) to attach the block, or escape the / to keep both as text.',
                ],
                'x/ y',
                [],
            ],
            'carve-version-unsupported' => [
                "---\ncarve: 0.3\n---",
                [
                    'carve-version-unsupported',
                    '/children/0',
                    0,
                    18,
                    'document declares Carve 0.3; this engine implements 0.2, so constructs added after that '
                        . 'version will not render as intended',
                ],
                "---\ncarve: 0.2\n---",
                [],
            ],
            'carve-version-unsupported/major' => [
                "---\ncarve: 1.0\n---",
                [
                    'carve-version-unsupported',
                    '/children/0',
                    0,
                    18,
                    'document declares an unrecognized Carve version 1.0; this engine implements 0.2',
                ],
                "---\ncarve: 0.2.0\n---",
                [],
            ],
            'comment-fence-closer-with-text' => [
                "%%%\nx\n%%% not a closer\n%%%\nafter",
                [
                    'comment-fence-closer-with-text',
                    '/children/0',
                    6,
                    22,
                    'This line starts with a percent run and holds other text, so it is content of the comment '
                        . 'block and does not close it. Put the closing run alone on its line.',
                ],
                "%%%\nx\n\n%%%\nafter",
                [],
            ],
            'comment-in-reference-label' => [
                '[x][a %% b]',
                [
                    'comment-in-reference-label',
                    '/children/0/children/1',
                    5,
                    11,
                    'A line comment starts inside this reference label and takes the rest of the line, so the '
                        . 'brackets are text. Move the comment out of the label, or escape the percent signs.',
                ],
                '[x]',
                [],
            ],
            'footnote-labels-differ-only-in-whitespace' => [
                "[^a b]: one\n\n[^a  b]: two",
                [
                    'footnote-labels-differ-only-in-whitespace',
                    '/children/1',
                    13,
                    25,
                    'Footnote definition [^a  b] normalizes to the same key as [^a b]; the first definition '
                        . 'wins. Normalized key: a b.',
                ],
                "[^a b]: one\n\n[^c d]: two",
                [],
            ],
            'table-column-overlap' => [
                "{aligns=left}\n|={>} a |= b |",
                [
                    'table-column-overlap',
                    '/children/0',
                    1,
                    7,
                    'Column 1 supplies the same alignment axis both in the table and in a table attribute; the '
                        . 'in-table marker wins.',
                ],
                "{aligns=left}\n|= a |= b |",
                [],
            ],
            'tight-list-item-with-blank-line' => [
                "- a\n\n  b\n- c",
                [
                    'tight-list-item-with-blank-line',
                    '/children/0',
                    0,
                    12,
                    'A blank line inside an item no longer makes the list loose. Put a blank line between the '
                        . 'items if every item should render as a paragraph.',
                ],
                "- a\n\n- c",
                ['rules' => ['tight-list-item-with-blank-line' => 'on']],
            ],
            'tight-list-item-with-blank-line/quote' => [
                "> - a\n>\n>   b\n> - c",
                [
                    'tight-list-item-with-blank-line',
                    '/children/0/children/0',
                    2,
                    19,
                    'A blank line inside an item no longer makes the list loose. Put a blank line between the '
                        . 'items if every item should render as a paragraph.',
                ],
                "> - a\n>\n> - c",
                ['rules' => ['tight-list-item-with-blank-line' => 'on']],
            ],
            'braced-comment-in-a-template-source' => [
                '{% if x %}',
                [
                    'braced-comment-in-a-template-source',
                    '/children/0/children/0',
                    0,
                    10,
                    'This braced comment is a template tag. Liquid, Nunjucks, or Twig source may have reached '
                        . 'Carve as text; the tag is parsed as an invisible comment.',
                ],
                '{% note %}',
                [],
            ],
            'unclosed-braced-comment' => [
                "a {% oops\n",
                [
                    'unclosed-braced-comment',
                    '/children/0/children/0',
                    2,
                    9,
                    'This braced comment is not closed before the next block starts, so it stays text. Close it '
                        . 'inside its block, or use %% line comments.',
                ],
                "a {% oops %}\n",
                [],
            ],
            'unclosed-inline-emphasis' => [
                "*a `b` c\n",
                [
                    'unclosed-inline-emphasis',
                    '/children/0/children/0',
                    0,
                    1,
                    'This strong (or: emphasis) opener is unclosed in its paragraph; add a closer.',
                ],
                "a `b` c\n",
                [],
            ],
            'ordered-list-kind-split' => [
                "i.  x\nb.  y\n",
                [
                    'ordered-list-kind-split',
                    '/children/1',
                    6,
                    11,
                    'A lone i starts a roman list and any other single letter an alphabetic one, so this list '
                        . 'splits after its first item.',
                ],
                "i.  x\n\n",
                [],
            ],
            'sigil-after-letter' => [
                'ü@bob',
                [
                    'sigil-after-letter',
                    '/children/0/children/0',
                    2,
                    3,
                    'A mention or tag does not start directly after a letter or number. Put a space or '
                        . 'punctuation before it.',
                ],
                'a@bob',
                [],
            ],
            'sigil-after-letter/hash' => [
                'ü#1a',
                [
                    'sigil-after-letter',
                    '/children/0/children/0',
                    2,
                    3,
                    'A mention or tag does not start directly after a letter or number. Put a space or '
                        . 'punctuation before it.',
                ],
                'ü#1',
                [],
            ],
            'list-item-body-below-content-column' => [
                "- a\n\n b",
                [
                    'list-item-body-below-content-column',
                    '/children/0/children/0',
                    6,
                    7,
                    "This line is indented past the list marker but short of the item's text, and it belongs to "
                        . 'the item. Indent it to the text, or move it to the column of the list to end the item.',
                ],
                "- a\n\n\tb",
                [],
            ],
            'list-item-block-overindented' => [
                "- a\n\n    > b\n",
                [
                    'list-item-block-overindented',
                    '/children/0/children/0/children/1',
                    9,
                    12,
                    "This block opener is past the list item's canonical content column 3; it now parses "
                        . 'structurally and the formatter will canonicalize it. Dedent it to column 3 for an explicit '
                        . 'structural spelling, or escape the opener to preserve the literal meaning used by older '
                        . 'readers.',
                ],
                "- a\n\n  > b\n",
                [],
            ],
            'list-marker-below-content-column' => [
                "- a\n  - b\n * c\n",
                [
                    'list-marker-below-content-column',
                    '/children/0/children/0/children/2/children/0',
                    11,
                    14,
                    'This list marker starts at column 2, between the columns of two open lists, so it is read '
                        . 'at the column of the deeper list. Start it at column 3 to make that explicit, or at column 1 to put it in '
                        . 'the outer list.',
                ],
                "- a\n  - b\n  * c\n",
                [],
            ],
            'block-opener-overindented' => [
                "- a\n   - b\n",
                [
                    'block-opener-overindented',
                    '/children/0/children/0/children/1',
                    7,
                    10,
                    'This list is indented 1 column(s) past the content column of list item, and it still opens '
                        . 'a list, measured from its own line. Remove the indentation to make the structure explicit, '
                        . 'or escape the opener to keep the line as text.',
                ],
                "- a\n  - b\n",
                [],
            ],
            'braced-span-opener-as-text' => [
                "{*a {**} b*}\n",
                [
                    'braced-span-opener-as-text',
                    '/children/0/children/0/children/0',
                    4,
                    6,
                    'This braced span did not open: the delimiter inside it closed a span that started before '
                        . 'it. Escape that delimiter.',
                ],
                "{*a *} b*}\n",
                [],
            ],
            'braced-span-opener-as-text/emphasis' => [
                '/a {*b/} c/',
                [
                    'braced-span-opener-as-text',
                    '/children/0/children/0/children/0',
                    3,
                    5,
                    'This braced span did not open: the delimiter inside it closed a span that started before '
                        . 'it. Escape that delimiter.',
                ],
                '/a b/',
                [],
            ],
            'colon-fence-length-mismatch' => [
                "- :::\n  :::e\n  ::::\nl\n",
                [
                    'colon-fence-length-mismatch',
                    '',
                    15,
                    19,
                    'Bare 4-colon fence has a different width from the innermost 3-colon div opened at 1:3; it closes the '
                        . 'container.',
                ],
                "- :::\n  :::e\n\nl\n",
                [],
            ],
            'colon-fence-length-mismatch/admonition' => [
                "{.x}\n::: warning\na\n::::\n",
                [
                    'colon-fence-length-mismatch',
                    '',
                    19,
                    23,
                    'Bare 4-colon fence has a different width from the innermost 3-colon admonition opened at 2:1; it '
                        . 'closes the container.',
                ],
                "{.x}\n::: warning\na\n:::\n",
                [],
            ],
            'consumed-key-near-miss' => [
                "{tabel}\n```csv\na,b\n```",
                [
                    'consumed-key-near-miss',
                    '/children/0',
                    8,
                    14,
                    '"tabel" is not a consumed key here; did you mean {table}?',
                ],
                "{table}\n```csv\na,b\n```",
                [],
            ],
            'consumed-key-near-miss/width' => [
                "{atble}\n```csv\na\n```",
                [
                    'consumed-key-near-miss',
                    '/children/0',
                    8,
                    14,
                    '"atble" is not a consumed key here; did you mean {table}?',
                ],
                "{table}\n```csv\na\n```",
                [],
            ],
            'ordered-marker-right-aligned' => [
                " 9. a\n10. b",
                [
                    'ordered-marker-right-aligned',
                    '/children/0/children/1',
                    6,
                    11,
                    "This marker sits left of its list, so it is a sibling item that moves the list's column. "
                        . 'Write the ordered markers left-aligned, as the formatter does.',
                ],
                " \n10. b",
                [],
            ],
            'table-span-marker-blocked' => [
                "|= H |\n| ^ |\n| a |",
                [
                    'table-span-marker-blocked',
                    '/children/0/children/1/children/0/children/0',
                    8,
                    11,
                    'The "^" span marker cannot extend a cell. It stands in the first row of its '
                        . 'group: head, body or foot. It is a cell that holds "^" as text; escape it as "\\^" to say '
                        . 'so, or remove it.',
                ],
                "|= H |\n||\n| a |",
                [],
            ],
            'table-span-marker-blocked/left' => [
                '| < | a |',
                [
                    'table-span-marker-blocked',
                    '/children/0/children/0/children/0/children/0',
                    1,
                    4,
                    'The "<" span marker cannot extend a cell. There is no cell to its left in its row. It is a '
                        . 'cell that holds "<" as text; escape it as "\\<" to say so, or remove it.',
                ],
                '| \\< | a |',
                [],
            ],
            'table-span-marker-attribute' => [
                "|= H |\n|={.x} ^ |\n| a |",
                [
                    'table-span-marker-attribute',
                    '/children/0/children/0/children/1/children/0',
                    8,
                    16,
                    'This "^" joins the cell above it, so its attribute block applies to no cell and carve fmt '
                        . 'drops it. Move the attributes to the cell it joins, or remove them.',
                ],
                "|= H |\n|= ^ |\n| a |",
                [],
            ],
            'table-span-marker-attribute/header' => [
                "| a |\n|= ^ |",
                [
                    'table-span-marker-attribute',
                    '/children/0/children/0/children/1/children/0',
                    7,
                    11,
                    'This "^" joins the cell above it, and its header mark makes no head row, so it marks no '
                        . 'cell and carve fmt drops it. Remove the "=", or make the row a head row.',
                ],
                "| a |\n| ^ |",
                [],
            ],
            'uneven-list-indent' => [
                "  - a\n- b",
                [
                    'uneven-list-indent',
                    '/children/0/children/1',
                    6,
                    9,
                    'This marker stands left of every open list and continues the list above it. Align it with '
                        . 'the markers of that list.',
                ],
                "- a\n- b",
                [],
            ],
            'non-descriptive-link-text' => [
                '[click here](/u)',
                [
                    'non-descriptive-link-text',
                    '/children/0/children/0',
                    0,
                    16,
                    'Use descriptive link text or a specific aria-label.',
                ],
                '[the manual](/u)',
                [],
            ],
            'unresolved-reference-link' => [
                '[a][b]',
                [
                    'unresolved-reference-link',
                    '/children/0/children/0',
                    0,
                    6,
                    'Reference link [a][b] has no matching link definition or heading; it renders as literal '
                        . 'text.',
                ],
                "[b]: /u\n\n[a][b]",
                [],
            ],
            'unresolved-reference-link/image' => [
                '![a][nope]',
                [
                    'unresolved-reference-link',
                    '/children/0/children/0',
                    0,
                    10,
                    'Reference image [a][nope] has no matching link definition; it renders as literal text.',
                ],
                "[nope]: /u.png\n\n![a][nope]",
                [],
            ],
            'unused-link-reference-definition' => [
                '[a]: /u',
                [
                    'unused-link-reference-definition',
                    '/children/0',
                    0,
                    7,
                    'This link reference definition is unused.',
                ],
                "[a]: /u\n\n[a][]",
                [],
            ],
            'unresolved-footnote' => [
                'See [^a].',
                [
                    'unresolved-footnote',
                    '/children/0/children/1',
                    4,
                    8,
                    'Footnote reference [^a] has no matching definition; it renders as literal text.',
                ],
                "[^a]: Note.\n\nSee [^a].",
                [],
            ],
            'unused-footnote-definition' => [
                '[^a]: Note.',
                [
                    'unused-footnote-definition',
                    '/children/0',
                    0,
                    4,
                    'Footnote definition [^a] is never referenced, so it is omitted from the rendered document.',
                ],
                "[^a]: Note.\n\nSee [^a].",
                [],
            ],
            'include-attributes-unused' => [
                "{.wide}\n{{ a.crv }}",
                [
                    'include-attributes-unused',
                    '/children/0',
                    0,
                    19,
                    'The attributes above this include apply to nothing, because it has no @code option. Remove '
                        . 'them or move them into the included file.',
                ],
                "{.wide}\n{{ a.js @code }}",
                [],
            ],
            'include-lines-table-rows-hint' => [
                "{table}\n{{ d.csv @lines:1-2 @code:csv }}",
                [
                    'include-lines-table-rows-hint',
                    '/children/0',
                    17,
                    27,
                    '@lines counts physical lines. Use @rows to select records of this table.',
                ],
                "{table}\n{{ d.csv @rows:1-2 @code:csv }}",
                [],
            ],
            'broken-fragment-link' => [
                '[x](#no)',
                [
                    'broken-fragment-link',
                    '/children/0/children/0',
                    0,
                    8,
                    'Link to "#no" matches no id in this document, so the link goes nowhere.',
                ],
                "# yes\n\n[x](#yes)",
                [],
            ],
        ];

        return array_filter($cases, static fn (string $id): bool => !Held::has('node-rules-b/' . $id), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param string $source
     * @param array{0: string, 1: string, 2: int, 3: int, 4: string} $expected
     * @param string $near
     * @param array<string, mixed> $options
     *
     * @return void
     */
    #[DataProvider('rules')]
    public function testRule(string $source, array $expected, string $near, array $options): void
    {
        $this->assertSame([$expected], $this->findings($source, $expected[0], $options));
        $this->assertSame([], $this->findings($near, $expected[0], $options));
    }

    public function testTableColumnOverlapUsesTheMatchingAxis(): void
    {
        $source = "{valigns=\"top,bottom\" aligns=\"left,right\"}\n|= A |={?^} B |\n| a | b |";
        $found = $this->findings($source, 'table-column-overlap', []);
        self::assertCount(1, $found);
        self::assertSame(1, $found[0][2]);
        self::assertSame(8, $found[0][3]);
        self::assertStringContainsString('Column 2 ', $found[0][4]);

        self::assertCount(1, $this->findings("{valigns=top}\n|={?v} a |", 'table-column-overlap', []));
        self::assertSame([], $this->findings("{widths=50}\n|={>} a |", 'table-column-overlap', []));
    }

    /**
     * @param string $source
     * @param string $code
     * @param array<string, mixed> $options
     *
     * @return array<array{0: string, 1: string, 2: int, 3: int, 4: string}>
     */
    private function findings(string $source, string $code, array $options): array
    {
        $out = [];
        foreach (Carve::lint($source, $options) as $diagnostic) {
            if ($diagnostic->code === $code) {
                $out[] = [
                    $diagnostic->code,
                    $diagnostic->path ?? '',
                    $diagnostic->startOffset,
                    $diagnostic->endOffset,
                    $diagnostic->message,
                ];
            }
        }

        return $out;
    }
}
