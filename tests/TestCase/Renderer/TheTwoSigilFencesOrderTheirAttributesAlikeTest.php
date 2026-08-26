<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function explode;
use function str_replace;
use function trim;

/**
 * The two sigil fences order their attributes ALIKE, and the way carve#1764
 * pins.
 *
 * An attribute line is authored BEFORE the fence, so its slots come first in
 * source order - `id` before `class` for `{#x .y}` - and the fence's own
 * structural class is appended at the first class position: `y hardbreaks`,
 * `y line-block`. An attribute line carrying only an id still yields
 * `id="x" class="hardbreaks"`.
 *
 * THE TWO FENCES REACH THAT ORDER BY DIFFERENT MECHANISMS, which is why they
 * are asserted side by side rather than one at a time. The hard-break fence
 * adds `hardbreaks` at PARSE time through `mergeLeadingAttributes`
 * (carve-php#1766); the line block adds `line-block` at RENDER time through
 * `HtmlRenderer::mergeAttribute()`. One rule, two spellings - the shape
 * markup-carve/carve#755 catalogs - and the two have already disagreed once:
 * before carve-php#1766 the hard-break fence emitted
 * `class="hardbreaks y" id="x"` where the line block emitted
 * `id="x" class="y line-block"` (carve-php#1771).
 *
 * THE ID IS WHAT THESE ADD. The class merge order is pinned for both fences by
 * `CarveConverterTest`, but nothing pinned the `id`'s POSITION on either, so a
 * change that moved the structural class to the front of the attribute list -
 * rather than to the front of the class list - passed the suite while reordering
 * every attributed sigil fence in the corpus.
 */
class TheTwoSigilFencesOrderTheirAttributesAlikeTest extends TestCase
{
    /**
     * The hard-break opener is `:::` plus a space plus a literal backslash.
     *
     * @var string
     */
    private const HARD_BREAK_OPENER = '::: \\';

    /**
     * @var string
     */
    private const LINE_BLOCK_OPENER = '::: |';

    private function openingTag(string $source): string
    {
        return trim(explode("\n", (new CarveConverter())->convert($source))[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function attributeLines(): iterable
    {
        yield 'an id and a class' => ['{#x .y}', 'id="x" class="y %s"'];
        yield 'an id alone' => ['{#x}', 'id="x" class="%s"'];
        yield 'a class alone' => ['{.y}', 'class="y %s"'];
        yield 'two classes after an id' => ['{#x .y .z}', 'id="x" class="y z %s"'];
        yield 'a custom slot after the class' => ['{#x .y k=v}', 'id="x" class="y %s" k="v"'];
    }

    #[DataProvider('attributeLines')]
    public function testTheHardBreakFenceKeepsSourceOrderAndAppendsItsClass(string $line, string $shape): void
    {
        $this->assertSame(
            '<div ' . str_replace('%s', 'hardbreaks', $shape) . '>',
            $this->openingTag($line . "\n" . self::HARD_BREAK_OPENER . "\none\n:::\n"),
        );
    }

    #[DataProvider('attributeLines')]
    public function testTheLineBlockKeepsSourceOrderAndAppendsItsClass(string $line, string $shape): void
    {
        $this->assertSame(
            '<div ' . str_replace('%s', 'line-block', $shape) . '>',
            $this->openingTag($line . "\n" . self::LINE_BLOCK_OPENER . "\none\n:::\n"),
        );
    }

    #[DataProvider('attributeLines')]
    public function testTheTwoFencesDifferOnlyInTheirStructuralClass(string $line, string $shape): void
    {
        // The agreement itself, stated as one assertion so a future divergence
        // reads as what it is rather than as two unrelated failures. The shape
        // is unused here on purpose: the provider is shared with the two
        // per-fence tests above, and this one compares the fences to each other
        // rather than either to a written-out expectation.
        $this->assertSame(
            str_replace('hardbreaks', '', $this->openingTag($line . "\n" . self::HARD_BREAK_OPENER . "\none\n:::\n")),
            str_replace('line-block', '', $this->openingTag($line . "\n" . self::LINE_BLOCK_OPENER . "\none\n:::\n")),
            'the two sigil fences disagree for ' . $line . ' (shape ' . $shape . ')',
        );
    }

    public function testAnUnattributedFenceCarriesItsStructuralClassAlone(): void
    {
        // The control: without an attribute line there is nothing to order, so a
        // pass above cannot mean the fences stopped carrying their class.
        $this->assertSame('<div class="hardbreaks">', $this->openingTag(self::HARD_BREAK_OPENER . "\none\n:::\n"));
        $this->assertSame('<div class="line-block">', $this->openingTag(self::LINE_BLOCK_OPENER . "\none\n:::\n"));
    }
}
