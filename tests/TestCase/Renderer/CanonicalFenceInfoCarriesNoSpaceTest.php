<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The canonical writer emits NO space between a code fence and its info string.
 *
 * `fenced_code_block` (resources/grammar.ebnf) states it for the writer while
 * leaving the reader lenient:
 *
 *   "The space between the fence and the info string is OPTIONAL (lenient:
 *    both ```php and ``` php are accepted; Markdown writes no space, Djot
 *    writes the space). The no-space form (```php) is canonical and is what
 *    the X->Carve converters emit. It is a PADDING SLOT, not a marker
 *    separator (PART 7)"
 *
 * The writer emitted the Djot spelling instead, in every engine. Nothing caught
 * it, and the reason it could not be caught by the existing checks is the first
 * half of that same clause: the reader accepts both, so parse(fmt(x)) ==
 * parse(x), fmt(fmt(x)) == fmt(x) and toHtml(fmt(x)) == toHtml(x) all hold
 * either way. Only a BYTE assertion on the writer's output can tell the
 * canonical form from the accepted one, which is what this file makes.
 *
 * The two slots are different slots and only one of them moves:
 *
 *  - the slot before the info string is `[space]`, optional, canonically
 *    absent;
 *  - the two slots INSIDE `code_fence_info` are `space+`, mandatory. ```php"t"
 *    is not a fence opener at all, so the separators between language, header
 *    and label stay exactly one space each.
 */
class CanonicalFenceInfoCarriesNoSpaceTest extends TestCase
{
    protected static function toHtml(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{0: string, 1: string}> authored, canonical
     */
    public static function shapeProvider(): array
    {
        return [
            'a language only' => ["``` php\nx\n```\n", "```php\nx\n```\n"],
            'a language and a quoted title' => [
                "``` php \"src/App.php\"\nx\n```\n",
                "```php \"src/App.php\"\nx\n```\n",
            ],
            'a language and a label' => ["``` php [tab-a]\nx\n```\n", "```php [tab-a]\nx\n```\n"],
            'a language, a title and a label' => [
                "``` php \"src/App.php\" [tab-a]\nx\n```\n",
                "```php \"src/App.php\" [tab-a]\nx\n```\n",
            ],
            'a title with no language' => ["``` \"src/App.php\"\nx\n```\n", "```\"src/App.php\"\nx\n```\n"],
            'a label with no language' => ["``` [tab-a]\nx\n```\n", "```[tab-a]\nx\n```\n"],
            /*
             * `raw_block` spells its otherwise identical slot the same way, so
             * it is checked rather than assumed: the `=` after the slot SELECTS
             * a raw block over a code block, and the grammar permits leading
             * whitespace before it, so ``` =html reads as a raw block and would
             * have hidden the same defect.
             *
             * THIS ROW WAS ALREADY CORRECT. The raw block writes its own opener
             * and never went through the fence-info builder, so it passed before
             * this change and after it. It is kept as a check, not as a fix: it
             * fails when the slot is widened there, which is how the defect
             * would arrive.
             */
            'a raw block' => ["``` =html\n<b>raw</b>\n```\n", "```=html\n<b>raw</b>\n```\n"],
        ];
    }

    #[DataProvider('shapeProvider')]
    public function testTheDjotSpellingNormalizesToTheCanonicalOne(string $authored, string $canonical): void
    {
        $this->assertSame($canonical, CarveConverter::toCarve($authored));
    }

    #[DataProvider('shapeProvider')]
    public function testTheCanonicalSpellingIsAFixedPoint(string $authored, string $canonical): void
    {
        $this->assertSame($canonical, CarveConverter::toCarve($canonical));
    }

    #[DataProvider('shapeProvider')]
    public function testTheWriterIsIdempotent(string $authored, string $canonical): void
    {
        $once = CarveConverter::toCarve($authored);

        $this->assertSame($once, CarveConverter::toCarve($once));
    }

    #[DataProvider('shapeProvider')]
    public function testTheDocumentStillSaysTheSameThing(string $authored, string $canonical): void
    {
        $this->assertSame(
            self::toHtml($authored),
            self::toHtml(CarveConverter::toCarve($authored)),
        );
    }

    /**
     * The control. A fence with NO info string has nothing to separate, so it is
     * the case that would expose a fix written as "drop one character after the
     * run".
     */
    public function testAFenceWithNoInfoStringNeitherGainsNorLosesASpace(): void
    {
        $source = "```\nx\n```\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
        $this->assertSame($source, CarveConverter::toCarve(CarveConverter::toCarve($source)));
    }

    /**
     * A tilde fence reaches the writer as the same node - `code_block` records no
     * fence character (PART 12 §3) - so it comes back as backticks. That
     * normalization is pre-existing and is not what this file is about; it is
     * asserted so the row is not read as the slot rule failing to apply to
     * tildes.
     */
    public function testATildeFenceIsRespelledWithBackticksAndStillCarriesNoSpace(): void
    {
        $this->assertSame("```php\nx\n```\n", CarveConverter::toCarve("~~~ php\nx\n~~~\n"));
    }

    /**
     * Inside a container the writer emits the container's own prefix and then the
     * fence. The slot sits after the fence run, so the prefix is unaffected and
     * the fence is still tight against its language.
     */
    public function testTheRuleHoldsUnderAContainerPrefix(): void
    {
        $inAList = "- item\n\n  ``` php\n  x\n  ```\n";
        $inAQuote = "> quoted\n>\n> ``` php\n> x\n> ```\n";

        $list = CarveConverter::toCarve($inAList);
        $this->assertStringContainsString("```php\n", $list);
        $this->assertStringNotContainsString('``` php', $list);
        $this->assertSame(self::toHtml($inAList), self::toHtml($list));

        $quote = CarveConverter::toCarve($inAQuote);
        $this->assertStringContainsString("> ```php\n", $quote);
        $this->assertStringNotContainsString('``` php', $quote);
        $this->assertSame(self::toHtml($inAQuote), self::toHtml($quote));
    }

    /**
     * The slot the fix must NOT touch. These separators are `space+` inside
     * `code_fence_info`; removing one does not tighten the opener, it stops the
     * line being a fence opener and the run falls back to an inline code span
     * (the INVALID-FENCE FALLBACK). So the reader is checked here too: if a later
     * change were to join the parts without a separator, the writer would emit a
     * document that no longer holds a code block at all.
     */
    public function testTheSeparatorsInsideTheInfoStringAreNotTheSameSlot(): void
    {
        $this->assertStringNotContainsString('<pre', self::toHtml("```php\"t\"\nx\n```\n"));
        $this->assertStringNotContainsString('<pre', self::toHtml("```php[l]\nx\n```\n"));

        $source = "```php \"t\" [l]\nx\n```\n";
        $this->assertSame($source, CarveConverter::toCarve($source));
    }
}
