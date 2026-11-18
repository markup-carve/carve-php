<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Block;

use MarkupCarve\Carve2\Block\BlockParser;
use MarkupCarve\Carve2\Block\TreeSink;
use MarkupCarve\Carve2\Json\TreeEncoder;
use PHPUnit\Framework\TestCase;

/**
 * Block pass clauses of C2-B. Every expected tree was checked against the reference engine; positions
 * and the children of inline-content leaves are left out unless a test is about them.
 */
final class BlockClausesTest extends TestCase
{
    /**
     * @var array<string>
     */
    private const INLINE_LEAVES = ['paragraph', 'heading', 'definition_term', 'cell_paragraph', 'line'];

    /**
     * @param string $src
     *
     * @return array<string, mixed>
     */
    public static function encode(string $src): array
    {
        $sink = new TreeSink($src);
        (new BlockParser($sink))->parse($src);
        self::assertNotNull($sink->root);

        return (new TreeEncoder())->encode($sink->root, $src);
    }

    /**
     * @param mixed $v
     *
     * @return mixed
     */
    private static function strip(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        $type = $v['type'] ?? null;
        foreach ($v as $k => $x) {
            if ($k === 'pos' || $k === 'stages' || $k === 'srcByteLength' || $k === 'astVersion') {
                continue;
            }
            if ($k === 'children' && in_array($type, self::INLINE_LEAVES, true)) {
                continue;
            }
            $out[$k] = self::strip($x);
        }

        return $out;
    }

    /**
     * @param mixed $v
     * @param string|int ...$path
     *
     * @return mixed
     */
    private static function dig(mixed $v, string|int ...$path): mixed
    {
        foreach ($path as $k) {
            self::assertIsArray($v);
            $v = $v[$k] ?? null;
        }

        return $v;
    }

