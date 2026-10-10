<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class CodeSourceBoundariesTest extends TestCase
{
    private function replaceCode(Node $node, string $value): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Code && $child->getContent() === 'Q') {
                $child->setContent($value);
            } else {
                $this->replaceCode($child, $value);
            }
        }
    }

    public function testAdjacentCodeKeepsBothPayloads(): void
    {
        foreach (["a\\", "a\\\\\\"] as $value) {
            foreach (["`Q`{%  %}`b`\n", "*`Q`{%  %}`b`*\n"] as $source) {
                $converter = new CarveConverter();
                $document = $converter->parse($source);
                $this->replaceCode($document, $value);
                $written = (new CarveRenderer())->render($document);
                $this->assertSame($converter->render($document), $converter->convert($written));
            }
        }
    }

    public function testBlankLineCodeIsRefusedOutsideAVerse(): void
    {
        foreach (["a\n\nb", "a\r\rb", "\n\nb", "a\n\n"] as $value) {
            $converter = new CarveConverter();
            $document = $converter->parse("`Q`\n");
            $this->replaceCode($document, $value);
            try {
                (new CarveRenderer())->render($document);
                $this->fail('The paragraph code value must be refused');
            } catch (SourceUnspellableException $error) {
                $this->assertSame('a blank line ends the code span paragraph', $error->reason);
            }
            $verse = $converter->parse("::: |\n`Q`\n:::\n");
            $this->replaceCode($verse, $value);
            $written = (new CarveRenderer())->render($verse);
            $this->assertSame(str_replace(["\r\n", "\r"], "\n", $converter->render($verse)), $converter->convert($written));
        }
    }
}
