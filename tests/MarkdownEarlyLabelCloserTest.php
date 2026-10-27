<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownEarlyLabelCloserTest extends TestCase
{
    public function testExporterEscapesAClosingBracketInsideAnAngleRun(): void
    {
        $markdown = (new CarveConverter(renderer: new MarkdownRenderer()))->convert('[a </#x]> b](u)');
        self::assertSame("[a </#x\\]> b](u)\n", $markdown);
    }

    public function testImporterKeepsAnEarlyUnescapedCloserLiteral(): void
    {
        $importer = new MarkdownToCarve();
        $html = (new CarveConverter())->convert($importer->convert('[</#x]>](u)'));
        self::assertSame("<p>[&lt;/#x]&gt;](u)</p>\n", $html);
    }

    public function testEscapedCloserStillAllowsTheLink(): void
    {
        $importer = new MarkdownToCarve();
        $html = (new CarveConverter())->convert($importer->convert('[</#x\\]>](u)'));
        self::assertSame("<p><a href=\"u\">&lt;/#x]&gt;</a></p>\n", $html);
        self::assertSame('[a </#x]> b](u)', $importer->convert('[a </#x\\]> b](u)'));
    }
}
