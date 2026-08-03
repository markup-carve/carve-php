<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A RENDER ANNOTATION IS EMITTED LAST - after the generated attribute, not
 * merely after the authored ones (grammar PART 9, attribute order).
 *
 * `data-source-line` records where a block was WRITTEN rather than describing
 * the element, so it is a third category behind authored attributes and
 * generated ones:
 *
 * source: `> ## Nested`
 * output: `<h2 id="Nested" data-source-line="1">`
 *
 * This engine attaches the stamp at PARSE time, which carries it inside the
 * authored run, and the generated id is appended after it - giving the exact
 * inversion the rule was written to stop. It was reachable only through a
 * heading whose id is generated and not hoisted to a `<section>`, which needs
 * a container, and no corpus case rendered one with `sourceLines` on
 * (carve#535).
 */
class SourceLineAnnotationOrderTest extends TestCase
{
    private function convert(string $source): string
    {
        return (new CarveConverter(sourceLines: true))->convert($source);
    }

    public function testTheStampFollowsAGeneratedIdOnAQuotedHeading(): void
    {
        $html = $this->convert("> ## Quoted\n");

        $this->assertStringContainsString('<h2 id="Quoted" data-source-line="1">', $html);
    }

    public function testTheStampFollowsAuthoredAttributesAndTheGeneratedId(): void
    {
        $html = $this->convert("> {a=b}\n> ## Quoted\n");

        $this->assertStringContainsString(
            '<h2 a="b" id="Quoted" data-source-line="2">',
            $html,
        );
    }

    public function testAnAuthoredIdKeepsItsPositionAndTheStampStillGoesLast(): void
    {
        // An authored id is not generated, so it keeps its authored place; the
        // annotation is behind it either way.
        $html = $this->convert("> {#x a=b}\n> ## Quoted\n");

        $this->assertStringContainsString('<h2 id="x" a="b" data-source-line="2">', $html);
    }

    public function testANonHeadingBlockIsUnaffected(): void
    {
        $html = $this->convert("{.c}\ntext\n");

        $this->assertStringContainsString('<p class="c" data-source-line="2">text</p>', $html);
    }
}
