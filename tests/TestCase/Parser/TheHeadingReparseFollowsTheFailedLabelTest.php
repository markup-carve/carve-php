<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * The second pass runs for headings that could rescue a reference, and for no
 * others (carve-php#2245).
 *
 * The flag that arms it fires for any reference the inline parser could not
 * resolve, which includes a definition written below its use - the ordinary
 * way to write one. Filtering the collected headings without asking which
 * label had failed reparsed every document that held a heading at all, at
 * roughly twice the parse cost.
 */
class TheHeadingReparseFollowsTheFailedLabelTest extends TestCase
{
    public function testAForwardDefinitionDoesNotReparse(): void
    {
        $parser = $this->parser();
        $parser->parse("# H\n\n[r][ref]\n\n[ref]: /t\n");

        $this->assertSame(1, $parser->topLevelWalks);
    }

    public function testTheForwardReferenceStillResolves(): void
    {
        $html = (new CarveConverter())->convert("# H\n\n[r][ref]\n\n[ref]: /t\n");

        $this->assertStringContainsString('href="/t"', $html);
    }

    public function testAHeadingNamedByTheFailedLabelStillReparses(): void
    {
        $parser = $this->parser();
        $parser->parse("See [t][H].\n\n# H\n");

        $this->assertSame(2, $parser->topLevelWalks);
    }

    public function testManyUnrelatedHeadingsDoNotReparse(): void
    {
        $parser = $this->parser();
        $parser->parse("# One\n\n## Two\n\n### Three\n\n[r][ref]\n\n[ref]: /t\n");

        $this->assertSame(1, $parser->topLevelWalks);
    }

    private function parser(): BlockParser
    {
        return new class extends BlockParser {
            public int $topLevelWalks = 0;

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