    /**
     * @param string $src
     * @param string $children JSON of the document children.
     *
     * @return void
     */
    private function assertTree(string $src, string $children): void
    {
        $tree = self::strip(self::encode($src));
        $this->assertIsArray($tree);
        $this->assertSame($children, json_encode($tree['children'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return void
     */
    public function testC2B031QuoteMarkNeedsSpace(): void
    {
        $this->assertTree(">a\n\n> b", '[{"type":"paragraph"},{"type":"block_quote","children":[{"type":"paragraph"}]}]');
    }

    /**
     * @return void
     */
    public function testC2B043BareDotNumbersAutomaticallyAndDotRunIsText(): void
    {
        $this->assertTree(
            "1. a\n. b\n\n. . . and then silence",
            '[{"type":"list","marker":{"kind":"ordered","style":"1","delimiter":"."},"sp'
            . 'acing":"tight","children":[{"type":"list_item","children":[{"type":"paragra'
            . 'ph"}]},{"type":"list_item","children":[{"type":"paragraph"}]}]},{"type":"pa'
            . 'ragraph"}]',
        );
    }

    /**
     * @return void
     */
    public function testC2B045StartAttributeSetsListStart(): void
    {
        $this->assertTree("{start=3}\n1. a\n2. b", '[{"type":"list","marker":{"kind":"ordered","style":"1","delimiter":".","sta'
            . 'rt":3},"spacing":"tight","children":[{"type":"list_item","children":[{"type'
            . '":"paragraph"}]},{"type":"list_item","children":[{"type":"paragraph"}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B046TaskBoxIsAnItemFact(): void
    {
        $this->assertTree("- [x] done\n- [ ] open\n- plain", '[{"type":"list","marker":{"kind":"bullet","char":"-"},"spacing":"tight","ch'
            . 'ildren":[{"type":"list_item","task":{"mark":"x"},"children":[{"type":"parag'
            . 'raph"}]},{"type":"list_item","task":{"mark":" "},"children":[{"type":"parag'
            . 'raph"}]},{"type":"list_item","children":[{"type":"paragraph"}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B049MismatchedMarkerStartsNewList(): void
    {
        $this->assertTree("- a\n* b", '[{"type":"list","marker":{"kind":"bullet","char":"-"},"spacing":"tight","ch'
            . 'ildren":[{"type":"list_item","children":[{"type":"paragraph"}]}]},{"type":"'
            . 'list","marker":{"kind":"bullet","char":"*"},"spacing":"tight","children":[{'
            . '"type":"list_item","children":[{"type":"paragraph"}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B015NestedListNeedsBulletOrValueOne(): void
    {
        $this->assertTree("- a\n  7. b\n\n- c\n  1. d", '[{"type":"list","marker":{"kind":"bullet","char":"-"},"spacing":"loose","ch'
            . 'ildren":[{"type":"list_item","children":[{"type":"paragraph"}]},{"type":"li'
            . 'st_item","children":[{"type":"paragraph"},{"type":"list","marker":{"kind":"'
            . 'ordered","style":"1","delimiter":"."},"spacing":"tight","children":[{"type"'
            . ':"list_item","children":[{"type":"paragraph"}]}]}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B021MarkerWithoutQuoteMarkClosesQuoteInItem(): void
    {
        $this->assertTree("- a\n\n  > q\n- b", '[{"type":"list","marker":{"kind":"bullet","char":"-"},"spacing":"tight","ch'
            . 'ildren":[{"type":"list_item","children":[{"type":"paragraph"},{"type":"bloc'
            . 'k_quote","children":[{"type":"paragraph"}]}]},{"type":"list_item","children'
            . '":[{"type":"paragraph"}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B029FenceBodyLosesOpenerIndent(): void
    {
        $this->assertTree("  ```\n    x\n  y\n  ```", '[{"type":"code_block","value":"  x\\ny\\n"}]');
    }

    /**
     * @return void
     */
    public function testC2B026ContainerThatDoesNotOwnLineCloses(): void
    {
        $this->assertTree("> a\n\n- b", '[{"type":"block_quote","children":[{"type":"paragraph"}]},{"type":"list","m'
            . 'arker":{"kind":"bullet","char":"-"},"spacing":"tight","children":[{"type":"'
            . 'list_item","children":[{"type":"paragraph"}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B125BareColonRunClosesOrOpens(): void
    {
        $this->assertTree(
            ":::\na\n:::\n\n::::\nb\n:::\n::::",
            '[{"type":"div","children":[{"type":"paragraph"}]},{"type":"div","children":'
            . '[{"type":"paragraph"},{"type":"div","children":[]}]}]',
        );
    }

    /**
     * @return void
     */
    public function testC2B127DivNameGivesAdmonition(): void
    {
        $this->assertTree(
            "::: note\nx\n:::\n\n::: warning \"T\" [L]\ny\n:::",
            '[{"type":"admonition","kind":"note","children":[{"type":"paragraph"}]},{"ty'
            . 'pe":"admonition","kind":"warning","title":{"type":"title"},"groupLabel":"L"'
            . ',"children":[{"type":"paragraph"}]}]',
        );
    }

    /**
     * @return void
     */
    public function testC2B196SigilNeedsSpace(): void
    {
        $this->assertTree(":::>\nx\n:::\n\n::: >\ny\n:::", '[{"type":"paragraph"},{"type":"div","children":[{"type":"block_quote","chil'
            . 'dren":[{"type":"paragraph"}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B119GluedLabelIsText(): void
    {
        $this->assertTree(":::[x]\nhi\n:::", '[{"type":"paragraph"},{"type":"div","children":[]}]');
    }

    /**
     * @return void
     */
    public function testC2B157BlockImagePromotedAtClose(): void
    {
        $this->assertTree(
            "![alt](a.png)\n\n![alt](a.png) more\n\n![alt](a.png)\n%% note",
            '[{"type":"paragraph","role":"image"},{"type":"paragraph"},{"type":"paragrap'
            . 'h","role":"image"}]',
        );
    }

    /**
     * @return void
     */
    public function testC2B158DisplayMathPromotedAtClose(): void
    {
        $this->assertTree("\$\$`x`\n\nsee \$\$`x`", '[{"type":"paragraph","role":"math"},{"type":"paragraph"}]');
    }

    /**
     * @return void
     */
    public function testC2B172SpanMarksJoinCells(): void
    {
        $this->assertTree("|=a|=b|\n|x|<|\n|^|y|", '[{"type":"table","columns":[{"type":"table_column"},{"type":"table_column"}'
            . '],"children":[{"type":"table_body","children":[{"type":"table_row","children":[{"'
            . 'type":"table_cell","header":false,"children":[{"type":"cell_paragraph"'
            . '}]},{"type":"table_cell","header":false,"children":[{"type":"cell_paragraph"'
            . '}]}]},{"type":"table_row","children":[{"'
            . 'type":"table_cell","header":false,"colspan":2,"children":[{"type":"cell_par'
            . 'agraph"}]},{"type":"table_cell","header":false,"span":"colspan","children":'
            . '[]}]},{"type":"table_row","children":[{"type":"table_cell","header":false,"'
            . 'children":[{"type":"cell_paragraph"}]},{"type":"table_cell","header":false,'
            . '"children":[{"type":"cell_paragraph"}]}]}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B173BlockedSpanKeepsAuthoredHeader(): void
    {
        $table = self::encode("|=|^|\n|a|b|")['children'][0];
        $this->assertSame(['table_body'], array_column($table['children'], 'type'));
        $cells = $table['children'][0]['children'][0]['children'];
        $this->assertSame([true, false], array_column($cells, 'header'));
    }

    /**
     * @return void
     */
    public function testC2B176ContinuationFragmentPastLastColumnFormsCell(): void
    {
        $this->assertTree("|a|b|\n+ c | d | e |", '[{"type":"table","columns":[{"type":"table_column"},{"type":"table_column"}'
            . ',{"type":"table_column"}],"children":[{"type":"table_body","children":[{"ty'
            . 'pe":"table_row","children":[{"type":"table_cell","header":false,"children":'
            . '[{"type":"cell_paragraph"}]},{"type":"table_cell","header":false,"children"'
            . ':[{"type":"cell_paragraph"}]},{"type":"table_cell","header":false,"children'
            . '":[{"type":"cell_paragraph"}]}]}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B186HeaderRowsHasNoEffectOnPipeTable(): void
    {
        $cell = '{"type":"table_cell","header":false,"children":[{"type":"cell_paragraph"}]}';
        $row = '{"type":"table_row","children":[' . $cell . ',' . $cell . ']}';
        $expected = '[{"type":"table","columns":[{"type":"table_column"},{"type":"table_column"}],';
        $expected .= '"attrs":{"keyValues":[["header-rows","2"]]},';
        $expected .= '"children":[{"type":"table_body","children":[' . $row . ',' . $row . ']}]}]';
        $this->assertTree("{header-rows=2}\n|a|b|\n|c|d|", $expected);
    }

    /**
     * @return void
     */
    public function testC2B159ThematicBreakAndSpacedStarsNestLists(): void
    {
        $this->assertTree("* * *\n\n***", '[{"type":"list","marker":{"kind":"bullet","char":"*"},"spacing":"tight","ch'
            . 'ildren":[{"type":"list_item","children":[{"type":"list","marker":{"kind":"b'
            . 'ullet","char":"*"},"spacing":"tight","children":[{"type":"list_item","child'
            . 'ren":[{"type":"paragraph"}]}]}]}]},{"type":"thematic_break"}]');
    }

    /**
     * @return void
     */
    public function testC2B161FrontmatterOnLineOne(): void
    {
        $this->assertTree("---\na: 1\n---\ntext", '[{"type":"frontmatter","format":"yaml","value":"a: 1\\n"},{"type":"paragraph"}]');
    }

    /**
     * @return void
     */
    public function testC2B218BomSkippedAndNulKept(): void
    {
        $this->assertTree("\u{FEFF}a\0b", '[{"type":"paragraph"}]');
    }

    /**
     * @return void
     */
    public function testC2A082CaptionIsImageParagraphSlot(): void
    {
        $block = self::encode("![x](a.png)\n^ The caption")['children'][0];
        $this->assertSame('paragraph', $block['type']);
        $this->assertSame('image', $block['role']);
        $this->assertSame('caption', $block['caption']['type']);
    }

    /**
     * @return void
     */
    public function testC2B147CaptionSetsTableCaption(): void
    {
        $this->assertTree("|a|\n^ Cap", '[{"type":"table","columns":[{"type":"table_column"}],"caption":{"type":"cap'
            . 'tion","children":[{"type":"paragraph"}]},"children":[{"type":"table_body","'
            . 'children":[{"type":"table_row","children":[{"type":"table_cell","header":fa'
            . 'lse,"children":[{"type":"cell_paragraph"}]}]}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B138CommentBlockAndCommentOnlyLine(): void
    {
        $this->assertTree("%%%\nhidden\n%%%\n\n%% line", '[{"type":"comment","form":"block","value":"hidden"},{"type":"comment","form'
            . '":"line","value":"line"}]');
    }

    /**
     * @return void
     */
    public function testC2B154HeadingTakesIndentedContinuationLines(): void
    {
        $this->assertTree(
            "# Title\n  more\n\n# Two\n# Three",
            '[{"type":"heading","level":1},{"type":"heading","level":1},{"type":"heading","level":1}]',
        );
    }

    /**
     * @return void
     */
    public function testC2B016DescriptionListTermAndDescriptions(): void
    {
        $this->assertTree(
            ":: term\nmore term text\n: first\n\n: second",
            '[{"type":"definition_list","children":[{"type":"definition_term"},{"type":"'
            . 'definition_description","children":[{"type":"paragraph"}]},{"type":"definit'
            . 'ion_description","children":[{"type":"paragraph"}]}]}]',
        );
    }

    /**
     * @return void
     */
    public function testC2B087FootnoteBodyNeedsIndent(): void
    {
        $this->assertTree("[^n]: note\n    more\n\nafter", '[{"type":"footnote","key":"n","namespace":"footnote","children":[{"type":"p'
            . 'aragraph"}]},{"type":"paragraph"}]');
    }

    /**
     * @return void
     */
    public function testC2B083LinkAndAbbreviationDefinitions(): void
    {
        $this->assertTree(
            "[ref]: https://x.test \"T\"\n\n*[HTML]: Hyper Text",
            '[{"type":"link_reference_definition","key":"ref","namespace":"link","href":'
            . '"https://x.test","tooltip":"T"},{"type":"abbreviation_def","key":"HTML","na'
            . 'mespace":"abbr","expansion":"Hyper Text"}]',
        );
    }

    /**
     * @return void
     */
    public function testC2B116RawFenceFormat(): void
    {
        $this->assertTree("```=html\n<b>\n```", '[{"type":"raw_block","format":"html","value":"<b>\\n"}]');
    }

    /**
     * @return void
     */
    public function testC2B117CodeFenceLanguageTitleLabel(): void
    {
        $this->assertTree("```php \"File\" [lst]\nx\n```", '[{"type":"code_block","value":"x\\n","syntax":"php","title":{"type":"title"'
            . '},"groupLabel":"lst"}]');
    }

    /**
     * @return void
     */
    public function testC2B123LineBlockLines(): void
    {
        $this->assertTree("::: |\na\n  b\n:::", '[{"type":"line_block","children":[{"type":"line"},{"type":"line"}]}]');
    }

    /**
     * @return void
     */
    public function testC2A068HardBreakSigilAddsClass(): void
    {
        $this->assertTree("::: \\\na\nb\n:::", '[{"type":"div","attrs":{"classes":["hardbreaks"]},"children":[{"type":"paragraph"}]}]');
    }

    /**
     * @return void
     */
    public function testC2B024LazyLineContinuesQuotedParagraph(): void
    {
        $this->assertTree("> a\nb", '[{"type":"block_quote","children":[{"type":"paragraph"}]}]');
    }

    /**
     * @return void
     */
    public function testC2B052ThreeBlankLinesEndListAndLooseness(): void
    {
        $this->assertTree("- a\n- b\n\n- c\n\n\n\n- d", '[{"type":"list","marker":{"kind":"bullet","char":"-"},"spacing":"loose","ch'
            . 'ildren":[{"type":"list_item","children":[{"type":"paragraph"}]},{"type":"li'
            . 'st_item","children":[{"type":"paragraph"}]},{"type":"list_item","children":'
            . '[{"type":"paragraph"}]}]},{"type":"list","marker":{"kind":"bullet","char":"'
            . '-"},"spacing":"tight","children":[{"type":"list_item","children":[{"type":"'
            . 'paragraph"}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B201IncludeDirective(): void
    {
        $this->assertTree(
            "{{ data.csv @code:csv }}\nnext line",
            '[{"type":"include","path":"data.csv","options":{"code":"csv"}},{"type":"paragraph"}]',
        );
    }

    /**
     * @return void
     */
    public function testC2B180DelimitedTableFromCsvFence(): void
    {
        $this->assertTree(
            "{table}\n```csv\na,b\n\"c,d\",e\n```",
            '[{"type":"table","columns":[{"type":"table_column"},{"type":"table_column"}'
            . '],"children":[{"type":"table_head","children":[{"type":"table_row","childre'
            . 'n":[{"type":"table_cell","header":true,"children":[{"type":"cell_paragraph"'
            . '}]},{"type":"table_cell","header":true,"children":[{"type":"cell_paragraph"'
            . '}]}]}]},{"type":"table_body","children":[{"type":"table_row","children":[{"'
            . 'type":"table_cell","header":false,"children":[{"type":"cell_paragraph"}]},{'
            . '"type":"table_cell","header":false,"children":[{"type":"cell_paragraph"}]}]'
            . '}]}]}]',
        );
    }

    /**
     * @return void
     */
    public function testC2B091AttributeLineAppliesToNextBlock(): void
    {
        $this->assertTree("{#id .c}\npara\n\n{.lonely}", '[{"type":"paragraph","attrs":{"id":"id","classes":["c"]}},{"type":"paragraph"}]');
    }

    /**
     * @return void
     */
    public function testC2B021OrderedMarkerPastOneStaysLazyInQuoteInItem(): void
    {
        $this->assertTree("> - > a\n>   2. b", '[{"type":"block_quote","children":[{"type":"list","marker":{"kind":"bullet"'
            . ',"char":"-"},"spacing":"tight","children":[{"type":"list_item","children":['
            . '{"type":"block_quote","children":[{"type":"paragraph"}]}]}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B108CommentOpenerTextKeepsTrailingSpaces(): void
    {
        $this->assertTree("%%%x  \nbody\n%%%", '[{"type":"comment","form":"block","value":"x  \\nbody"}]');
    }

    /**
     * @return void
     */
    public function testC2B166RowWithTrailingCommentThenPipeLine(): void
    {
        // C2-A-086: the comment after the last row belongs to the table.
        $expected = '[{"type":"table","columns":[{"type":"table_column"}],';
        $expected .= '"children":[{"type":"table_body","children":[{"type":"table_row",';
        $expected .= '"children":[{"type":"table_cell","header":false,"children":[{"type":"cell_paragraph"}]}]}]},';
        $expected .= '{"type":"comment","form":"line","value":""}]},{"type":"paragraph"}]';
        $this->assertTree("|a| %%\n|", $expected);
    }

    /**
     * @return void
     */
    public function testC2B029FenceLeftOfContentColumnStripsToOpener(): void
    {
        // G-blocks-7.
        $this->assertTree("- a\n\n ```\n  code\n ```", '[{"type":"list","marker":{"kind":"bullet","char":"-"},"spacing":"tight","ch'
            . 'ildren":[{"type":"list_item","children":[{"type":"paragraph"},{"type":"code'
            . '_block","value":" code\\n"}]}]}]');
    }

    /**
     * @return void
     */
    public function testC2B218NulInCodeReadAsReplacementCharacter(): void
    {
        $tree = self::encode("```\nx\0y\n```");
        $this->assertSame("x\u{FFFD}y\n", self::dig($tree, 'children', 0, 'value'));
    }

    /**
     * @return void
     */
    public function testBomIsNotPartOfTheFirstBlock(): void
    {
        $tree = self::encode("\u{FEFF}a  ");
        $this->assertSame(3, self::dig($tree, 'children', 0, 'pos', 'startOffset'));
        // G-blocks-2: a span ends at the untrimmed line end.
        $this->assertSame(6, self::dig($tree, 'children', 0, 'pos', 'endOffset'));
    }

    /**
     * @return void
     */
    public function testC2B190DepthCapMakesOpenerText(): void
    {
        $node = self::encode(str_repeat('> ', 205) . 'x');
        $depth = 0;
        while (self::dig($node, 'children', 0, 'type') === 'block_quote') {
            $node = self::dig($node, 'children', 0);
            $depth++;
        }
        $this->assertSame(200, $depth);
        $this->assertSame('paragraph', self::dig($node, 'children', 0, 'type'));
        $this->assertSame(400, self::dig($node, 'children', 0, 'pos', 'startOffset'));
    }

    /**
     * @return void
     */
    public function testNoExceptionOnHostileInput(): void
    {
        $pieces = [
            "\n", ' ', "\t", '>', '-', '*', '1.', 'a)', ':::', '```',
            '%%%', '%%', '|', '=', '<', '^', '{', '}', '.',
            '[^x]:', '[x]:', '*[A]:', '^ ', '#', '{{ ', ' }}', '![x](y)', '$$`x`', '---',
            "\0", "\r", "\u{FEFF}", "\xff", '+',
        ];
        mt_srand(7);
        for ($doc = 0; $doc < 300; $doc++) {
            $src = '';
            $n = mt_rand(1, 80);
            for ($k = 0; $k < $n; $k++) {
                $src .= $pieces[mt_rand(0, count($pieces) - 1)];
            }
            $sink = new TreeSink($src);
            (new BlockParser($sink))->parse($src);
            $this->assertNotNull($sink->root);
        }
    }
}
