<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Tabs;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Candidate4Test extends TestCase
{
    /**
     * @var string
     */
    private const DOC = '{"type":"document","astVersion":"2.0",%s"children":[%s]}';

    /**
     * @var string
     */
    private const EXT = '"extensions":[{"name":"org.example.n","version":"1","required":false,"slots":[]}],';

    private static function cell(string $children): string
    {
        return '{"type":"table","columns":[{"type":"table_column"}],"children":[{"type":"table_body","children":['
            . '{"type":"table_row","children":[{"type":"table_cell","header":false,"children":[' . $children . ']}]}]}]}';
    }

    private static function source(string $form, string $name, string $extra = ''): string
    {
        $type = $form === 'inline' ? 'inline_extension' : 'block_extension';
        $text = '{"type":"text","value":"safe"}';
        $fallback = $form === 'inline' ? $text : '{"type":"paragraph","children":[' . $text . ']}';
        $node = '{"type":"' . $type . '","name":"org.example.n","version":"1","children":[],"fallback":[' . $fallback . '],'
            . '"source":{"form":"' . $form . '","name":' . json_encode($name) . ',"body":"x"' . $extra . '}}';

        return $form === 'inline' ? '{"type":"paragraph","children":[' . $node . ']}' : $node;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedTables(): array
    {
        return [
            'no children' => ['', 'table-cell-children'],
            'two paragraphs' => [
                '{"type":"cell_paragraph","children":[{"type":"text","value":"a"}]},'
                    . '{"type":"cell_paragraph","children":[{"type":"text","value":"b"}]}',
                'table-cell-children',
            ],
            'paragraph beside a block' => [
                '{"type":"cell_paragraph","children":[]},{"type":"paragraph","children":[{"type":"text","value":"b"}]}',
                'table-cell-children',
            ],
        ];
    }

    #[DataProvider('refusedTables')]
    public function testC2A091ReaderRefusesTheChildrenOfACell(string $children, string $reason): void
    {
        try {
            TreeReader::read(sprintf(self::DOC, '', self::cell($children)));
            $this->fail('accepted');
        } catch (TreeError $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function testC2A091CellOfBlocksAndOneCellParagraphAreRead(): void
    {
        $tree = TreeReader::read(sprintf(self::DOC, '', self::cell('{"type":"cell_paragraph","children":[]}')));
        $this->assertSame('document', $tree->type);
        $blocks = '{"type":"paragraph","children":[{"type":"text","value":"a"}]},{"type":"thematic_break"}';
        $tree = TreeReader::read(sprintf(self::DOC, '', self::cell($blocks)));
        $this->assertSame('document', $tree->type);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function sourceNames(): array
    {
        return [
            'raw fence' => ['fence', '=html', false],
            'fence with a space' => ['fence', 'a b', false],
            'code fence' => ['fence', 'python', true],
            'csv fence' => ['fence', 'csv', true],
            'admonition div' => ['div', 'note', false],
            'directive div' => ['div', 'toc', false],
            'figure div' => ['div', 'figure', false],
            'plain div' => ['div', 'tabs', true],
            'div with a dot' => ['div', 'a.b', false],
            'inline digit first' => ['inline', '1a', false],
            'inline' => ['inline', 'kbd', true],
            'inline underscore' => ['inline', '_a-1', true],
        ];
    }

    #[DataProvider('sourceNames')]
    public function testC2X025ReaderRefusesASourceNameNoMatcherOfItsFormClaims(string $form, string $name, bool $read): void
    {
        try {
            TreeReader::read(sprintf(self::DOC, self::EXT, self::source($form, $name)));
            $this->assertTrue($read, 'accepted');
        } catch (TreeError $e) {
            $this->assertFalse($read, $e->getMessage());
            $this->assertSame('extension-source-name', $e->reason);
        }
    }

    public function testC2X025FigureDivWithATitleIsAPlainDiv(): void
    {
        $tree = TreeReader::read(sprintf(self::DOC, self::EXT, self::source('div', 'figure', ',"title":"T"')));
        $this->assertSame('document', $tree->type);
    }

    public function testC2A124ReaderRefusesAnyOtherFieldOnAnUnqualifiedInlineExtension(): void
    {
        $node = '{"type":"inline_extension","name":"kbd","children":[{"type":"text","value":"K"}]%s}';
        foreach ([',"fallback":[{"type":"text","value":"FB"}]', ',"props":{"a":1}', ',"version":"1"'] as $extra) {
            try {
                TreeReader::read(sprintf(self::DOC, '', '{"type":"paragraph","children":[' . sprintf($node, $extra) . ']}'));
                $this->fail('accepted ' . $extra);
            } catch (TreeError $e) {
                $this->assertNotSame('', $e->reason);
            }
        }
        $tree = TreeReader::read(sprintf(self::DOC, '', '{"type":"paragraph","children":['
            . sprintf($node, ',"attrs":{"classes":["c"]}') . ']}'));
        $this->assertSame('document', $tree->type);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function smartSources(): array
    {
        return [
            'em dash of two' => ['em_dash', '—', '--', false],
            'em dash' => ['em_dash', '—', '---', true],
            'en dash' => ['en_dash', '–', '--', true],
            'ellipsis' => ['ellipsis', '…', '...', true],
            'ellipsis of four' => ['ellipsis', '…', '....', false],
            'right quote' => ['right_single_quote', '’', "'", true],
            'right quote typed' => ['right_single_quote', '’', '’', false],
        ];
    }

    #[DataProvider('smartSources')]
    public function testC2A098ReaderRefusesASourceOtherThanTheOneOfItsKind(string $kind, string $glyph, string $source, bool $read): void
    {
        $node = '{"type":"paragraph","children":[{"type":"smart_punctuation","kind":"' . $kind . '","glyph":"' . $glyph
            . '","source":' . json_encode($source) . '}]}';
        try {
            TreeReader::read(sprintf(self::DOC, '', $node));
            $this->assertTrue($read, 'accepted');
        } catch (TreeError $e) {
            $this->assertFalse($read, $e->getMessage());
            $this->assertSame('smart-punctuation-source', $e->reason);
        }
    }

    public function testC2H091BacklinkJoinsAParagraphOfAnUnresolvedImageReference(): void
    {
        $html = Carve::toHtml("a[^n]\n\n[^n]: ![a][nope]");
        $this->assertStringContainsString('<p>![a][nope]<a href="#fnref1" role="doc-backlink"', $html);
        $html = Carve::toHtml("a[^n]\n\n[^n]: ![a](x.png)");
        $this->assertStringContainsString("<img src=\"x.png\" alt=\"a\">\n      <p><a href=\"#fnref1\"", $html);
    }

    public function testC2H161DeniedUrlListTokenIsReported(): void
    {
        $result = Carve::renderWithDiagnostics('|{background="https://x.org/b.png"} a |', ['urlPolicy' => ['allow' => ['mailto']]]);
        $this->assertStringContainsString('<td background="">a</td>', $result->html);
        $this->assertContains('destination-denied', $result->codes());
        $result = Carve::renderWithDiagnostics('[a]{ping="java script:x"}');
        $this->assertStringContainsString('ping=""', $result->html);
        $this->assertNotContains('destination-denied', $result->codes());
    }

    public function testC2A115ReferenceTooltipIsAResultField(): void
    {
        $link = '{"type":"link","key":"k","namespace":"link","refForm":"full","tooltip":"X","children":[{"type":"text","value":"a"}]}';
        $definition = '{"type":"link_reference_definition","key":"k","namespace":"link","href":"/u","tooltip":"T"}';
        $json = sprintf(self::DOC, '', '{"type":"paragraph","children":[' . $link . ']},' . $definition);
        $this->assertSame('<p><a href="/u" title="T">a</a></p>', HtmlRenderer::render(TreeReader::read($json), Options::from([])));
        $other = json_decode(str_replace('"tooltip":"X"', '"tooltip":"Y"', $json), true);
        $this->assertTrue(TreeEquality::equal(json_decode($json, true), $other));
        $direct = json_decode(str_replace('"key":"k","namespace":"link","refForm":"full",', '"href":"/v",', $json), true);
        $retitled = json_decode(str_replace('"tooltip":"X"', '"tooltip":"Y"', (string)json_encode($direct)), true);
        $this->assertFalse(TreeEquality::equal($direct, $retitled));
    }

    public function testC2A162EqualitySkipsGeometryAndCoalescesText(): void
    {
        $a = json_decode(Carve::toJson("a *b*\n\n[x][k]\n\n[k]: /u"), true);
        $b = $a;
        $b['children'][0]['children'][0] = ['type' => 'text', 'value' => 'a'];
        array_splice($b['children'][0]['children'], 1, 0, [['type' => 'text', 'value' => ' ']]);
        $b['children'][0]['children'][0]['value'] = 'a';
        $b['children'][1]['children'][0]['href'] = '/stale';
        unset($b['srcByteLength']);
        $this->assertTrue(TreeEquality::equal($a, $b));
        $b['children'][0]['children'][1]['value'] = '  ';
        $this->assertFalse(TreeEquality::equal($a, $b));
    }

    public function testC2H019ParagraphOfBreaksAloneKeepsItsBreak(): void
    {
        $this->assertSame("<p><br>\n</p>", Carve::toHtml('\\'));
        $html = Carve::toHtml("a^[\n]");
        $this->assertStringContainsString("<p>\n<a href=\"#fnref1\" role=\"doc-backlink\"", $html);
    }

    public function testC2B166TrailingWhitespaceAfterARowKeepsTheTable(): void
    {
        $html = Carve::toHtml("| a |\t \n+ b |  \n| c |");
        $this->assertSame("<table>\n  <tbody>\n    <tr><td>a b</td></tr>\n    <tr><td>c</td></tr>\n  </tbody>\n</table>", $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function emptyExtensionContainers(): array
    {
        return ['props' => ['"props":{}'], 'slots' => ['"slots":{}'], 'payload' => ['"payload":[]']];
    }

    #[DataProvider('emptyExtensionContainers')]
    public function testC2A058EmptyExtensionContainerIsRefused(string $field): void
    {
        $node = '{"type":"block_extension","name":"org.example.n","version":"1",' . $field
            . ',"children":[],"fallback":[{"type":"paragraph","children":[{"type":"text","value":"a"}]}]}';
        $this->expectException(TreeError::class);
        TreeReader::read(sprintf(self::DOC, self::EXT, $node));
    }

    public function testC2B219OrderedStartAboveNineDigitsIsRefused(): void
    {
        $list = '{"type":"list","marker":{"kind":"ordered","style":"1","delimiter":".","start":%d},"spacing":"tight",'
            . '"children":[{"type":"list_item","children":[{"type":"paragraph","children":[{"type":"text","value":"a"}]}]}]}';
        $this->assertSame('document', TreeReader::read(sprintf(self::DOC, '', sprintf($list, 999999999)))->type);
        $this->expectException(TreeError::class);
        TreeReader::read(sprintf(self::DOC, '', sprintf($list, 1000000000)));
    }

    /**
     * @var string
     */
    private const TABS = ":::: tabs\n::: tab \"A\"\n# Head\n\nBody[^m] see </#Head>.\n:::\n::::\n\n[^m]: M note.\n";

    public function testC2X032ResolutionVisitsOneViewOfAnExtensionNode(): void
    {
        $result = Carve::renderWithDiagnostics(self::TABS, ['extensions' => Tabs::extensions()]);
        $this->assertStringContainsString('<h1 id="Head">Head</h1>', $result->html);
        $this->assertSame(1, substr_count($result->html, 'id="fnref1"'));
        $this->assertSame(1, substr_count($result->html, 'role="doc-backlink"'));
        $this->assertStringContainsString('see <a href="#Head">Head</a>', $result->html);
        $this->assertNotContains('duplicate-id', $result->codes());
        $this->assertContains('extension-fallback-rendered', $result->codes());
        $html = Carve::toHtml(self::TABS, ['extensions' => Tabs::extensions(true)]);
        $this->assertStringContainsString('<div class="tab-panel" role="group" aria-label="A"><h1 id="Head">Head</h1>', $html);
        $this->assertSame(1, substr_count($html, 'id="fnref1"'));
        $this->assertStringContainsString('<input type="radio" name="tabs-1" id="tabs-1-0" checked><label for="tabs-1-0">A</label>', $html);
    }

    public function testC2H165DegradedBlockExtensionWritesItsFallbackBlocks(): void
    {
        $options = ['extensions' => Tabs::extensions(), 'profile' => 'comment'];
        $html = Carve::toHtml(":::: tabs\n::: tab \"A\"\n# Head\n\nBody\n:::\n::::\n", $options);
        $this->assertSame("<p>A</p>\n<p># Head</p>\n<p>Body</p>", $html);
    }

    public function testC2A058TabsTransformEmitsNoEmptyContainer(): void
    {
        $json = Carve::toJson(":::: tabs\n::: tab \"A\"\nx\n:::\n::::\n", ['extensions' => Tabs::extensions()]);
        $this->assertStringNotContainsString('"props":{}', $json);
        $this->assertStringNotContainsString('"slots":{}', $json);
        $this->assertStringNotContainsString('"payload":[]', $json);
        $this->assertSame('document', TreeReader::read($json)->type);
    }

    public function testC2X040TabsClaimOnlyTabDivsWithATitle(): void
    {
        $tree = json_decode(Carve::toJson(":::: tabs\n::: tab\nx\n:::\n::::\n", ['extensions' => Tabs::extensions()]), true);
        $this->assertSame('div', $tree['children'][0]['type']);
        $tree = json_decode(Carve::toJson("::: tab \"A\"\nx\n:::\n", ['extensions' => Tabs::extensions()]), true);
        $this->assertSame('div', $tree['children'][0]['type'], 'C2-X-039: a tab div elsewhere stays a core div');
    }

    public function testC2L011LayoutVisitsTheSlotsOfTheTransformedTree(): void
    {
        $source = ":::: tabs\n::: tab \"A\"\n# Head\n\nBody [x].\n:::\n::::\n\n[x]: /u\n";
        $result = Carve::parseWithLayout($source, ['extensions' => Tabs::extensions()]);
        $paths = array_column($result->layout['nodes'], 'path');
        $this->assertContains('/children/0/slots/tabs/0', $paths);
        $this->assertContains('/children/0/slots/tabs/0/slots/body/0', $paths);
        $this->assertContains('/children/0/slots/tabs/0/slots/body/1/children/1', $paths);
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('/fallback', $path);
        }
        $tree = json_decode(Carve::toJson($source, ['extensions' => Tabs::extensions()]), true);
        $this->assertCount(count($paths), Carve::validateLayout((string)json_encode($result->layout), $tree)['nodes']);
    }

    public function testC2X045TabsSourceBodyKeepsTheNestedTabDivs(): void
    {
        $source = ":::: tabs\n::: tab \"Install\"\nRun setup.\n:::\n\n::: tab \"Use\"\nOpen the app.\n:::\n::::\n";
        $tree = json_decode(Carve::toJson($source, ['extensions' => Tabs::extensions()]), true);
        $tabs = $tree['children'][0];
        $this->assertSame(
            ['form' => 'div', 'name' => 'tabs', 'body' => "::: tab \"Install\"\nRun setup.\n:::\n\n::: tab \"Use\"\nOpen the app.\n:::\n"],
            $tabs['source'],
        );
        $this->assertSame(
            ['form' => 'div', 'name' => 'tab', 'body' => "Run setup.\n", 'title' => 'Install'],
            $tabs['slots']['tabs'][0]['source'],
        );
        $this->assertSame("Open the app.\n", $tabs['slots']['tabs'][1]['source']['body']);
    }
}
