<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use MarkupCarve\Carve\Parser\SourceMap;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The scratch pass that builds the implicit heading-reference index keeps only
 * headings, so it skips paragraph inlines - except where block structure reads
 * them, which is a caption's host paragraph.
 */
class TheHeadingIndexPassParsesOnlyTheInlinesItReadsTest extends TestCase
{
    private const SOURCE = <<<'CRV'
See [Target][], [Nested][] and [Quoted][].

![alt](img.png)
^ The caption

# Target

- item

  ## Nested

> # Quoted
CRV;

    public function testTheScratchPassSkipsProseButReadsTheCaptionHost(): void
    {
        $parser = new class extends BlockParser {
            public int $reparses = 0;

            public stdClass $scratch;

            public function __construct()
            {
                parent::__construct();
                $this->scratch = new stdClass();
                $this->scratch->active = false;
                $this->scratch->inlines = [];
                $this->inlineParser = new class ($this, $this->scratch) extends InlineParser {
                    public function __construct(BlockParser $blockParser, private stdClass $scratch)
                    {
                        parent::__construct($blockParser);
                    }

                    public function parse(
                        Node $parent,
                        string $text,
                        int $sourceLine = 0,
                        bool $captionContext = false,
                        ?SourceMap $sourceMap = null,
                        bool $lineBlock = false,
                        bool $separateQuoteScope = false,
                    ): void {
                        if ($this->scratch->active) {
                            $this->scratch->inlines[] = $text;
                        }
                        parent::parse($parent, $text, $sourceLine, $captionContext, $sourceMap, $lineBlock, $separateQuoteScope);
                    }
                };
            }

            protected function indexHeadingsFromStructure(array $lines): void
            {
                $this->scratch->active = true;
                try {
                    parent::indexHeadingsFromStructure($lines);
                } finally {
                    $this->scratch->active = false;
                }
            }

            protected function reparseWithHeadingReferences(array $lines, array $headingReferences, int $sourceLength): Document
            {
                $this->reparses++;

                return parent::reparseWithHeadingReferences($lines, $headingReferences, $sourceLength);
            }
        };

        $parser->parse(self::SOURCE);
        $inlines = $parser->scratch->inlines;

        $this->assertNotContains('See [Target][], [Nested][] and [Quoted][].', $inlines);
        // Once to decide the caption interrupts the paragraph, once as the host.
        $this->assertSame(2, count(array_keys($inlines, '![alt](img.png)', true)));
        $this->assertContains('Target', $inlines);
        $this->assertContains('Nested', $inlines);
        $this->assertSame(0, $parser->reparses);
    }

    public function testTheIndexStillResolvesEveryReachableHeading(): void
    {
        $html = (new CarveConverter())->convert(self::SOURCE);

        $this->assertStringContainsString(
            '<p>See <a href="#Target">Target</a>, <a href="#Nested">Nested</a> and [Quoted][].</p>',
            $html,
        );
        $this->assertStringContainsString('<figcaption>The caption</figcaption>', $html);
    }

    public function testBlockCallbacksSeeMaterializedParagraphs(): void
    {
        $seen = [];
        $converter = new CarveConverter();
        $converter->getParser()->addBlockPattern('/^CUSTOM$/', static function (array $lines, int $start, Node $parent) use (&$seen): int {
            foreach ($parent->getChildren() as $child) {
                if ($child instanceof Paragraph) {
                    $seen[] = $child->hasChildren();
                }
            }

            return 1;
        });
        $html = $converter->convert("See [Target][].\n\nSecond paragraph.\n\nThird paragraph.\n\nCUSTOM\n\n# Target\n");
        $this->assertGreaterThanOrEqual(6, count($seen));
        $this->assertNotContains(false, $seen);
        $this->assertStringContainsString('<a href="#Target">Target</a>', $html);
    }

    public function testDisplayMathKeepsItsCaptionDuringHeadingIndexing(): void
    {
        $source = 'See [Target][].' . "\n\n" . '$$`x`' . "\n^ Math caption\n\n# Target\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString('<figcaption>Math caption</figcaption>', $html);
        $this->assertStringContainsString('<a href="#Target">Target</a>', $html);
    }
}
