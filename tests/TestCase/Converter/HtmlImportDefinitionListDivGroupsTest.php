<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HTML5 lets a `dl` group its terms and definitions two ways: as direct
 * children, or with one `div` per group wrapping them. Only the first was read,
 * so the second converted to an empty document - every term and every
 * definition gone, and no diagnostic saying so.
 *
 * The assertions go through the parser rather than stopping at the emitted
 * source: what the row promises is a definition list on the other side, and a
 * `::` line that does not re-parse into one would satisfy a source assertion
 * while failing the promise.
 */
class HtmlImportDefinitionListDivGroupsTest extends TestCase
{
    protected HtmlToCarve $converter;

    protected CarveConverter $carve;

    protected function setUp(): void
    {
        $this->converter = new HtmlToCarve();
        $this->carve = new CarveConverter();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function divGroupedListProvider(): array
    {
        return [
            'one group' => [
                '<dl><div><dt>Term</dt><dd>Definition</dd></div></dl>',
                "<dl>\n  <dt>Term</dt>\n  <dd>Definition</dd>\n</dl>\n",
            ],
            'several groups' => [
                '<dl><div><dt>One</dt><dd>First</dd></div><div><dt>Two</dt><dd>Second</dd></div></dl>',
                "<dl>\n  <dt>One</dt>\n  <dd>First</dd>\n  <dt>Two</dt>\n  <dd>Second</dd>\n</dl>\n",
            ],
            'wrapped and unwrapped groups mixed' => [
                '<dl><dt>Plain</dt><dd>Direct</dd><div><dt>Wrapped</dt><dd>Grouped</dd></div></dl>',
                "<dl>\n  <dt>Plain</dt>\n  <dd>Direct</dd>\n  <dt>Wrapped</dt>\n  <dd>Grouped</dd>\n</dl>\n",
            ],
            'several terms in one group' => [
                '<dl><div><dt>color</dt><dt>colour</dt><dd>The visual property.</dd></div></dl>',
                "<dl>\n  <dt>color</dt>\n  <dt>colour</dt>\n  <dd>The visual property.</dd>\n</dl>\n",
            ],
            'several definitions in one group' => [
                '<dl><div><dt>color</dt><dd>The visual property.</dd><dd>Used in design.</dd></div></dl>',
                "<dl>\n  <dt>color</dt>\n  <dd>The visual property.</dd>\n  <dd>Used in design.</dd>\n</dl>\n",
            ],
            'wrapper carries a styling class' => [
                '<dl><div class="row"><dt>Term</dt><dd>Definition</dd></div></dl>',
                "<dl>\n  <dt>Term</dt>\n  <dd>Definition</dd>\n</dl>\n",
            ],
            'wrapper indented across lines' => [
                "<dl>\n  <div>\n    <dt>Term</dt>\n    <dd>Definition</dd>\n  </div>\n</dl>",
                "<dl>\n  <dt>Term</dt>\n  <dd>Definition</dd>\n</dl>\n",
            ],
            'definition holding two paragraphs' => [
                '<dl><div><dt>Term</dt><dd><p>One</p><p>Two</p></dd></div></dl>',
                "<dl>\n  <dt>Term</dt>\n  <dd>\n    <p>One</p>\n    <p>Two</p>\n  </dd>\n</dl>\n",
            ],
            'definition holding a nested list' => [
                '<dl><div><dt>Term</dt><dd><dl><dt>Inner</dt><dd>Nested</dd></dl></dd></div></dl>',
                "<dl>\n  <dt>Term</dt>\n  <dd>\n    <dl>\n      <dt>Inner</dt>\n      <dd>Nested</dd>\n    </dl>\n  </dd>\n</dl>\n",
            ],
        ];
    }

    #[DataProvider('divGroupedListProvider')]
    public function testDivGroupedDefinitionListSurvivesImport(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->carve->convert($this->converter->convert($html)));
    }

    /**
     * The list-level attribute block sits before the terms, so a wrapped group
     * must not displace it.
     */
    public function testListAttributesSurviveAlongsideAWrappedGroup(): void
    {
        $carve = $this->converter->convert('<dl id="glossary"><div><dt>Term</dt><dd>Definition</dd></div></dl>');

        $this->assertSame(
            "<dl id=\"glossary\">\n  <dt>Term</dt>\n  <dd>Definition</dd>\n</dl>\n",
            $this->carve->convert($carve),
        );
    }

    /**
     * CONTROL. The direct-child form is the one that already worked; it goes
     * through the same parse so a change to the emitted source shows up here
     * too, rather than only in a string match that a broken `::` line passes.
     */
    public function testDirectChildDefinitionListStillConverts(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>Term</dt>\n  <dd>Definition</dd>\n</dl>\n",
            $this->carve->convert($this->converter->convert('<dl><dt>Term</dt><dd>Definition</dd></dl>')),
        );
    }

    /**
     * CONTROL. HTML5 allows one level of wrapper and no more, so a `div` inside
     * the wrapper is not a group. Its terms stay unread rather than the
     * converter inventing a flattening the source did not say.
     */
    public function testDoublyWrappedGroupIsNotFlattened(): void
    {
        $carve = $this->converter->convert('<dl><div><div><dt>Term</dt><dd>Definition</dd></div></div></dl>');

        $this->assertSame('', trim($carve));
    }

    /**
     * CONTROL. A `div` elsewhere in the document is still a div, not a
     * definition-list wrapper - the unwrapping is scoped to `dl` children.
     */
    public function testDivOutsideADefinitionListIsUnaffected(): void
    {
        $this->assertSame(
            "<p>Loose</p>\n",
            $this->carve->convert($this->converter->convert('<div><p>Loose</p></div>')),
        );
    }
}
