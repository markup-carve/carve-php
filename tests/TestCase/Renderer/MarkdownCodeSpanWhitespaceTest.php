<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownCodeSpanWhitespaceTest extends TestCase
{
    public function testCodeSpansKeepSignificantWhitespace(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/markdown-code-span-whitespace.json'), true, 512, JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        $renderer = new MarkdownRenderer();
        foreach ($cases as $case) {
            $document = $converter->parse($case['template']);
            $this->setCodeValue($document, $case['value']);
            $this->assertSame($case['markdown'], $renderer->render($document), json_encode($case, JSON_THROW_ON_ERROR));
        }
    }

    private function setCodeValue(Node $node, string $value): void
    {
        if ($node instanceof Code) {
            $node->setContent($value);
        }
        foreach ($node->getChildren() as $child) {
            $this->setCodeValue($child, $value);
        }
    }
}
