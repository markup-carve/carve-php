<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 §T9: a header cell states what it heads.
 *
 * `col` in the leading header-row run, `row` below it. The corpus pins the
 * positional default; what this file adds is the AUTHORED cases, which the
 * corpus has no document for and which are the ones a careless implementation
 * gets wrong.
 */
class TableHeaderScopeTest extends TestCase
{
    private function render(string $carve): string
    {
        return (string)preg_replace('/\s+/', ' ', trim(CarveConverter::create()->convert($carve)));
    }

    /**
     * An authored scope REPLACES the default rather than joining it. Emitting
     * both gives `<th scope="col" scope="colgroup">` - two attributes of one
     * name, invalid HTML, and not an override. Suppressing the default is also
     * what keeps `colgroup` and `rowgroup` reachable, since neither has a
     * marker spelling here.
     */
    public function testAnAuthoredScopeReplacesTheDefault(): void
    {
        $html = $this->render("|{scope=\"colgroup\"} a |\n|---|\n| b |\n");

        $this->assertStringContainsString('<th scope="colgroup">a</th>', $html);
        $this->assertStringNotContainsString('scope="col" scope=', $html);
    }

    /**
     * The suppression test is case-INSENSITIVE, the one place this rule departs
     * from Carve's case-sensitive attribute names. `{Scope=…}` stays a
     * different Carve attribute and still reaches the output as `Scope`, but
     * HTML attribute names are not case-sensitive, so emitting the default
     * beside it is the same collision by another spelling.
     */
    public function testAnAuthoredScopeInAnotherCaseAlsoSuppressesTheDefault(): void
    {
        $html = $this->render("|{Scope=\"x\"} a |\n|---|\n| b |\n");

        $this->assertStringContainsString('<th Scope="x">a</th>', $html);
        $this->assertStringNotContainsString('scope="col"', $html);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function positionProvider(): array
    {
        return [
            'a head-row cell heads its column' => ["|= A |= B |\n| 1 | 2 |\n", '<th scope="col">A</th>'],
            'a body-row marker cell heads its row' => ["|= A |= B |\n|= R | 1 |\n", '<th scope="row">R</th>'],
            'the default leads the author\'s attributes' => ["|{.hl} a |\n|---|\n| b |\n", '<th scope="col" class="hl">a</th>'],
        ];
    }

    #[DataProvider('positionProvider')]
    public function testTheDefaultComesFromPosition(string $carve, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->render($carve));
    }

    /**
     * The default is GENERATED, so importing it back would write the
     * generator's own output as if the author had typed it: a round trip
     * turned a table with no attribute block at all into `|{scope=col} Left |`.
     */
    public function testTheGeneratedScopeDoesNotComeBackAsAnAuthoredAttribute(): void
    {
        $html = '<table><thead><tr><th scope="col">A</th></tr></thead>'
            . '<tbody><tr><th scope="row">R</th><td>1</td></tr></tbody></table>';

        $carve = (new HtmlToCarve())->convert($html);

        $this->assertStringNotContainsString('scope', $carve);
    }

    /**
     * An authored value is not reproducible from position, so it survives the
     * import - the same asymmetry the renderer applies.
     */
    public function testAnAuthoredScopeSurvivesTheImport(): void
    {
        $html = '<table><thead><tr><th scope="colgroup">A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>';

        $this->assertStringContainsString('scope=colgroup', (new HtmlToCarve())->convert($html));
    }
}
