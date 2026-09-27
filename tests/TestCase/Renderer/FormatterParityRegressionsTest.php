<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class FormatterParityRegressionsTest extends TestCase
{
    public function testConstructedBlockMarkerCodeRefusesInTermsAndFootnotes(): void
    {
        foreach ([":: p `X` q\n", "r[^n]\n\n[^n]: p `X` q\n"] as $source) {
            foreach (["a\n> b", "a\n>\nb", "a\n[x]: y", "a\r> b"] as $value) {
                if (str_starts_with($source, 'r[') && str_contains($value, '[x]')) {
                    continue;
                }
                $doc = CarveConverter::create()->parse($source);
                $visit = function (Node $node) use (&$visit, $value): void {
                    if ($node instanceof Code) {
                        $node->setContent($value);
                    }
                    foreach ($node->getChildren() as $child) {
                        $visit($child);
                    }
                };
                $visit($doc);
                try {
                    (new CarveRenderer())->render($doc);
                    $this->fail('Unspellable code was written');
                } catch (SourceUnspellableException $error) {
                    $this->assertStringContainsString('cannot spell code', $error->getMessage());
                }
            }
        }
    }

    public function testFormattingPreservesRenderedContentAndIsIdempotent(): void
    {
        $converter = new CarveConverter();
        foreach (["- - p `a\n > b` q\n", "- 1. p `a\n > b` q\n", "- p\n\n  - q `a\n > b` r\n", "r[^n]\n\n[^n]: p `a\n  [x]: y` q\n", "r[^n]\n\n[^n]: - p `a\n   > b` q\n", "> - - p `a\n>  > b` q\n", "- p `a\n > b` q\n", "- p `a\n >\nb` q\n", "- p `a\n [x]: y` q\n", "> - p `a\n>  > b` q\n", "`\n\t> x\n", "~``` x\n[d]: u ```\n", "`\n``\n", "`\n``x\n", "1. [d]: u\n", "- A\n{x}\n*[A]: }\n"] as $source) {
            $formatted = CarveConverter::toCarve($source);
            $this->assertSame($converter->convert($source), $converter->convert($formatted), $source);
            $this->assertSame($formatted, CarveConverter::toCarve($formatted), $source);
        }
    }
}
