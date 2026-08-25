<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A sectioning wrapper unwraps instead of being written as a container fence.
 *
 * `<article id="k">` used to import as a `::: article` fence, on the premise
 * that the fence renders back as the element. IT DOES NOT: a container fence
 * renders as `<div class="name">` for EVERY name, so the import came back as
 * `<div class="article" id="k">`. That is both halves of a defect at once, and
 * nothing in the report said either:
 *
 * - the `<article>` the author wrote was GONE;
 * - a `class="article"` the document never carried was ADDED.
 *
 * The addition is the worse half. A declared loss is a ceiling an import may sit
 * inside; an addition is the document coming back saying something it never
 * said, and a reader cannot tell it was not authored (carve-php#1721).
 *
 * So the wrapper degrades to its content and the report names both the element
 * and what it carried, which is what carve-js and carve-rs do with the same
 * input, in the same words at the same severity for the element row.
 *
 * `<section>` is deliberately NOT here. It goes through `processSection()`,
 * which can put an authored id back on the heading below it, and this ticket
 * left that alone; `theSectionWrapperKeepsItsAuthoredId` pins it. What the
 * section DOES report is carve-php#1737's, in
 * `AnUnwrappedElementSaysSoBeforeItsAttributesDoTest` - the id coming back is a
 * statement about the attribute, and the element is gone either way.
 */
class ASectioningWrapperUnwrapsRatherThanBecomingADivTest extends TestCase
{
    /**
     * @return list<array{code: string, message: string, severity: string, path: string}>
     */
    protected function rows(string $html, string $mode = 'roundtrip'): array
    {
        return array_map(
            static fn (HtmlImportDiagnostic $diagnostic): array => [
                'code' => $diagnostic->code,
                'message' => $diagnostic->message,
                'severity' => $diagnostic->severity,
                'path' => $diagnostic->path,
            ],
            (new HtmlToCarve(importMode: $mode))->convertWithReport($html)->diagnostics,
        );
    }

    protected function carve(string $html, string $mode = 'roundtrip'): string
    {
        return (new HtmlToCarve(importMode: $mode))->convert($html);
    }

    /**
     * The ticket's own input, and the whole of its claim: the fence is gone, the
     * unwrap is declared, and the attribute it could not carry is declared too.
     */
    public function testTheTicketsInputUnwrapsAndDeclaresBothHalves(): void
    {
        $html = '<article id="k"><p>a</p></article>';

        $this->assertSame("a\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => 'Unwrapped unsupported <article> element',
                    'severity' => 'info',
                    'path' => '/article[1]',
                ],
                [
                    'code' => 'attribute-dropped',
                    'message' => 'Dropped unsupported attribute id on <article>',
                    'severity' => 'info',
                    'path' => '/article[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * THE ADDITION IS THE HALF NO ROW CAN DECLARE, so it is asserted on the
     * re-rendered document rather than on the report: whatever else the import
     * loses, nothing the author did not write may come back.
     */
    public function testNothingTheDocumentNeverCarriedComesBack(): void
    {
        $rendered = (new CarveConverter())->convert($this->carve('<article id="k"><p>a</p></article>'));

        $this->assertStringNotContainsString('class="article"', $rendered);
        $this->assertStringNotContainsString('<div', $rendered);
        $this->assertSame("<p>a</p>\n", $rendered);
    }

    /**
     * Every sectioning name behaved the same way and is fixed the same way.
     *
     * @return array<string, array{0: string}>
     */
    public static function sectioningWrappers(): array
    {
        return [
            'article' => ['article'],
            'main' => ['main'],
            'header' => ['header'],
            'footer' => ['footer'],
            'nav' => ['nav'],
            'aside' => ['aside'],
        ];
    }

    #[DataProvider('sectioningWrappers')]
    public function testEverySectioningNameUnwrapsWithAttributes(string $tag): void
    {
        $html = '<' . $tag . ' id="k"><p>a</p></' . $tag . '>';

        $this->assertSame("a\n", $this->carve($html));
        $this->assertSame(
            ['element-unwrapped', 'attribute-dropped'],
            array_column($this->rows($html), 'code'),
        );
        $this->assertSame(
            'Unwrapped unsupported <' . $tag . '> element',
            $this->rows($html)[0]['message'],
        );
    }

    /**
     * The unwrap was already happening for a wrapper carrying no attributes -
     * there was no fence to build - and it was silent there too.
     */
    #[DataProvider('sectioningWrappers')]
    public function testAnUnattributedWrapperIsDeclaredToo(string $tag): void
    {
        $html = '<' . $tag . '><p>a</p></' . $tag . '>';

        $this->assertSame("a\n", $this->carve($html));
        $this->assertSame(['element-unwrapped'], array_column($this->rows($html), 'code'));
    }

    /**
     * The row stands AHEAD of the rows about the attributes the wrapper carried,
     * which is the order both sibling engines report.
     */
    public function testTheUnwrapIsReportedBeforeTheAttributesItCarried(): void
    {
        $rows = $this->rows('<article id="a1" data-kind="post"><p>X</p></article>');

        $this->assertSame(
            ['element-unwrapped', 'attribute-dropped', 'attribute-dropped'],
            array_column($rows, 'code'),
        );
    }

    /**
     * It is not a mode. `roundtrip` keeps what Carve cannot express BYTE FOR
     * BYTE, and a sectioning wrapper is not one of those names: turning a
     * page's header, nav and footer into opaque raw blocks would make the most
     * common wrappers in a document unreadable as Carve. Both sibling engines
     * unwrap them in `roundtrip` too.
     *
     * @return array<string, array{0: string}>
     */
    public static function modes(): array
    {
        return ['safe' => ['safe'], 'semantic' => ['semantic'], 'roundtrip' => ['roundtrip']];
    }

    #[DataProvider('modes')]
    public function testTheUnwrapIsTheSameInEveryMode(string $mode): void
    {
        $html = '<nav id="k"><p>a</p></nav>';

        $this->assertSame("a\n", $this->carve($html, $mode));
        $this->assertSame(['element-unwrapped', 'attribute-dropped'], array_column($this->rows($html, $mode), 'code'));
    }

    /**
     * AN ADMONITION ASIDE IS NOT A SECTIONING WRAPPER, because Carve has a
     * construct for it: `::: note` renders back as the same `<aside>`. This is
     * the boundary the fix must not cross - the fence goes only where it was
     * never a spelling.
     */
    public function testAnAdmonitionAsideStillBuildsItsConstruct(): void
    {
        $html = '<aside class="admonition note"><p>b</p></aside>';

        $this->assertSame("::: note\nb\n:::\n", $this->carve($html));
        $this->assertSame([], $this->rows($html));
        $this->assertStringContainsString(
            '<aside class="admonition note"',
            (new CarveConverter())->convert($this->carve($html)),
        );
    }

    /**
     * `::: details` is a real construct too, and the one name that still reaches
     * the fence builder in this handler.
     */
    public function testADisclosureStillBuildsItsFence(): void
    {
        $html = '<details class="faq" id="q1"><summary>Question?</summary><p>Answer.</p></details>';

        $this->assertSame("{#q1 .faq}\n::: details \"Question?\"\nAnswer.\n:::\n", $this->carve($html));
    }

    /**
     * A `<details>` with no summary it can quote falls back to the generic
     * container, and there the fence IS its spelling - `::: details` is a
     * construct where `::: article` is not. It must keep building one.
     */
    public function testADisclosureWithNoQuotableSummaryStillBuildsItsFence(): void
    {
        $html = '<details id="d"><p>Answer.</p></details>';

        $this->assertSame("{#d}\n::: details\nAnswer.\n:::\n", $this->carve($html));
    }

    /**
     * The names that map to NOTHING keep the answer markup-carve/carve-php#1713
     * gave them: `roundtrip` preserves them byte for byte. This fix is about the
     * names that used to build a fence, not about the preserve set.
     */
    public function testAnUnmappedContainerIsStillPreservedInRoundtrip(): void
    {
        $html = '<form id="f"><p>a</p></form>';

        $this->assertSame("```=html\n<form id=\"f\"><p>a</p></form>\n```\n", $this->carve($html));
        $this->assertSame(['raw-preserved'], array_column($this->rows($html), 'code'));
    }

    /**
     * Outside `roundtrip` an unmapped container has no preserved block to fall
     * back on, so it unwraps - and it used to write a `::: address` fence there,
     * which put a `class="address"` in the output for the same reason a
     * `::: article` did. carve-js and carve-rs both unwrap and declare here.
     */
    public function testAnUnmappedContainerOutsideRoundtripUnwrapsAndDeclaresIt(): void
    {
        $html = '<address disabled>bodytext</address>';

        $this->assertSame("bodytext\n", $this->carve($html, 'safe'));
        $this->assertSame(
            ['element-unwrapped', 'attribute-dropped'],
            array_column($this->rows($html, 'safe'), 'code'),
        );
        $this->assertStringNotContainsString(
            'class="address"',
            (new CarveConverter())->convert($this->carve($html, 'safe')),
        );
    }

    /**
     * A `<section>` is left exactly as it was: carve-rs#1381 and carve-rs#1355
     * settled that an authored id survives onto the heading, so the element
     * comes back, and a DERIVED id stays dropped because re-emitting it would
     * change the render. Neither is this ticket's to move.
     */
    public function testTheSectionWrapperKeepsItsAuthoredId(): void
    {
        $carve = $this->carve('<section id="zz"><h1>A</h1><p>b</p></section>');

        $this->assertSame("{#zz}\n# A\n\nb\n", $carve);
        $this->assertStringContainsString('<section id="zz">', (new CarveConverter())->convert($carve));
    }

    /**
     * A sectioning wrapper INSIDE another one unwraps as well, and the section
     * below it still keeps its id - the outer element is what goes.
     *
     * The section reports its OWN unwrap too, at its own path (carve-php#1737):
     * its id comes back on the heading, so the attribute row is correctly
     * silent, and the element is gone all the same.
     */
    public function testANestedSectionKeepsItsIdWhileTheArticleAroundItGoes(): void
    {
        $html = '<article id="k"><section id="s"><h2>T</h2><p>a</p></section></article>';

        $this->assertSame("{#s}\n## T\n\na\n", $this->carve($html));
        $this->assertSame(
            ['element-unwrapped', 'attribute-dropped', 'element-unwrapped'],
            array_column($this->rows($html), 'code'),
        );
        $this->assertSame(
            ['/article[1]', '/article[1]', '/article[1]/section[1]'],
            array_column($this->rows($html), 'path'),
        );
    }
}
