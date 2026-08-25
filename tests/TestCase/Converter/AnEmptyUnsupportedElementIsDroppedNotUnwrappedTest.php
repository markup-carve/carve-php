<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unsupported element with nothing to unwrap is DROPPED, not unwrapped
 * (ruling markup-carve/carve#1738).
 *
 * `element-unwrapped` makes a claim about CONTENT: the wrapper went and what it
 * held stayed. This file already answered that question for every element it
 * has no mapping for - {@see \MarkupCarve\Carve\Converter\HtmlToCarve::reportImportElementOutcome()}
 * asks `hasImportContentToUnwrap()` and picks the code from the answer - and
 * left ONE path unconditional: the register of unwrapped block containers,
 * which wrote `element-unwrapped` for a `<form>`, a `<dialog>` or a `<section>`
 * whether or not it held anything. An empty one held nothing, so nothing
 * stayed, and the row stated something about content that did not happen.
 *
 * BOTH HALVES OF THE SAME ELEMENT, deliberately. A test that only pinned the
 * empty case passes an implementation that made every block container
 * `dropped`, which would be a worse report than the one this replaces - so each
 * element below is asserted twice, once holding content and once empty.
 *
 * WHY THIS PATH AND NOT A NAME LIST. The ruling makes carve-js and carve-rs
 * content-sensitive on the arm they write the general row from. The same tag
 * reaches a different arm in each engine - a `<form>` takes carve-rs's inline
 * arm and carve-js's block arm and this engine's register - so leaving one
 * unconditional is what makes three engines disagree about `<form></form>`
 * while agreeing about `<progress></progress>`. Measured over 191 shapes across
 * the three engines, the element row diverged on 66 before the ruling and on 34
 * after it, with none newly diverging.
 *
 * ORDER IS UNCHANGED. carve-php#1739 put this row ahead of the rows naming what
 * the element carried, and the replacement is written at the same point in the
 * walk.
 */
class AnEmptyUnsupportedElementIsDroppedNotUnwrappedTest extends TestCase
{
    /**
     * @return list<array{code: string, severity: string}>
     */
    protected function rows(string $html, string $mode = 'safe'): array
    {
        return array_map(
            static fn (HtmlImportDiagnostic $diagnostic): array => [
                'code' => $diagnostic->code,
                'severity' => $diagnostic->severity,
            ],
            (new HtmlToCarve(importMode: $mode))->convertWithReport($html)->diagnostics,
        );
    }

    /**
     * @return list<array{code: string, severity: string}>
     */
    protected function elementRows(string $html, string $mode = 'safe'): array
    {
        return array_values(array_filter(
            $this->rows($html, $mode),
            static fn (array $row): bool => str_starts_with($row['code'], 'element-'),
        ));
    }

    /**
     * Block containers the register covers, written twice: holding content, and
     * holding nothing. `section` is here because it is the shape the register
     * was built for, and `form` because it is where the three engines parted.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockContainers(): array
    {
        return [
            'form' => ['<form>CONTENT</form>', '<form></form>'],
            'fieldset' => ['<fieldset>CONTENT</fieldset>', '<fieldset></fieldset>'],
            'address' => ['<address>CONTENT</address>', '<address></address>'],
            'hgroup' => ['<hgroup>CONTENT</hgroup>', '<hgroup></hgroup>'],
            'dialog' => ['<dialog>CONTENT</dialog>', '<dialog></dialog>'],
            'menu' => ['<menu>CONTENT</menu>', '<menu></menu>'],
            'search' => ['<search>CONTENT</search>', '<search></search>'],
            'section' => ['<section>CONTENT</section>', '<section></section>'],
            'article' => ['<article>CONTENT</article>', '<article></article>'],
            'aside' => ['<aside>CONTENT</aside>', '<aside></aside>'],
            'nav' => ['<nav>CONTENT</nav>', '<nav></nav>'],
        ];
    }

    #[DataProvider('blockContainers')]
    public function testABlockContainerUnwrapsWithContentAndIsDroppedWithout(string $withContent, string $empty): void
    {
        $this->assertSame(
            [['code' => 'element-unwrapped', 'severity' => 'info']],
            $this->elementRows($withContent),
            $withContent,
        );
        $this->assertSame(
            [['code' => 'element-dropped', 'severity' => 'warning']],
            $this->elementRows($empty),
            $empty,
        );
    }

    /**
     * WHITESPACE IS NOT CONTENT, and the emitted source proves it: the document
     * is empty either way. This is the predicate the generic outcome test
     * already used, reused rather than restated.
     */
    public function testAContainerHoldingOnlyWhitespaceIsDroppedAndWritesNothing(): void
    {
        $this->assertSame('', trim((new HtmlToCarve())->convert('<form>   </form>')));
        $this->assertSame(
            [['code' => 'element-dropped', 'severity' => 'warning']],
            $this->elementRows('<form>   </form>'),
        );
    }

    /**
     * AN ACTIVE CHILD IS NOT CONTENT EITHER. A `<script>` never survives an
     * import, so a container whose only child is one had nothing an unwrap
     * could preserve - and the `<script>` reports its own drop, which is why
     * the container saying `element-unwrapped` would be the only false row.
     */
    public function testAContainerWhoseOnlyChildIsActiveIsDropped(): void
    {
        $this->assertSame(
            [
                ['code' => 'element-dropped', 'severity' => 'warning'],
                ['code' => 'element-dropped', 'severity' => 'warning'],
            ],
            $this->elementRows('<form><script>1</script></form>'),
        );
    }

    /**
     * THE ELEMENT ROW STANDS AHEAD OF THE ATTRIBUTE ROWS IT INTRODUCES
     * (carve-php#1739), in both outcomes. Asserting the code alone would pass
     * an implementation that emitted the replacement after the attributes it is
     * supposed to introduce.
     */
    public function testTheElementRowStandsAheadOfItsAttributeRowsInBothOutcomes(): void
    {
        $this->assertSame(
            [
                ['code' => 'element-unwrapped', 'severity' => 'info'],
                ['code' => 'attribute-dropped', 'severity' => 'info'],
            ],
            $this->rows('<form action="/x">CONTENT</form>'),
        );
        $this->assertSame(
            [
                ['code' => 'element-dropped', 'severity' => 'warning'],
                ['code' => 'attribute-dropped', 'severity' => 'info'],
            ],
            $this->rows('<form action="/x"></form>'),
        );
    }

    /**
     * THE CONTROL FOR THE PATH THAT ALREADY ANSWERED. An element with no mapping
     * at all took its code from the content before this change and still does,
     * so a regression there would show here rather than in the shapes above.
     */
    public function testTheGenericOutcomeAnswerDidNotMove(): void
    {
        $this->assertSame(
            [['code' => 'element-unwrapped', 'severity' => 'info']],
            $this->elementRows('<progress value="1">FALLBACK</progress>'),
        );
        $this->assertSame(
            [['code' => 'element-dropped', 'severity' => 'warning']],
            $this->elementRows('<progress value="1"></progress>'),
        );
    }

    /**
     * A renderer-derived `<section role="doc-endnotes">` is silent, and the
     * content question does not reach it (PART 9 §16a). The register never
     * takes it, so neither row can fire.
     */
    public function testARendererDerivedSectionStaysSilent(): void
    {
        $this->assertSame(
            [],
            $this->elementRows('<section role="doc-endnotes"><ol><li>n</li></ol></section>'),
        );
    }

    /**
     * A `<div>` earns no element row, and a mapped element stays silent whether
     * it is empty or not: the change is the code and the severity on the empty
     * arm, and nothing about WHICH elements report at all.
     */
    public function testTheElementsThatReportNothingStillReportNothing(): void
    {
        foreach (['<div></div>', '<div>TEXT</div>', '<p></p>', '<p>TEXT</p>', '<em></em>', '<em>TEXT</em>'] as $html) {
            $this->assertSame([], $this->elementRows($html), $html);
        }
    }
}
