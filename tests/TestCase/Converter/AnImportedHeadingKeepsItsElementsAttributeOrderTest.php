<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A HEADING'S SLOT ORDER IS THE ELEMENT'S OWN ORDER, not a fixed one.
 *
 * The importer spelled `#id`, then `.class`, then the key-values, whatever
 * order the element had written them in. So `<h1 class="k" id="x">` came back
 * as `{#x .k}` and re-rendered as `<h1 id="x" class="k">` - attributes the
 * input never had in that order. carve-rs ruled it in carve-rs#1354 and
 * carve-js ported it; this is the carve-php port (carve-php#1699).
 *
 * The order is OBSERVABLE because `HtmlRenderer` reads `#id` out of the node's
 * attribute order to decide whether a heading id was authored: `{#x .k}` and
 * `{.k #x}` render different bytes.
 *
 * A NON-EMPTY ORDER IS EXHAUSTIVE, so a slot the element did not spell under
 * its own name still has to appear or the writer drops it silently.
 *
 * THE EXPECTATIONS ARE carve-js's AND carve-rs's ANSWERS for the same input,
 * so a shape the three disagree on fails here rather than recording whatever
 * this engine happens to do.
 */
class AnImportedHeadingKeepsItsElementsAttributeOrderTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function orderProvider(): array
    {
        return [
            'class before id where the element did' => ['<h1 class="k" id="x">h</h1>', "{.k #x}\n# h\n"],
            'id first where the element did' => ['<h1 id="x" class="k">h</h1>', "{#x .k}\n# h\n"],
            'a key-value against the id' => ['<h1 data-a="1" id="x">h</h1>', "{data-a=1 #x}\n# h\n"],
            'all three by the element' => ['<h1 class="k" data-a="1" id="x">h</h1>', "{.k data-a=1 #x}\n# h\n"],
            'a boolean key-value keeps its place' => ['<h1 hidden id="x">h</h1>', "{hidden #x}\n# h\n"],
            // A NON-EMPTY ORDER IS EXHAUSTIVE: `style` is folded away on the
            // way in and names no slot, so the id it sat in front of still has
            // to be written.
            'a slot the element did not name' => ['<h1 style="color:red" id="x">h</h1>', "{#x}\n# h\n"],
        ];
    }

    /**
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('orderProvider')]
    public function testTheHeadingWritesTheElementsOrder(string $html, string $expected): void
    {
        $this->assertSame($expected, (new HtmlToCarve())->convert($html));
    }

    /**
     * The two orders are two documents, and each comes back as itself.
     *
     * @return array<string, array{0: string}>
     */
    public static function fixedPointProvider(): array
    {
        return [
            'class then id' => ["- a\n\n  {.k #Other}\n  # H\n"],
            'id then class' => ["- a\n\n  {#Other .k}\n  # H\n"],
            'key-value then id' => ["- a\n\n  {data-a=1 #Other}\n  # H\n"],
        ];
    }

    /**
     * @param string $source
     */
    #[DataProvider('fixedPointProvider')]
    public function testTheImportedSourceRendersTheHtmlItCameFrom(string $source): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        $back = (new HtmlToCarve(importMode: 'roundtrip'))->convert($html);

        $this->assertSame($html, $converter->convert($back));
    }

    /**
     * THE SCOPE. Only the heading takes its order from the element, because
     * only the heading has a writer that can be handed an id and a class in
     * either order and has to write them back in it. Every other element keeps
     * the canonical `#id .class key=value` order, which is what carve-js and
     * carve-rs also do - they set the slot list on the heading arm alone.
     */
    public function testAnotherElementKeepsTheCanonicalOrder(): void
    {
        $this->assertSame("{#x .k}\nh\n", (new HtmlToCarve())->convert('<p class="k" id="x">h</p>'));
    }

    /**
     * ONE `#id` SLOT, AND THE CALLER'S SKIP DECIDES WHOSE.
     *
     * processSection() writes the section wrapper's id and asks the heading's
     * writer to leave the heading's own id out. The writer read `id` off the
     * node before it looked at the skip list, so the skip was inert and a
     * `<section id="S">` around an `<h1 id="X">` wrote `{#S #X .k}` - two id
     * slots in one block, of which the parser takes the LAST, silently
     * inverting the priority the call site had spelled and rendering
     * `<section id="X">`.
     *
     * Now the skip is honored and the section id is the one that survives,
     * which is what the call site asked for. This is the same value-versus-
     * presence confusion carve-php#1698 fixed one line above, seen from the
     * skip side instead of the empty side.
     */
    public function testASectionIdIsNotOverriddenByTheHeadingsOwn(): void
    {
        $this->assertSame(
            "{#S .k}\n# H\n",
            (new HtmlToCarve())->convert('<section id="S"><h1 id="X" class="k">H</h1></section>'),
        );
    }
}
