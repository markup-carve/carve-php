<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 section 11 / carve#1870. All five unchecked spellings render the same
 * box, so the item names the state it was written with.
 */
class ATaskStateNamesItselfInHtmlTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<array{string}>
     */
    public static function extendedStates(): array
    {
        return [['-'], ['_'], ['?']];
    }

    #[DataProvider('extendedStates')]
    public function testTheItemNamesTheState(string $state): void
    {
        $this->assertStringContainsString(
            '<li data-task-state="' . $state . '">',
            $this->converter->convert("- [$state] a\n"),
        );
    }

    public function testTheValueIsEscapedLikeAnyOther(): void
    {
        $this->assertStringContainsString('<li data-task-state="&gt;">', $this->converter->convert("- [>] a\n"));
    }

    public function testTheTwoStatesTheBoxTellsApartCarryNothing(): void
    {
        $this->assertStringNotContainsString('data-task-state', $this->converter->convert("- [ ] a\n"));
        $this->assertStringNotContainsString('data-task-state', $this->converter->convert("- [x] a\n"));
        $this->assertStringNotContainsString('data-task-state', $this->converter->convert("- a\n"));
    }

    public function testItLeadsTheAuthoredAttributes(): void
    {
        $this->assertStringContainsString(
            '<li data-task-state="?" class="c">',
            $this->converter->convert("-{.c} [?] q\n"),
        );
    }

    public function testTheStateSurvivesARenderAndImportCycle(): void
    {
        $renderer = new CarveRenderer();
        $source = $renderer->render($this->converter->parse("- [-] dropped\n- [x] done\n- [ ] open\n"));
        $imported = (new HtmlToCarve())->convert($this->converter->convert($source));

        $this->assertSame($source, $renderer->render($this->converter->parse($imported)));
    }

    public function testAValueOutsideTheEnumerationStaysTheAuthorsAttribute(): void
    {
        $imported = (new HtmlToCarve())->convert(
            '<ul><li data-task-state="/"><input type="checkbox" disabled> odd</li></ul>',
        );

        $this->assertSame("-{data-task-state=/} [ ] odd\n", $imported);
    }

    public function testAPlainItemAfterATaskKeepsItsOwnAttribute(): void
    {
        // A loop variable survives its iteration in PHP, so a plain item after
        // an extended-state task inherited the previous item's state and lost
        // its own authored attribute to the skip list.
        $imported = (new HtmlToCarve())->convert(
            '<ul><li data-task-state="-"><input type="checkbox" disabled> a</li>'
            . '<li data-task-state="/">b</li></ul>',
        );

        $this->assertSame("- [-] a\n-{data-task-state=/} b\n", $imported);
    }

    public function testAStateTheBoxContradictsDoesNotTickTheBox(): void
    {
        $imported = (new HtmlToCarve())->convert(
            '<ul><li data-task-state="x"><input type="checkbox" disabled> a</li></ul>',
        );

        $this->assertSame("-{data-task-state=x} [ ] a\n", $imported);
    }
}
