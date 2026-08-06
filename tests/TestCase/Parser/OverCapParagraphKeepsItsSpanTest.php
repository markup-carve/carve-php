<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * The paragraph the parser flattens past the nesting cap keeps its position.
 *
 * PART 9 §25 caps container nesting, and an opener past the cap degrades to
 * ordinary paragraph text. That paragraph was published with no `pos`, and its
 * soft breaks with none either.
 *
 * PART 12 §4 does permit omitting `pos` - but only on a REASSEMBLED node, and
 * it names them: a synthesized hard break, line-block content rebuilt around a
 * sentinel, a cell put back together from continuation lines, a text run whose
 * pieces are not adjacent. This paragraph is none of those. It is one
 * contiguous run of lines, so a span over it claims nothing false, and
 * carve-js publishes exactly that span - slicing the source by it returns the
 * degraded openers and the body line and nothing else (carve-php#945,
 * carve#534).
 *
 * The distinction is the point: most of this engine's remaining `ast:check`
 * findings ARE covered by that clause and are not defects. This one is.
 */
class OverCapParagraphKeepsItsSpanTest extends TestCase
{
    protected function overCapDocument(): string
    {
        // 203 openers, matching corpus 182 - the document `ast:check` reports.
        return str_repeat(":::: note\n", 203) . "x\n";
    }

    /**
     * @return array<string, mixed>
     */
    protected function publish(string $source): array
    {
        return (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string> $types
     *
     * @return array<int, array<string, mixed>>
     */
    protected function collect(array $node, array $types): array
    {
        $found = [];
        if (in_array($node['type'] ?? '', $types, true)) {
            $found[] = $node;
        }
        foreach (['children', 'items', 'rows'] as $key) {
            /** @var array<mixed> $branch */
            $branch = $node[$key] ?? [];
            foreach ($branch as $child) {
                if (is_array($child) && isset($child['type'])) {
                    $found = array_merge($found, $this->collect($child, $types));
                }
            }
        }

        return $found;
    }

    public function testTheFlattenedParagraphCarriesASpan(): void
    {
        $paragraphs = $this->collect($this->publish($this->overCapDocument()), ['paragraph']);

        $this->assertNotEmpty($paragraphs, 'no paragraph past the cap');
        foreach ($paragraphs as $paragraph) {
            $this->assertArrayHasKey('pos', $paragraph, 'the over-cap paragraph has no position');
        }
    }

    public function testItsSoftBreaksCarrySpansToo(): void
    {
        $breaks = $this->collect($this->publish($this->overCapDocument()), ['soft_break']);

        $this->assertNotEmpty($breaks, 'the flattened body should hold a soft break');
        foreach ($breaks as $break) {
            $this->assertArrayHasKey('pos', $break, 'a soft break in the over-cap paragraph has no position');
        }
    }

    public function testTheSpanSelectsTheDegradedText(): void
    {
        // Present is not enough - a wrong span is rated worse than an absent
        // one. The paragraph must start at the first line it actually holds.
        $source = $this->overCapDocument();
        $paragraphs = $this->collect($this->publish($source), ['paragraph']);
        $pos = $paragraphs[0]['pos'] ?? null;

        $this->assertNotNull($pos);
        $codepoints = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $slice = implode('', array_slice($codepoints, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']));

        $this->assertStringStartsWith(':::: note', $slice);
        $this->assertStringEndsWith('x', $slice);
    }

    public function testADocumentInsideTheCapStillPlacesItsParagraph(): void
    {
        // The control. A change that placed nothing would fail the tests above
        // and pass here only by accident; one that broke ordinary placement
        // fails here.
        $paragraphs = $this->collect($this->publish(":::: note\nbody\nmore\n::::\n"), ['paragraph']);

        $this->assertNotEmpty($paragraphs);
        $this->assertArrayHasKey('pos', $paragraphs[0]);
    }
}
