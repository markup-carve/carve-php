<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Converter;

use Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotToCarveTest extends TestCase
{
    protected DjotToCarve $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotToCarve();
    }

    public function testEmphasisUnderscoreBecomesSlash(): void
    {
        $this->assertSame('/text/', $this->converter->convert('_text_'));
    }

    public function testSubscriptTildeBecomesCommas(): void
    {
        $this->assertSame('H,,2,,O', $this->converter->convert('H~2~O'));
    }

    public function testHighlightBracesBecomesEquals(): void
    {
        $this->assertSame('==important==', $this->converter->convert('{=important=}'));
    }

    public function testMarkdownStrongBecomesSingleStar(): void
    {
        $this->assertSame('*bold*', $this->converter->convert('**bold**'));
    }

    public function testMarkdownStrikethroughBecomesSingleTilde(): void
    {
        $this->assertSame('~struck~', $this->converter->convert('~~struck~~'));
    }

    public function testNestedDifferentFamilies(): void
    {
        $this->assertSame('~/x/~', $this->converter->convert('~~_x_~~'));
    }

    public function testEmphasisWithSubscriptNests(): void
    {
        $this->assertSame('/,,x,,/', $this->converter->convert('_~x~_'));
    }

    public function testCodeSpanIsUntouched(): void
    {
        $this->assertSame('`_x_`', $this->converter->convert('`_x_`'));
    }

    public function testFencedBlockIsUntouched(): void
    {
        $input = "```\n_x_\n```";
        $this->assertSame($input, $this->converter->convert($input));
    }

    public function testLinkDestinationIsUntouched(): void
    {
        $this->assertSame('[home](/~user/index)', $this->converter->convert('[home](/~user/index)'));
    }

    public function testEscapedDelimiterLeftLiteral(): void
    {
        $this->assertSame('\\_x_', $this->converter->convert('\\_x_'));
    }

    public function testWordInternalUnderscoreNotMatched(): void
    {
        $this->assertSame('snake_case_word', $this->converter->convert('snake_case_word'));
    }

    public function testUnchangedConstructs(): void
    {
        $input = '2^10^ {+ins+} {-del-}';
        $this->assertSame($input, $this->converter->convert($input));
    }

    public function testEmptyString(): void
    {
        $this->assertSame('', $this->converter->convert(''));
    }

    public function testPlusBulletBecomesDash(): void
    {
        // Djot allows `+` bullets; Carve does not (it is the continuation
        // marker), so a `+` list is normalized to `-` to survive conversion.
        $this->assertSame("- one\n- two", $this->converter->convert("+ one\n+ two"));
    }

    public function testIndentedPlusBulletBecomesDash(): void
    {
        $this->assertSame("- a\n  - b", $this->converter->convert("+ a\n  + b"));
    }

    public function testLonePlusContinuationMarkerIsUntouched(): void
    {
        // A lone `+` is the Carve list-continuation marker, not a bullet.
        $this->assertSame("- item\n+\n> note", $this->converter->convert("- item\n+\n> note"));
    }

    public function testPlusBulletInFencedBlockIsUntouched(): void
    {
        $input = "```\n+ literal\n```";
        $this->assertSame($input, $this->converter->convert($input));
    }
}
