<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Strong;
use PHPUnit\Framework\TestCase;

/**
 * The combined bold-italic form is a single production, and the nested spelling
 * parses to the SAME Strong wrapping Emphasis - so the nesting does not record
 * which one the author wrote, and this renderer normalized the combined form into
 * the nested one.
 *
 * That rewrote the spelling Carve DOCUMENTS (docs/cheatsheet.md,
 * docs/migrate-from-markdown.md as the replacement for Markdown's `***both***`)
 * into one documented nowhere (PART 11 section 6; carve#375).
 */
class BoldItalicAuthoredFormTest extends TestCase
{
    /**
     * @var string
     */
    private const COMBINED = "/*x*/\n";

    /**
     * @var string
     */
    private const NESTED = "*/x/*\n";

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function firstStrong(string $source): Strong
    {
        $paragraph = $this->converter->parse($source)->getChildren()[0];
        foreach ($paragraph->getChildren() as $child) {
            if ($child instanceof Strong) {
                return $child;
            }
        }

        $this->fail('no Strong node in ' . var_export($source, true));
    }

    public function testOnlyTheCombinedFormIsMarked(): void
    {
        $this->assertTrue($this->firstStrong(self::COMBINED)->isBoldItalic());
        $this->assertFalse($this->firstStrong(self::NESTED)->isBoldItalic());
    }

    public function testEachSpellingIsReproducedByteExactly(): void
    {
        $this->assertSame(self::COMBINED, CarveConverter::toCarve(self::COMBINED));
        $this->assertSame(self::NESTED, CarveConverter::toCarve(self::NESTED));
    }

    /**
     * Which is why the distinction has to live in the tree rather than be
     * recovered from the output.
     */
    public function testBothSpellingsRenderTheSameHtml(): void
    {
        $this->assertSame(
            $this->converter->convert(self::COMBINED),
            $this->converter->convert(self::NESTED),
        );
    }

    /**
     * A bare `/` needs a word boundary, so the nested spelling is not the same
     * document here: the two-char token skips that guard.
     */
    public function testTheMidWordFormSurvives(): void
    {
        $this->assertSame("a/*y*/b\n", CarveConverter::toCarve("a/*y*/b\n"));
    }

    public function testItalicNestedInsideBoldItalicSurvives(): void
    {
        $source = "/*a /b/ c*/\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
        $this->assertSame(
            $this->converter->convert($source),
            $this->converter->convert(CarveConverter::toCarve($source)),
        );
    }

    public function testAnOrdinaryStrongIsUntouched(): void
    {
        $this->assertSame("*x*\n", CarveConverter::toCarve("*x*\n"));
    }

    public function testEverySpellingStaysIdempotentAndMeaningPreserving(): void
    {
        $cases = [self::COMBINED, self::NESTED, "/*bold italic*/\n", "a/*y*/b\n", "/*a /b/ c*/\n"];
        foreach ($cases as $source) {
            $once = CarveConverter::toCarve($source);

            $this->assertSame($once, CarveConverter::toCarve($once), "not idempotent: {$source}");
            $this->assertSame(
                $this->converter->convert($source),
                $this->converter->convert($once),
                "meaning changed: {$source}",
            );
        }
    }
}
