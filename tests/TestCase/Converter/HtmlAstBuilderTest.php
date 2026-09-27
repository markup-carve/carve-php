<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use LogicException;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use PHPUnit\Framework\TestCase;

class HtmlAstBuilderTest extends TestCase
{
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
