<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use LogicException;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class HtmlAstBuilderTest extends TestCase
{
    use ScalingGuardTrait;

    public function testTableSectionsKeepTheirOwnRetainedRows(): void
    {
        $tree = (new HtmlAstBuilder())->build(
            '<table><tbody><tr><td>a</td></tr><tr><td></td></tr></tbody>'
            . '<tbody></tbody><tbody><tr><th>b</th></tr><tr><td>c</td></tr></tbody></table>',
        );
        $table = $tree['children'][0];
        self::assertCount(3, $table['rows']);
        self::assertSame([
            ['headRows' => 0, 'bodyRows' => 1],
            ['headRows' => 0, 'bodyRows' => 0],
            ['headRows' => 1, 'bodyRows' => 1],
        ], $table['rowGroups']['bodies']);
    }

    public function testRetainedSectionPathsCountTextAndCommentSiblings(): void
    {
        $builder = new HtmlAstBuilder();
        $builder->build('<table> <!-- gap --><tbody id="a"><tr><td>a</td></tr></tbody>'
            . ' <tbody id="b"><tr><td>b</td></tr></tbody></table>');
        self::assertSame([
            '/table[1]/tbody[3]' => ['id'],
            '/table[1]/tbody[5]' => ['id'],
        ], $builder->retainedTableAttributes());
    }

    #[Group('scaling')]
    public function testManyTableSectionsScaleLinearly(): void
    {
        $this->assertBuilderScales('<tbody><tr><td>x</td></tr></tbody>', '<table>', '</table>', 1024);
    }

    #[Group('scaling')]
    public function testAdjacentDefinitionListsScaleLinearly(): void
    {
        $this->assertBuilderScales('<dl><dt>t</dt><dd>d</dd></dl>', '', '', 1024);
    }

    private function assertBuilderScales(string $fragment, string $prefix, string $suffix, int $n): void
    {
        $builder = new HtmlAstBuilder();
        $this->assertConversionScalesLinearly(
            static function (string $html) use ($builder): void {
                $builder->build($html);
            },
            $prefix . str_repeat($fragment, $n) . $suffix,
            $prefix . str_repeat($fragment, $n * 4) . $suffix,
            $fragment,
            $n,
            $n * 4,
        );
    }

    public function testEachBuildOwnsItsDocumentAndDecisions(): void
    {
        $builder = new HtmlAstBuilder(importMode: 'roundtrip');
        $builder->build('<section><p id="first"></p><custom>raw</custom></section>');
        $firstDocument = $builder->builtDocument();
        $firstRaw = $builder->keptRawElements();
        $firstDropped = $builder->droppedEmptyElements();
        self::assertCount(1, $firstRaw);
        self::assertCount(1, $firstDropped);

        $html = '<p>second</p>';
        self::assertSame((new HtmlAstBuilder(importMode: 'roundtrip'))->build($html), $builder->build($html));
        self::assertNotSame($firstDocument, $builder->builtDocument());
        self::assertNotSame($firstRaw, $builder->keptRawElements());
        self::assertNotSame($firstDropped, $builder->droppedEmptyElements());
        self::assertCount(0, $builder->keptRawElements());
        self::assertCount(0, $builder->droppedEmptyElements());
    }

    public function testCompletedResultKeepsItsOwnDecisionsAfterAnotherBuild(): void
    {
        $builder = new HtmlAstBuilder(importMode: 'roundtrip', sourceSafe: false);
        $first = $builder->buildResult('<section><p id="first"></p><custom>raw</custom></section>');
        $second = $builder->buildResult('<p>second</p>');

        self::assertNotSame($first->session, $second->session);
        self::assertNotSame($first->session->builtDocument, $second->session->builtDocument);
        self::assertCount(1, $first->session->keptRawElements);
        self::assertCount(1, $first->session->droppedEmptyElements);
        self::assertCount(0, $second->session->keptRawElements);
        self::assertCount(0, $second->session->droppedEmptyElements);
    }

    public function testWriterTreeCannotBeExportedAsPublicAst(): void
    {
        $result = (new HtmlAstBuilder(sourceSafe: true))->buildResult('<p>:literal:</p>');
        $this->expectException(LogicException::class);
        $result->publicTree();
    }

    public function testItBuildsTheBasicSharedFixtureWithoutWritingCarve(): void
    {
        $fixture = dirname(__DIR__, 2) . '/spec/tests/html-import/basic';
        $html = file_get_contents($fixture . '/input.html');
        $expected = file_get_contents($fixture . '/expected.ast.json');
        $this->assertNotFalse($html);
        $this->assertNotFalse($expected);

        $actual = (new HtmlAstBuilder())->build($html);
        unset($actual['srcByteLength']);

        $this->assertSame(json_decode($expected, true, flags: JSON_THROW_ON_ERROR), $actual);
    }
}
