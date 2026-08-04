<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §16: `reference_definition = '[', reference_label, ']', ':', space,
 * link_destination, [link_title], [space, attributes], newline`.
 *
 * A trailing `{...}` attributes the DEFINITION, and PART 9R R1 transfers those
 * attributes to every link resolving the label, with the link's own attributes
 * overriding per key under the §15 A3 merge - definition list first, link list
 * second, classes accumulating in source order.
 *
 * The slot exists because R1 and the `linkDefs` symbol table were written with
 * definition attributes in them and the production could not produce them
 * (markup-carve/carve#604, #612). This engine had invented the only spelling
 * anyone had - a floating `{...}` line ABOVE the definition - which §15 A2a
 * then correctly took away; this is the replacement.
 */
class ReferenceDefinitionAttributesTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testTrailingAttributesReachEveryLinkResolvingTheLabel(): void
    {
        $html = $this->converter->convert("[ex]: https://e.com {.external}\n\n[A][ex] and [B][ex]");

        $this->assertSame(2, substr_count($html, 'class="external"'));
    }

    public function testTheLinksOwnAttributesOverridePerKey(): void
    {
        $html = $this->converter->convert("[ex]: /u {.external #a}\n\nsee [E][ex]{.internal #b}");

        $this->assertStringContainsString('class="external internal"', $html);
        $this->assertStringContainsString('id="b"', $html);
        $this->assertStringNotContainsString('id="a"', $html);
    }

    public function testTheDefinitionsSourceOrderSurvives(): void
    {
        // `parse()` hoists class to the front; the inline path preserves source
        // order, and a definition's attributes have to render the same way.
        $html = $this->converter->convert("[r]: /u {data-x=\"y\" .a}\n\nsee [E][r]");

        $this->assertStringContainsString('<a href="/u" data-x="y" class="a">', $html);
    }

    public function testAQuotedBraceDoesNotCloseTheBlock(): void
    {
        // A lazy `\{[^}]*\}` stops at the quoted `}` and drops every attribute
        // on the line; the scan tracks quote state instead.
        $html = $this->converter->convert("[r]: /u {data-x=\"}\" .a}\n\nsee [E][r]");

        $this->assertStringContainsString('data-x="}"', $html);
        $this->assertStringContainsString('class="a"', $html);
    }

    public function testTheBlockMustBePrecededByWhitespace(): void
    {
        // `space, attributes` - without the space the braces are part of the
        // destination.
        $html = $this->converter->convert("[ex]: /u{.x}\n\nsee [E][ex]");

        $this->assertStringContainsString('href="/u{.x}"', $html);
        $this->assertStringNotContainsString('class="x"', $html);
    }

    public function testATitleAndAttributesCoexist(): void
    {
        $html = $this->converter->convert("[ex]: /u \"T\" {.c}\n\nsee [E][ex]");

        $this->assertStringContainsString('title="T"', $html);
        $this->assertStringContainsString('class="c"', $html);
    }

    public function testTheLineAboveStillFloatsPastTheDefinition(): void
    {
        // The two forms are different constructs (§15 A2a and this slot): the
        // line above lands on the next VISIBLE block, the trailing block on the
        // links.
        $html = $this->converter->convert("{.a}\n[ex]: /u {.b}\n\ntext [E][ex]");

        $this->assertStringContainsString('<p class="a">', $html);
        $this->assertStringContainsString('<a href="/u" class="b">', $html);
    }

    public function testARepeatedClassInOneBlockStillDeduplicates(): void
    {
        // Within one attribute block a repeated class collapses, as it does
        // inline and on a block attribute line. A3 accumulates ACROSS lists,
        // which is a different question.
        $html = $this->converter->convert("[ex]: /u {.a .a}\n\nsee [E][ex]");

        $this->assertStringContainsString('class="a"', $html);
        $this->assertStringNotContainsString('class="a a"', $html);
    }

    public function testADefinitionWithoutAttributesIsUnchanged(): void
    {
        $html = $this->converter->convert("[ex]: /u\n\nsee [E][ex]");

        $this->assertStringContainsString('<a href="/u">E</a>', $html);
    }
}
