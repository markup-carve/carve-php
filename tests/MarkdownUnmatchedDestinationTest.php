<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownUnmatchedDestinationTest extends TestCase
{
    public function testUnmatchedClosingParenthesesRoundTrip(): void
    {
        $converter = new CarveConverter();
        $renderer = new MarkdownRenderer();
        $importer = new MarkdownToCarve();
        foreach (['/a\\)b', '/a\\)b\\)c', '/a]b\\)c'] as $destination) {
            $source = '[x](' . $destination . ')';
            $markdown = $renderer->render($converter->parse($source));
            self::assertSame($converter->parse($source)->getChildren()[0]->getChildren()[0]->getDestination(), $converter->parse($importer->convert($markdown))->getChildren()[0]->getChildren()[0]->getDestination());
            self::assertStringContainsString('\\)', $markdown);
        }
    }
}
