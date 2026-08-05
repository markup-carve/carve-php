<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §10: an authored `[label]: /url` line is a NODE.
 *
 * Four clauses wanted it and the fourth decided it: without a node a writer
 * cannot reproduce the definition, so a resolved reference was written back as
 * an inline link and PART 11 §1's `parse(fmt(x)) == parse(x)` was false for
 * every one of them (markup-carve/carve#642).
 */
class LinkReferenceDefinitionNodeTest extends TestCase
{
    private function parse(string $source, bool $withPositions = false): Document
    {
        return (new BlockParser(trackPositions: $withPositions))->parse($source);
    }

    /**
     * @return array<string>
     */
    private function childTypes(string $source): array
    {
        return array_map(
            static fn ($child): string => $child->getType(),
            $this->parse($source)->getChildren(),
        );
    }

    public function testAnAuthoredDefinitionIsANode(): void
    {
        $this->assertSame(
            ['paragraph', 'link_reference_definition'],
            $this->childTypes("[a][r]\n\n[r]: /u\n"),
        );
    }

    public function testAnUnusedDefinitionIsStillANode(): void
    {
        // The gap §10a named: an unused definition survived neither the tree nor
        // a round trip, so `carve --markdown` on this document emitted nothing.
        $this->assertSame(['link_reference_definition'], $this->childTypes("[r]: /u\n"));
    }

    public function testTheNodeCarriesLabelHrefTitleAndAttributes(): void
    {
        $document = $this->parse("[a][r]\n\n[r]: /u \"T\" {.x}\n");
        $definition = null;
        foreach ($document->getChildren() as $child) {
            if ($child instanceof LinkReferenceDefinition) {
                $definition = $child;
            }
        }

        $this->assertInstanceOf(LinkReferenceDefinition::class, $definition);
        $this->assertSame('r', $definition->getLabel());
        $this->assertSame('/u', $definition->getHref());
        $this->assertSame('T', $definition->getTitle());
        // The trailing block rides the node's inherited attributes, which the
        // codec publishes as `attrs` - one attribute channel, since field names
        // are spec surface (PART 12 §3).
        $this->assertSame(['class' => 'x'], $definition->getAttributes());
    }

    /**
     * The definition renders nothing on the HTML target: it feeds the links and
     * images that resolve its label (PART 9R R1). Adding the node must not put
     * anything on the page.
     */
    public function testTheNodeRendersNothingInHtml(): void
    {
        $converter = new CarveConverter();
        $this->assertSame("<p><a href=\"/u\">a</a></p>\n", $converter->convert("[a][r]\n\n[r]: /u\n"));
        $this->assertSame('', $converter->convert("[r]: /u\n"));
    }

    /**
     * §10 hoists the definition to the DOCUMENT, exactly as §7 hoists the other
     * two kinds. Appending it to the enclosing container instead left the node
     * inside a block quote and changed that quote's rendering.
     */
    public function testADefinitionInsideAContainerHoistsToTheDocument(): void
    {
        $this->assertSame(
            ['paragraph', 'block_quote', 'link_reference_definition'],
            $this->childTypes("a\n\n> [r]: /u\n"),
        );
        $this->assertSame(
            "<blockquote><p>quoted</p></blockquote>\n",
            (new CarveConverter())->convert("> quoted\n[r]: /u"),
        );
    }

    /**
     * Hoisting a NODE rather than collecting a root map is what keeps the
     * definition navigable: §4 requires `pos` on every node but the root, and a
     * root FIELD cannot carry one. That reasoning is §10's whole argument, so
     * the span is asserted rather than assumed - including for a definition
     * hoisted out of a container, which still points at its authored line.
     */
    public function testTheNodeCarriesThePositionOfItsAuthoredLine(): void
    {
        foreach (["x\n\n[r]: /u\n", "a\n\n> [r]: /u\n"] as $source) {
            $document = $this->parse($source, withPositions: true);
            foreach ($document->getChildren() as $child) {
                if (!$child instanceof LinkReferenceDefinition) {
                    continue;
                }
                $pos = $child->getPos();
                $this->assertNotNull($pos, "no pos for: {$source}");
                $this->assertSame(3, $pos->startLine, "wrong line for: {$source}");
            }
        }
    }

    public function testAHeadingDerivedReferenceGetsNoNode(): void
    {
        // PART 11 R1 derives a reference from a heading. There is no definition
        // line to reproduce, so there is nothing for a node to carry.
        $this->assertNotContains('link_reference_definition', $this->childTypes("# Getting Started\n\n[Getting Started][]\n"));
    }

    public function testTheWireShapeIsTheSpellingPart12Section10Names(): void
    {
        $wire = (new AstCodec())->encode($this->parse("[a][r]\n\n[r]: /u \"T\" {.x}\n"));
        $definition = null;
        foreach ($wire['children'] as $child) {
            if (($child['type'] ?? null) === 'link_reference_definition') {
                $definition = $child;
            }
        }

        $this->assertNotNull($definition);
        unset($definition['pos']);
        $this->assertSame(
            [
                'type' => 'link_reference_definition',
                'label' => 'r',
                'href' => '/u',
                'title' => 'T',
                'attrs' => ['classes' => ['x'], 'order' => ['.class']],
            ],
            $definition,
        );
    }
}
