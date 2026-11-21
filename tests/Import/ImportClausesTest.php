<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Import\HtmlParser;
use MarkupCarve\Carve\Import\ImportInputError;
use MarkupCarve\Carve\Import\ImportResult;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\TestCase;

/**
 * Clauses of import-html.md (C2-M) that no corpus example holds, each test named by its clause.
 */
final class ImportClausesTest extends TestCase
{
    /**
     * @param string $html
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private static function children(string $html, array $options = []): array
    {
        return Corpus::sorted(HtmlImporter::import($html, $options)->document['children']);
    }

    /**
     * @param array<mixed> $expected
     * @param string $html
     * @param array<string, mixed> $options
     *
     * @return void
     */
    private function assertImports(array $expected, string $html, array $options = []): void
    {
        $this->assertSame(Corpus::sorted($expected), self::children($html, $options));
    }

    /**
     * @param string $value
     *
     * @return array<string, mixed>
     */
    private static function para(string $value): array
    {
        return ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]];
    }

    public function testC2M001ResultHoldsAValidTreeAndTheRenderLossChannel(): void
    {
        $result = Carve::importHtml('<p onclick="x()">a</p>');
        $this->assertInstanceOf(ImportResult::class, $result);
        $this->assertSame('document', $result->tree->type);
        $this->assertSame(['import-attribute-dropped'], $result->codes());
        $entry = $result->diagnostics[0]->toArray();
        $this->assertSame('render-loss', $entry['channel']);
        $this->assertSame('warning', $entry['severity']);
        $this->assertSame('p', $entry['foreignType']);
    }

    public function testC2M002InputThatStartsWithBodyIsAFragment(): void
    {
        $result = HtmlImporter::import('<body class="x"><p>a</p></body>');
        $this->assertSame(['import-attribute-dropped'], $result->codes());
        $this->assertSame([self::para('a')], $result->document['children']);
    }

    public function testC2M004DocumentLanguageAndDirection(): void
    {
        $result = HtmlImporter::import('<!-- c --><html lang="de" dir="rtl"><body lang="fr" data-x="1"><p>a</p></body></html>');
        $this->assertSame('fr', $result->document['lang']);
        $this->assertSame('rtl', $result->document['dir']);
        $this->assertContains('import-attribute-dropped', $result->codes());
    }

    public function testC2M005HeadAndDoctypeAreDroppedOnceEach(): void
    {
        $result = HtmlImporter::import('<!DOCTYPE html><html><head><title>T</title><meta charset="utf-8"></head><body>x</body></html>');
        $this->assertSame(['import-element-dropped', 'import-element-dropped'], $result->codes());
    }

    public function testC2M006TooDeepInputIsRefused(): void
    {
        $this->expectException(ImportInputError::class);
        HtmlImporter::import(str_repeat('<blockquote>', 300) . 'x');
    }

    public function testC2M006JsonDecoderDepthIsAnImportError(): void
    {
        $this->expectException(ImportInputError::class);
        $this->expectExceptionMessage('The input exceeds the nesting limits of a tree (C2-A-171)');

        HtmlImporter::import(str_repeat('<blockquote>', 4000) . 'a' . str_repeat('</blockquote>', 4000));
    }

    public function testC2M006DeepestBlockTreeStillImports(): void
    {
        $html = str_repeat('<blockquote>', 200) . 'a' . str_repeat('</blockquote>', 200);

        $this->assertSame('document', HtmlImporter::import($html)->tree->type);
    }

    public function testC2M006MixedPlainBlockOpenersAreRefused(): void
    {
        $this->expectException(ImportInputError::class);

        HtmlImporter::import(str_repeat('<div><blockquote>', 101) . 'a');
    }

    public function testC2M006ChecksOptionsBeforeTheDepthFastPath(): void
    {
        $this->expectException(OptionError::class);

        HtmlImporter::import(str_repeat('<blockquote>', 201) . 'a', ['rawHtml' => 'bad']);
    }

    public function testC2M006EmptyListDoesNotIncreaseImportedDepth(): void
    {
        $html = str_repeat('<blockquote>', 200) . '<ul></ul>' . str_repeat('</blockquote>', 200);

        $this->assertSame('document', HtmlImporter::import($html)->tree->type);
    }

    public function testC2M013RunOfImagesGivesOneImageParagraphEach(): void
    {
        $image = static fn (string $src): array => [

            'type' => 'paragraph',
            'role' => 'image',
            'children' => [
                ['type' => 'image', 'src' => $src, 'alt' => ''],
            ],
        ];
        $this->assertImports([$image('/a'), $image('/b')], '<img src="/a"> <img src="/b">');
    }

    public function testC2M014BlockElementInInlineContentIsASpan(): void
    {
        $result = HtmlImporter::import('<em><p class="c">x</p></em>');
        $span = ['type' => 'span', 'children' => [['type' => 'text', 'value' => 'x']], 'attrs' => ['classes' => ['c']]];
        $paragraph = ['type' => 'paragraph', 'children' => [['type' => 'emphasis', 'children' => [$span]]]];
        $this->assertSame([$paragraph], $result->document['children']);
        $this->assertContains('import-element-mapped', $result->codes());
    }

    public function testC2M017LineFeedBeforeBreakIsLayout(): void
    {
        $result = HtmlImporter::import("<p>a\n<br>\nb</p>");
        $this->assertSame([
            [

                'type' => 'paragraph',
                'children' => [
                    ['type' => 'text', 'value' => 'a'], ['type' => 'hard_break'], ['type' => 'text', 'value' => 'b'],
                ],
            ],
        ], $result->document['children']);
        $this->assertContains('import-whitespace-normalized', $result->codes());
        $this->assertSame('hint', $result->diagnostics[0]->severity);
    }

    public function testC2M024DivWithHeadingRoleAboveSix(): void
    {
        $this->assertImports(
            [['type' => 'heading', 'level' => 7, 'children' => [['type' => 'text', 'value' => 'x']]]],
            '<div role="heading" aria-level="7">x</div>',
        );
    }

    public function testC2M030CodeBlockValueEndsWithALineFeed(): void
    {
        $result = HtmlImporter::import('<pre><code class="language-php x" data-k="1">a <b>b</b></code></pre>');
        $this->assertSame([['type' => 'code_block', 'value' => "a b\n", 'syntax' => 'php']], $result->document['children']);
        $this->assertSame(['import-attribute-dropped'], $result->codes());
    }

    /**
     * TODO entry 16: an ordered start outside 0 to 999999999 is dropped and reported (C2-B-219).
     */
    public function testC2M031OrderedStartOutOfRangeIsDropped(): void
    {
        foreach (['1234567890', '-3', '99999999999999999999'] as $start) {
            $result = HtmlImporter::import('<ol start="' . $start . '"><li>a</li></ol>');
            $marker = $result->document['children'][0]['marker'];
            $this->assertSame(['kind' => 'ordered', 'style' => '1', 'delimiter' => '.'], $marker, $start);
            $this->assertSame(['import-attribute-dropped'], $result->codes(), $start);
        }
        $edge = HtmlImporter::import('<ol start="999999999"><li>a</li></ol><ol start="0"><li>b</li></ol>');
        $this->assertSame(999999999, $edge->document['children'][0]['marker']['start']);
        $this->assertSame(0, $edge->document['children'][1]['marker']['start']);
        $this->assertSame([], $edge->codes());
    }

    public function testC2M032TaskStateAttributeGivesTheMark(): void
    {
        $result = HtmlImporter::import('<ul><li data-task-state="?"><input type="checkbox"> a</li><li data-task-state="z">'
            . '<input type="checkbox" checked> b</li></ul>');
        $items = $result->document['children'][0]['children'];
        $this->assertSame('?', $items[0]['task']['mark']);
        $this->assertSame('x', $items[1]['task']['mark']);
        $this->assertSame([self::para('a')], $items[0]['children']);
        $this->assertContains('import-attribute-dropped', $result->codes());
    }

    public function testC2M033ItemWithTwoBlocksMakesTheListLoose(): void
    {
        $this->assertSame('loose', self::children('<ul><li>a<p>b</p></li><li>c</li></ul>')[0]['spacing']);
        $this->assertSame('tight', self::children('<ul><li>a<ul><li>b</li></ul></li><li>c</li></ul>')[0]['spacing']);
    }

    public function testC2M039RowSpanZeroAndPastTheGroup(): void
    {
        $result = HtmlImporter::import('<table><tr><td rowspan="0">a</td><td rowspan="3">b</td></tr><tr><td>c</td></tr></table>');
        $row = $result->document['children'][0]['children'][0]['children'][0]['children'];
        $this->assertArrayNotHasKey('rowspan', $row[0]);
        $this->assertSame(2, $row[1]['rowspan']);
        // C2-W-145: the clamped row span is written with its mark.
        $this->assertSame(['import-structure-repaired', 'import-structure-repaired'], $result->codes());
        $this->assertSame("| a | b |\n| c | ^ |\n", $result->source);
    }

    public function testC2M040ColumnAlignmentInATableWithoutHead(): void
    {
        $table = self::children('<table><tr><td align="center">a</td></tr><tr><td align="center">b</td></tr></table>')[0];
        $this->assertSame([['align' => 'center', 'type' => 'table_column']], $table['columns']);
        $this->assertArrayNotHasKey('align', $table['children'][0]['children'][0]['children'][0]);
    }

    public function testC2M041ColumnWidthFromTheFirstColumnGroup(): void
    {
        $table = self::children('<table><colgroup><col style="width: 25%;"><col></colgroup><tr><td>a</td><td>b</td></tr></table>')[0];
        $this->assertSame([['type' => 'table_column', 'width' => 0.25], ['type' => 'table_column']], $table['columns']);
    }

    public function testC2M046WbrIsDroppedAndReported(): void
    {
        $this->assertSame(['import-element-dropped'], HtmlImporter::import('<p>a<wbr>b</p>')->codes());
    }

    public function testC2M048RubyPairsAndABaseWithoutAnnotation(): void
    {
        $result = HtmlImporter::import('<p><ruby>漢<rp>(</rp><rt>kan</rt><rp>)</rp>字</ruby></p>');
        $this->assertSame([
            [

                'type' => 'paragraph',
                'children' => [
                    [

                        'type' => 'ruby',
                        'pairs' => [
                            [

                                'type' => 'ruby_pair',
                                'base' => [['type' => 'text', 'value' => '漢']],
                                'annotation' => [['type' => 'text', 'value' => 'kan']],
                            ],
                        ],
                    ],
                    ['type' => 'text', 'value' => '字'],
                ],
            ],
        ], $result->document['children']);
        // C2-A-159: ruby has no source spelling, so the writer's loss follows the importer's (C2-M-074).
        $this->assertSame(['import-structure-repaired', 'structure-unspellable'], $result->codes());
    }

    public function testC2M049MathWithoutTexIsItsText(): void
    {
        $result = HtmlImporter::import('<p><math><mi>x</mi></math></p>');
        $this->assertSame([self::para('x')], $result->document['children']);
        $this->assertSame(['import-element-mapped'], $result->codes());
    }

    public function testC2M050LongDescriptionIsAParagraphWithOneLink(): void
    {
        $image = self::children('<p><img src="/i" alt="I" longdesc="/d"></p>')[0]['children'][0];
        $this->assertSame([
            [

                'children' => [['children' => [['type' => 'text', 'value' => '/d']], 'href' => '/d', 'type' => 'link']],
                'type' => 'paragraph',
            ],
        ], $image['longDescription']);
    }

    public function testC2M054NameThatIsNoKeyIsDropped(): void
    {
        if (!HtmlParser::html5()) {
            $this->markTestSkipped('libxml cuts the attribute name `data-ü` to `data-`, so the fallback keeps a key');
        }
        $result = HtmlImporter::import('<p data-ü="1">a</p>');
        $this->assertSame([self::para('a')], $result->document['children']);
        $this->assertSame(['import-attribute-dropped'], $result->codes());
    }

    public function testC2M061FootnoteSectionWithAnItemWithoutId(): void
    {
        $result = HtmlImporter::import('<p>x<sup><a href="#fn1" role="doc-noteref">1</a></sup></p><section role="doc-endnotes"><ol>'
            . '<li id="fn1"><p>N <a href="#fnref1" role="doc-backlink">↩</a></p></li><li><p>M</p></li></ol></section>');
        $children = $result->document['children'];
        $this->assertSame(['type' => 'footnote_ref', 'key' => '1', 'namespace' => 'footnote'], $children[0]['children'][1]);
        $this->assertSame(['type' => 'footnote', 'key' => '1', 'namespace' => 'footnote', 'children' => [self::para('N')]], $children[1]);
        $this->assertSame(['import-structure-repaired'], $result->codes());
    }

    public function testC2M064FootnoteSectionWithBlocksAfterItIsADirective(): void
    {
        $children = self::children('<section class="footnotes"><ol><li id="fn-a"><p>N</p></li></ol></section><p>after</p>');
        $this->assertSame(['children' => [], 'kind' => 'footnotes', 'type' => 'directive'], $children[0]);
        $this->assertSame('a', $children[2]['key']);
    }

    public function testC2M066RawHtmlOnKeepsRawElementsWithoutCarriers(): void
    {
        $this->assertImports(
            [['type' => 'raw_block', 'format' => 'html', 'value' => "<video src=\"/v\"></video>\n"]],
            '<video src="/v" data-carve-src="x"></video>',
            ['rawHtml' => true],
        );
        $off = HtmlImporter::import('<video src="/v"></video><input type="text">');
        $this->assertSame([], $off->document['children']);
        $this->assertSame(['import-element-dropped', 'import-element-dropped'], $off->codes());
    }

    public function testC2M069EmptyInlineNodeWithAttributesIsDroppedAndReported(): void
    {
        $result = HtmlImporter::import('<p>a<em class="c"></em></p>');
        $this->assertSame([self::para('a')], $result->document['children']);
        $this->assertSame(['import-structure-repaired'], $result->codes());
    }

    public function testC2M070ListWithoutItemsIsDropped(): void
    {
        $result = HtmlImporter::import('<ul></ul><dl></dl>');
        $this->assertSame([], $result->document['children']);
        $this->assertSame(['import-structure-repaired', 'import-structure-repaired'], $result->codes());
    }

    public function testC2M075AlignmentDeclarationIsTheKeyAlign(): void
    {
        $this->assertImports([
            [

                'type' => 'paragraph',
                'children' => [['type' => 'text', 'value' => 'a']],
                'attrs' => [
                    'keyValues' => [['align', 'right']],
                ],
            ],
        ], '<p style="text-align: right;">a</p>');
    }

    public function testC2M082StrictLossesMakesEveryEntryAnError(): void
    {
        $result = HtmlImporter::import('<p onclick="x">a</p>', ['strictLosses' => true]);
        $this->assertSame('error', $result->diagnostics[0]->severity);
    }
}
