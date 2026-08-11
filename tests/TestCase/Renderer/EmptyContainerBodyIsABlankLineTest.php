<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The canonical writer puts a BLANK LINE where an empty container body would
 * be, for EVERY container shape (markup-carve/carve#961, ruling 1).
 *
 * PART 10 §4 settled the same question one layer out, for the HTML target, and
 * chose the blank line. This target follows that sibling clause rather than
 * inventing a second rule for "an empty body". It deliberately does NOT import
 * §4's bare-div exception: §4 says in its own words that the exception "has no
 * principle behind it" and stands only because three engines already produced
 * it, which was not the case here - so the uniform rule §4 itself calls
 * "easier to state and to implement" is taken instead.
 *
 * carve-js and carve-rs already wrote the blank line; carve-php glued the
 * opener to the closer. The glue was a WORKAROUND for a parser defect one layer
 * down - a blank line inside an open div severed the item's collection, so the
 * closer below it read as a fresh bare-div opener. That defect is fixed with
 * this change and is pinned by
 * {@see \MarkupCarve\Carve\Test\TestCase\BlankLineInsideAnOpenDivInAnItemTest}.
 */
class EmptyContainerBodyIsABlankLineTest extends TestCase
{
    private function fmt(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    public function testABareDivKeepsABlankLine(): void
    {
        $this->assertSame(":::\n\n:::\n", $this->fmt(":::\n:::\n"));
    }

    public function testAWordClassDivKeepsABlankLine(): void
    {
        $this->assertSame("::: myclass\n\n:::\n", $this->fmt("::: myclass\n:::\n"));
    }

    public function testAnAdmonitionKeepsABlankLine(): void
    {
        $this->assertSame("::: note\n\n:::\n", $this->fmt("::: note\n:::\n"));
    }

    public function testAPlacementDirectiveKeepsABlankLine(): void
    {
        $this->assertStringContainsString("::: footnotes\n\n:::", $this->fmt("x\n\n::: footnotes\n:::\n\n[^a]: n\n"));
    }

    public function testAnEmptyContainerInsideAListItemKeepsABlankLine(): void
    {
        $this->assertSame("- ::: note\n\n  :::\n", $this->fmt("- ::: note\n  :::\n"));
    }

    public function testAnEmptyContainerInsideABlockQuoteKeepsABlankLine(): void
    {
        $this->assertSame("> quote\n>\n> ::: note\n>\n> :::\n", $this->fmt("> quote\n> ::: note\n>\n> :::\n"));
    }

    /**
     * The rule is about the BODY, not about the fence width: an empty
     * container nested one level in widens to `::::` and still gets its blank
     * line, and the container holding it is not empty and gets none.
     */
    public function testANestedEmptyContainerKeepsABlankLine(): void
    {
        $this->assertSame("::: note\n:::: tip\n\n::::\n:::\n", $this->fmt("::: note\n:::: tip\n::::\n:::\n"));
    }

    public function testANonEmptyBodyGainsNoBlankLine(): void
    {
        $this->assertSame("::: note\nbody\n:::\n", $this->fmt("::: note\nbody\n:::\n"));
    }

    /**
     * PART 11 §1: the canonical form re-parses to the same document, and
     * formatting it again changes nothing.
     */
    public function testTheEmptyContainerRoundTripsAndIsIdempotent(): void
    {
        foreach ([":::\n:::\n", "::: note\n:::\n", "- item\n  ::: note\ntail\n", "- ::: note\n  :::\n"] as $source) {
            $once = $this->fmt($source);
            $this->assertSame($once, $this->fmt($once), 'not idempotent: ' . $source);
            $converter = new CarveConverter();
            $this->assertSame(
                (new CarveConverter())->convert($source),
                $converter->convert($once),
                'html not preserved: ' . $source,
            );
        }
    }
}
