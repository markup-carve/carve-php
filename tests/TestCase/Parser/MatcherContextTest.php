<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\MatcherContext;
use PHPUnit\Framework\TestCase;

class MatcherContextTest extends TestCase
{
    private function context(string $source = ''): MatcherContext
    {
        $blockParser = new BlockParser();
        $blockParser->parse($source);

        return new MatcherContext($blockParser, $blockParser->getInlineParser());
    }

    public function testParseInlinesReturnsDetachedChildren(): void
    {
        $nodes = $this->context()->parseInlines('a *b* c');

        $this->assertNotEmpty($nodes);
        $hasStrong = false;
        foreach ($nodes as $node) {
            if ($node instanceof Strong) {
                $hasStrong = true;

                break;
            }
        }

        $this->assertTrue($hasStrong, 'parseInlines should resolve *b* to a strong node');
    }

    public function testParseBlocksReturnsDetachedBlocks(): void
    {
        $nodes = $this->context()->parseBlocks(['# Heading', '', 'Body text']);

        $this->assertNotEmpty($nodes);
        $this->assertInstanceOf(Heading::class, $nodes[0]);
    }

    public function testDefinitionTablesAreExposed(): void
    {
        $ctx = $this->context("[ref]: https://example.com\n\nuse [text][ref]");

        $this->assertNotNull($ctx->getReference('ref'));
        $this->assertNull($ctx->getReference('missing'));
        $this->assertFalse($ctx->hasFootnote('nope'));
    }
}
