<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Past the cap an item's over-cap marker line becomes paragraph text that still
 * splits the item's paragraphs, and the `+` the writer spells that split with is
 * flattened as well, so no source reads back (CARVE-P9-078). The writer used to
 * emit source that read back as a different tree, after a narrowing search whose
 * every probe re-parsed the whole document: 230 levels took over two minutes
 * (markup-carve/carve-js#3107).
 */
class TheWriterRefusesAListNestedPastTheCapTest extends TestCase
{
    /**
     * `$depth` container levels, each holding one item line.
     */
    private function nested(string $shape, int $depth): string
    {
        $out = '';
        $indent = '';
        $closers = [];
        for ($i = 0; $i < $depth; $i++) {
            if ($shape === 'quote' && $i % 2 === 1) {
                $out .= "{$indent}> q{$i}\n{$indent}>\n";
                $indent .= '> ';

                continue;
            }
            if ($shape === 'container' && $i % 2 === 1) {
                $fence = str_repeat(':', 3 + (int)ceil(($depth - $i) / 2));
                $out .= "{$indent}{$fence} note\n";
                $closers[] = $indent . $fence;

                continue;
            }
            $marker = $shape === 'ordered' || ($shape === 'mixed' && $i % 2 === 1) ? '1. ' : '- ';
            $out .= "{$indent}{$marker}item {$i}\n";
            $indent .= str_repeat(' ', strlen($marker));
        }
        while ($closers !== []) {
            $out .= array_pop($closers) . "\n";
        }

        return $out;
    }

    private function assertReadsBack(Document $document): void
    {
        $probe = new class extends CarveRenderer {
            public function readsBack(Document $document): bool
            {
                $tree = $this->canonicalTree($this->render($document));

                return $tree !== null && $tree == $this->sourceTree($document);
            }
        };

        $this->assertTrue($probe->readsBack($document));
    }

    private function assertRefused(Document $document): void
    {
        $this->expectException(SourceUnspellableException::class);
        $this->expectExceptionMessage(sprintf(
            'content nested past the parser nesting limit of %d has no spelling that reads back',
            BlockParser::MAX_NESTING_DEPTH,
        ));
        (new CarveRenderer())->render($document);
    }

    public function testABulletLadderAtTheCapReadsBack(): void
    {
        $this->assertReadsBack((new CarveConverter())->parse($this->nested('bullet', BlockParser::MAX_NESTING_DEPTH)));
    }

    public function testAQuoteInListLadderPastTheCapReadsBack(): void
    {
        $this->assertReadsBack((new CarveConverter())->parse($this->nested('quote', BlockParser::MAX_NESTING_DEPTH + 1)));
    }

    public function testABulletLadderPastTheCapIsRefused(): void
    {
        $this->assertRefused((new CarveConverter())->parse($this->nested('bullet', BlockParser::MAX_NESTING_DEPTH + 1)));
    }

    public function testAHandBuiltTreeAtTheCapWithNoFootnotesReadsBack(): void
    {
        // A hand-built tree carries an empty footnote map like a parsed one, so
        // the comparison must not read that as a difference.
        $node = new Div();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('x'));
        $node->appendChild($paragraph);
        for ($i = 1; $i < BlockParser::MAX_NESTING_DEPTH; $i++) {
            $outer = new Div();
            $outer->appendChild($node);
            $node = $outer;
        }
        $document = new Document();
        $document->appendChild($node);

        $this->assertReadsBack($document);
    }

    public function testADeclaredCarveOutJustUnderTheCapIsStillWritten(): void
    {
        // A paragraph holding only an image is written as a block image, which
        // does not read back as the paragraph. Inline depth is not container
        // depth, so the paragraph one level under the cap stays out of the check.
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Image('a.png'));
        $node = $paragraph;
        for ($i = 1; $i < BlockParser::MAX_NESTING_DEPTH; $i++) {
            $div = new Div();
            $div->appendChild($node);
            $node = $div;
        }
        $document = new Document();
        $document->appendChild($node);

        $this->assertStringContainsString('a.png', (new CarveRenderer())->render($document));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: bool}>
     */
    public static function heavierLadders(): array
    {
        return [
            'mixed at the cap' => ['mixed', BlockParser::MAX_NESTING_DEPTH, true],
            'container past the cap' => ['container', BlockParser::MAX_NESTING_DEPTH + 1, true],
            'ordered past the cap' => ['ordered', BlockParser::MAX_NESTING_DEPTH + 1, false],
            'mixed far past the cap' => ['mixed', BlockParser::MAX_NESTING_DEPTH + 30, false],
        ];
    }

    #[DataProvider('heavierLadders')]
    #[Group('scaling')]
    public function testHeavierLadders(string $shape, int $depth, bool $readsBack): void
    {
        $document = (new CarveConverter())->parse($this->nested($shape, $depth));
        if ($readsBack) {
            $this->assertReadsBack($document);

            return;
        }
        $this->assertRefused($document);
    }

    /**
     * Relative to parsing the same document, so the reading does not depend on
     * how busy the machine is. Healthy reads ~2 (two renders, two parses); the
     * search this replaced re-parsed the document a dozen times or more.
     */
    #[Group('scaling')]
    public function testThePastTheCapDecisionCostsAFewParses(): void
    {
        foreach (['bullet', 'mixed'] as $shape) {
            $source = $this->nested($shape, BlockParser::MAX_NESTING_DEPTH + 30);
            $document = (new CarveConverter())->parse($source);
            $ratios = [];
            for ($round = 0; $round < 3; $round++) {
                $start = hrtime(true);
                (new CarveConverter())->parse($source);
                $parse = hrtime(true) - $start;
                $start = hrtime(true);
                try {
                    (new CarveRenderer())->render($document);
                } catch (SourceUnspellableException) {
                }
                $ratios[] = (hrtime(true) - $start) / max(1, $parse);
            }
            sort($ratios);

            $this->assertLessThan(8.0, $ratios[0], $shape);
        }
    }
}
