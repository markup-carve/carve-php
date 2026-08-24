<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An explicit empty id is not an absent one. It wins verbatim, SUPPRESSES the
 * auto slug, and this engine's own renderer writes `<h1 id="">` back for it -
 * so an import that drops one turns a heading the author kept out of every
 * anchor into a heading that has one (carve-php#1698).
 *
 * THE LOSS WAS IN A VALUE TEST. `getElementAttributes()` asked
 * `getAttribute('id') !== ''`, and `getAttribute()` answers `''` for an absent
 * id and for an explicit empty one alike, so the writer could not tell the two
 * apart and neither could the names policy beside it. That is why the id went
 * missing BESIDE A CLASS too - the shape where carve-js's own defect, a
 * truthiness test on the whole attribute set, did not reach.
 *
 * An empty id has no `#` spelling, so it rides the key-value slot as `id=""` -
 * the form carve-js and carve-rs write, and the one this engine's parser reads
 * back into an explicit empty id.
 */
class AnExplicitlyEmptyIdIsNotAnAbsentOneTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keptProvider(): array
    {
        return [
            'the only attribute' => [
                '<ul><li>a<h1 id="">H</h1></li></ul>',
                "- a\n\n  {id=\"\"}\n  # H\n",
            ],
            // THE SHAPE THAT PROVED THIS IS NOT carve-js's CAUSE. carve-js kept
            // the empty id here before its own fix, because one more attribute
            // made the set truthy. carve-php lost it in both shapes, which put
            // the cause where the VALUE is tested rather than where the set is.
            'beside a class' => [
                '<ul><li>a<h1 id="" class="k">H</h1></li></ul>',
                "- a\n\n  {id=\"\" .k}\n  # H\n",
            ],
            'beside a key-value' => [
                '<ul><li>a<h1 id="" data-a="1">H</h1></li></ul>',
                "- a\n\n  {id=\"\" data-a=1}\n  # H\n",
            ],
            // NOT HEADING-SPECIFIC. getElementAttributes() serves every element
            // that writes an attribute block.
            'on a paragraph' => [
                '<p id="">x</p>',
                "{id=\"\"}\nx\n",
            ],
            'on a blockquote' => [
                '<blockquote id=""><p>x</p></blockquote>',
                "{id=\"\"}\n> x\n",
            ],
            // ONE RULE, ONE SPELLING. Five block writers open-code the `#id`
            // slot instead of going through getElementAttributes(), and each
            // carried its own copy of the same value test, so each dropped the
            // same empty id. They all ask idAttributePart() now.
            'on an admonition' => [
                '<div class="admonition note" id=""><p>x</p></div>',
                "{id=\"\" .note}\n::: admonition\nx\n:::\n",
            ],
            'on a line block' => [
                '<div class="line-block" id=""><span>a</span></div>',
                "{id=\"\"}\n::: |\na\n:::\n",
            ],
            'on a fenced container' => [
                '<div class="tabs" id=""><div class="tab"><p>x</p></div></div>',
                "{id=\"\"}\n::: tabs\n:::: tab\nx\n::::\n:::\n",
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('keptProvider')]
    public function testTheEmptyIdIsWrittenInEveryMode(string $html, string $expected): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            // Even in `roundtrip`: an empty id is not the default slug of
            // anything, so the generated-id carve-out never reaches it.
            $this->assertSame($expected, (new HtmlToCarve(importMode: $mode))->convert($html), 'mode ' . $mode);
        }
    }

    /**
     * THE LOSS, stated as what a reader sees. Before this the import wrote the
     * item and a bare `# H` with no attribute line at all, and rendering that
     * back produced `id="H"` - an anchor the input explicitly suppressed.
     */
    public function testItReRendersToTheHtmlItCameFromRatherThanToTheSlug(): void
    {
        $html = "<ul>\n  <li>a\n    <h1 id=\"\">H</h1>\n  </li>\n</ul>\n";
        $carve = (new HtmlToCarve())->convert($html);

        $this->assertSame($html, (new CarveConverter())->convert($carve));
    }

    /**
     * THE CONTROL. Widening the test from "non-empty" to "present" must not
     * start writing an attribute block for an element that carried nothing.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function untouchedProvider(): array
    {
        return [
            'heading with no attributes' => ['<ul><li>a<h1>H</h1></li></ul>', "- a\n\n  # H\n"],
            'paragraph with no attributes' => ['<p>x</p>', "x\n"],
            'class but no id' => ['<ul><li>a<h1 class="k">H</h1></li></ul>', "- a\n\n  {.k}\n  # H\n"],
        ];
    }

    /**
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('untouchedProvider')]
    public function testAnElementThatCarriedNoIdStillWritesNone(string $html, string $expected): void
    {
        $this->assertSame($expected, (new HtmlToCarve())->convert($html));
    }
}
