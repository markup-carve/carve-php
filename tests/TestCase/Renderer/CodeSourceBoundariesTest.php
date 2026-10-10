<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Comment;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\SmallCaps;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class CodeSourceBoundariesTest extends TestCase
{
    private function replaceCode(Node $node, string $value): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Comment) {
                $node->removeChild($child);
            } elseif ($child instanceof Code && $child->getContent() === 'Q') {
                $child->setContent($value);
            } else {
                $this->replaceCode($child, $value);
            }
        }
    }

    public function testAdjacentCodeKeepsBothPayloads(): void
    {
        foreach (['a\\', 'a\\\\\\'] as $value) {
            foreach (["`Q`{%  %}`b`\n", "*`Q`{%  %}`b`*\n"] as $source) {
                $converter = new CarveConverter();
                $document = $converter->parse($source);
                $this->replaceCode($document, $value);
                $written = (new CarveRenderer())->render($document);
                $this->assertSame($converter->render($document), $converter->convert($written));
            }
        }
    }

    public function testBlankLineCodeInsideVerseKeepsValue(): void
    {
        foreach (["a\n\nb", "a\r\rb", "a\r\n\r\nb", "\n\nb", "a\n\n"] as $value) {
            $converter = new CarveConverter();
            $verse = $converter->parse("::: |\n`Q`\n:::\n");
            $this->replaceCode($verse, $value);
            $written = (new CarveRenderer())->render($verse);
            $this->assertSame(str_replace(["\r\n", "\r"], "\n", $converter->render($verse)), $converter->convert($written));
        }
    }

    public function testEmptySiblingsDoNotHideAVerbatimCloser(): void
    {
        foreach ([new Code('a\\'), new Math('a\\'), new LiteralInline('a\\')] as $first) {
            $converter = new CarveConverter();
            $document = $converter->parse("`Q`\n");
            $document->getChildren()[0]->setChildren([$first, new Text(''), new Code('b')]);
            $written = (new CarveRenderer())->render($document);
            $this->assertStringContainsString('{%  %}', $written);
            $this->assertSame($converter->render($document), $converter->convert($written));
        }
    }

    public function testTransparentWrappersKeepTheirVerbatimCloser(): void
    {
        foreach ([new Code('a\\'), new Math('a\\'), new LiteralInline('a\\')] as $first) {
            $converter = new CarveConverter();
            $document = $converter->parse("`Q`\n");
            $paragraph = $document->getChildren()[0];
            $paragraph->setChildren([$first, new Code('b')]);
            $expected = $converter->render($document);
            $wrapper = new SmallCaps();
            $wrapper->setChildren([$first, new Text('')]);
            $paragraph->setChildren([$wrapper, new Code('b')]);
            $written = (new CarveRenderer())->render($document);
            $this->assertStringContainsString('{%  %}', $written);
            $this->assertSame($expected, $converter->convert($written));
        }
    }
}
