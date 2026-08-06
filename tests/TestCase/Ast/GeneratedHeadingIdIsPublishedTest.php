<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A generated heading id is a resolution result, so it reaches the wire.
 *
 * PART 12 §5 names it: "A GENERATED HEADING ID IS A RESOLUTION RESULT --
 * NORMATIVE. A `heading` whose id was slugged from its text rather than written
 * carries that id in `attrs.id`".
 *
 * The criterion is recomputability, and a heading id is not a function of the
 * heading: dedup assigns the next free suffix in document order, so a consumer
 * holding one subtree cannot derive `Notes-2` without having seen every heading
 * before it. carve-js publishes it; this engine computed ids only on the render
 * path, so its published headings carried no `attrs` at all (carve#750).
 *
 * The id takes NO `order` slot: it was never written in an attribute block.
 */
class GeneratedHeadingIdIsPublishedTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>|null>
     */
    protected function headingAttrs(string $source): array
    {
        $tree = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'heading') {
                $found[] = $node['attrs'] ?? null;
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        $walk($tree);

        return $found;
    }

    public function testASluggedIdIsPublished(): void
    {
        $this->assertSame([['id' => 'Introduction']], $this->headingAttrs("# Introduction\n"));
    }

    public function testDedupSuffixesArePublishedInDocumentOrder(): void
    {
        // The case that cannot be recomputed from one subtree.
        $this->assertSame(
            [['id' => 'Notes'], ['id' => 'Notes-2'], ['id' => 'Notes-3']],
            $this->headingAttrs("# Notes\n\n# Notes\n\n# Notes\n"),
        );
    }

    public function testTheIdTakesNoOrderSlot(): void
    {
        // It was never written in an attribute block, and `order` is the
        // source-appearance order of the slots in one.
        foreach ($this->headingAttrs("# Introduction\n") as $attrs) {
            $this->assertArrayNotHasKey('order', $attrs ?? []);
        }
    }

    public function testAnAuthoredIdWinsAndKeepsItsSlot(): void
    {
        // A block-attribute line above the heading IS authored, so it keeps the
        // value and the slot - the generated id must not overwrite it.
        $attrs = $this->headingAttrs("{#chosen}\n# Introduction\n")[0];

        $this->assertSame('chosen', $attrs['id'] ?? null);
        $this->assertSame(['#id'], $attrs['order'] ?? null);
    }

    public function testTheHtmlStillUsesTheSameIds(): void
    {
        // The published tree and the rendered document must not disagree.
        $html = (new CarveConverter())->convert("# Notes\n\n# Notes\n");

        $this->assertStringContainsString('id="Notes"', $html);
        $this->assertStringContainsString('id="Notes-2"', $html);
    }
}
