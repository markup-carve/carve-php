<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An abbreviation definition written inside OPAQUE content is not a definition.
 *
 * The scan walked every line, so `*[A]: x` inside a fenced code SAMPLE
 * registered an abbreviation for the whole document - documenting the syntax
 * changed the prose around it. The same line inside a line block did too, and
 * was expanded in place, so verse showed an `<abbr>` the author never wrote.
 *
 * A footnote definition is already skipped in a code fence by the scan beside
 * this one, and carve-rs skips abbreviations in both places (carve#573,
 * carve#574).
 */
class AbbreviationDefinitionOpaqueTest extends TestCase
{
    private function convert(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testACodeFenceDoesNotDefineAnAbbreviation(): void
    {
        $html = $this->convert("A here.\n\n```\n*[A]: x\n```\n");

        $this->assertStringNotContainsString('<abbr', $html);
        $this->assertStringContainsString('*[A]: x', $html);
    }

    public function testATildeFenceDoesNotDefineAnAbbreviation(): void
    {
        $html = $this->convert("A here.\n\n~~~\n*[A]: x\n~~~\n");

        $this->assertStringNotContainsString('<abbr', $html);
    }

    public function testALineBlockDoesNotDefineAnAbbreviation(): void
    {
        $html = $this->convert("A here.\n\n::: |\n*[A]: x\n:::\n");

        $this->assertStringNotContainsString('<abbr', $html);
    }

    public function testTheDefinitionStaysLiteralInsideItsVerse(): void
    {
        $html = $this->convert("::: |\n*[A]: x\nA here\n:::\n");

        $this->assertStringNotContainsString('<abbr', $html);
        $this->assertStringContainsString('*[A]: x', $html);
    }

    public function testATopLevelDefinitionStillWorks(): void
    {
        $this->assertStringContainsString(
            '<abbr title="x">A</abbr>',
            $this->convert("*[A]: x\n\nA here.\n"),
        );
    }

    public function testADefinitionAfterAFenceStillWorks(): void
    {
        $this->assertStringContainsString(
            '<abbr title="x">A</abbr>',
            $this->convert("```\nsample\n```\n\n*[A]: x\n\nA here.\n"),
        );
    }

    public function testAWiderLineBlockFenceClosesOnItsOwnWidth(): void
    {
        $html = $this->convert("A here.\n\n:::: |\n*[A]: x\n:::\nstill verse\n::::\n");

        $this->assertStringNotContainsString('<abbr', $html);
    }
}
