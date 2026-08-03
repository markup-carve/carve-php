<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 5: `abbreviation_expansion = {character - newline}+` - ONE or more.
 *
 * `*[A]: ` with nothing after the separator is not a definition, so the line is
 * paragraph text. This engine consumed it, which deleted it from the document.
 *
 * It was the last definition kind where that was still true: a link reference
 * and a footnote definition with no content are already kept as text in all
 * three engines. carve-js already implements the production; carve-rs is
 * carve-rs#487 (carve-php#674).
 *
 * The boundary the grammar draws, which carve-js already follows: a SECOND
 * trailing space IS an expansion, because a space is a character.
 */
class AbbreviationExpansionRequiredTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    public function testAnEmptyExpansionIsNotADefinition(): void
    {
        $this->assertSame('<p>*[A]:</p>', $this->squash($this->converter->convert("*[A]: \n")));
    }

    public function testNothingIsSilentlyDropped(): void
    {
        // The sharp end: the line rendered NOTHING, so it vanished.
        $this->assertNotSame('', trim($this->converter->convert("*[A]: \n")));
    }

    public function testOneCharacterOfExpansionIsEnough(): void
    {
        // A second space is a character, so this IS a definition - unused, so it
        // renders nothing. Pinned because it is the boundary, not an accident.
        $this->assertSame('', trim($this->converter->convert("*[A]:  \n")));
        $this->assertSame('', trim($this->converter->convert("*[A]: \t\n")));
    }

    public function testARealDefinitionStillWorks(): void
    {
        $html = $this->converter->convert("*[HTML]: HyperText Markup Language\n\nHTML rules.\n");

        $this->assertStringContainsString('<abbr', $html);
        $this->assertStringContainsString('HyperText Markup Language', $html);
    }

    public function testNoSeparatorSpaceIsUnchanged(): void
    {
        $this->assertSame('<p>*[A]:</p>', $this->squash($this->converter->convert("*[A]:\n")));
    }
}
