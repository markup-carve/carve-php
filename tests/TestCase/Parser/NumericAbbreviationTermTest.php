<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A digit-only abbreviation term does not crash the parser.
 *
 * `abbreviation_term = (letter | digit)+`, so `*[9]: nine` is a definition. The
 * block pattern already accepted it; what did not survive was the EXPANSION
 * pass. Abbreviations live in an array keyed by term, and PHP turns a numeric
 * string key into an int - so `array_keys()` handed `preg_quote()` an int and
 * the render died with
 *
 *   TypeError: preg_quote(): Argument #1 ($str) must be of type string, int given
 *
 * A fatal on a two-line document, not a wrong render: `bin/carve` exited 255 and
 * printed a stack trace (carve#791).
 *
 * Reached through any render, so the guard belongs where the keys are read
 * rather than at one call site.
 */
class NumericAbbreviationTermTest extends TestCase
{
    public function testADigitOnlyTermExpands(): void
    {
        $html = (new CarveConverter())->convert("*[9]: nine\n\nuse 9 here.\n");

        $this->assertStringContainsString('<abbr title="nine">9</abbr>', $html);
    }

    public function testTwoDigitOnlyTermsSortWithoutCrashing(): void
    {
        // The length sort runs a comparator only with two or more keys, so a
        // single numeric term never reached `strlen()` - this is the case that
        // does.
        $html = (new CarveConverter())->convert("*[9]: nine\n*[42]: answer\n\n9 and 42.\n");

        $this->assertStringContainsString('<abbr title="nine">9</abbr>', $html);
        $this->assertStringContainsString('<abbr title="answer">42</abbr>', $html);
    }

    public function testAMixedTermStillWorks(): void
    {
        // `1a` was already fine, because a key that is not a decimal integer
        // string stays a string. Pinned so a fix cannot regress it.
        $html = (new CarveConverter())->convert("*[1a]: first\n\nuse 1a here.\n");

        $this->assertStringContainsString('<abbr title="first">1a</abbr>', $html);
    }

    public function testTheDefinitionLineItselfRendersNothing(): void
    {
        $html = (new CarveConverter())->convert("*[9]: nine\n\nuse 9 here.\n");

        $this->assertStringNotContainsString('*[9]:', $html);
    }
}
