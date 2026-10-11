<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\AdmonitionExtension;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Extension\MathBlockExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 §2 allows `&nbsp;` as the one entity beyond the three, and only in
 * TEXT CONTENT. A no-break space in an attribute value survives as itself, in
 * the core renderer and in every extension that writes an attribute of its own.
 */
class AttributeNoBreakSpaceTest extends TestCase
{
    /**
     * @var string
     */
    protected const NBSP = "\u{00A0}";

    public function testCoreAttributeKeepsTheRawCharacterAndTextTakesTheEntity(): void
    {
        $html = (new CarveConverter())->convert('{k="A' . self::NBSP . 'B"}' . "\n" . '# H' . self::NBSP . 'X');

        $this->assertStringContainsString('k="A' . self::NBSP . 'B"', $html);
        $this->assertStringContainsString('>H&nbsp;X<', $html);
    }

    public function testTocPlacementFragmentKeepsTheRawCharacter(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());

        $html = $converter->convert('# A' . self::NBSP . "B\n\n::: toc\n:::");

        $this->assertStringContainsString('href="#A' . self::NBSP . 'B"', $html);
        $this->assertStringNotContainsString('href="#A&nbsp;B"', $html);
    }

    public function testTabsRoundTripCarrierKeepsTheRawCharacter(): void
    {
        $converter = new CarveConverter(roundTripMode: true);
        $converter->addExtension(new TabsExtension());

        $html = $converter->convert(":::: tabs\n::: tab\n# A" . self::NBSP . "B\nbody\n:::\n::::");

        $this->assertStringContainsString('# A' . self::NBSP . 'B', $html);
        $this->assertDoesNotMatchRegularExpression('/data-carve-src="[^"]*&nbsp;/', $html);
    }

    public function testCodeGroupRoundTripCarrierKeepsTheRawCharacter(): void
    {
        $converter = new CarveConverter(roundTripMode: true);
        $converter->addExtension(new CodeGroupExtension());

        $html = $converter->convert('{k="A' . self::NBSP . 'B"}' . "\n::: code-group\n``` php\nx\n```\n:::");

        $this->assertDoesNotMatchRegularExpression('/data-carve-src="[^"]*&nbsp;/', $html);
    }

    public function testAdmonitionTitleCarrierKeepsTheRawCharacterWhileTheTitleTextTakesTheEntity(): void
    {
        $converter = new CarveConverter(roundTripMode: true);
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert('::: note "A' . self::NBSP . 'B"' . "\nContent.\n:::");

        $this->assertStringContainsString('data-carve-admonition-title="A' . self::NBSP . 'B"', $html);
        $this->assertStringContainsString('<p class="admonition-title">A&nbsp;B</p>', $html);
    }

    public function testFencedRenderAuthorAttributeKeepsTheRawCharacter(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new FencedRenderExtension(language: 'myuml', cssClass: 'myuml'));

        $html = $converter->convert('{k="A' . self::NBSP . 'B"}' . "\n``` myuml\nbody\n```");

        $this->assertStringContainsString('k="A' . self::NBSP . 'B"', $html);
        $this->assertStringNotContainsString('k="A&nbsp;B"', $html);
    }

    public function testMathBlockAuthorAttributeKeepsTheRawCharacter(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension());

        $html = $converter->convert('{k="A' . self::NBSP . 'B"}' . "\n``` math\nx = 1\n```");

        $this->assertStringContainsString('k="A' . self::NBSP . 'B"', $html);
    }
}
