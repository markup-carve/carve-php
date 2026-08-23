<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class DefinitionPrepassGateTest extends TestCase
{
    public function testAbsentOpeningBytesSkipTheDiscoveryWalk(): void
    {
        $parser = $this->parser();
        $parser->parse("# Heading\n\nPlain *strong* text with [a link](/url).\n");

        $this->assertSame(1, $parser->topLevelWalks);
    }

    public function testMixedDefinitionsShareTheAuthoritativeWalk(): void
    {
        $parser = $this->parser();
        $parser->parse("[ref]: /url\n\n[^note]: body\n\n*[HTML]: HyperText\n");

        $this->assertSame(1, $parser->topLevelWalks);
        $this->assertSame(0, $parser->referenceCollectors);
    }

    public function testOneDefinitionFamilyKeepsItsCheaperCollector(): void
    {
        $parser = $this->parser();
        $parser->parse("[ref]: /url\n\n[link][ref]\n");

        $this->assertSame(1, $parser->topLevelWalks);
        $this->assertSame(1, $parser->referenceCollectors);
    }

    private function parser(): BlockParser
    {
        return new class extends BlockParser {
            public int $topLevelWalks = 0;

            public int $referenceCollectors = 0;

            protected function extractReferences(array $lines): void
            {
                $this->referenceCollectors++;
                parent::extractReferences($lines);
            }

            protected function parseBlocks(
                Node $parent,
                array $lines,
                int $indent,
                ?array $lineMap = null,
                bool $topLevel = false,
                bool $itemBody = false,
            ): void {
                if ($topLevel) {
                    $this->topLevelWalks++;
                }
                parent::parseBlocks($parent, $lines, $indent, $lineMap, $topLevel, $itemBody);
            }
        };
    }
}
