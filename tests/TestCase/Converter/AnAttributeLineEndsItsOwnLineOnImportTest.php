<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block-attribute line the importer writes ENDS ITS OWN LINE
 * (markup-carve/carve-php#1653).
 *
 * `formatBlockAttributes()` returns `"{...}\n"` and its docblock says so. Seven
 * of its callers use that directly; three added a second newline, so the
 * importer wrote a BLANK LINE between the attribute and the block it attaches
 * to - on a list, a table and a definition list.
 *
 * WHAT MAKES THIS TEST ABLE TO FAIL. The attribute still ATTACHES across the
 * blank line: all three shapes read back with the id on the right block, before
 * the fix and after it. So a test asking "did the attribute survive" passes on
 * the defect and proves nothing - which is the vacuous shape this suite has
 * already been caught writing once tonight.
 *
 * The property that separates them is FIXED-POINT-NESS: the importer's output
 * has to be what the canonical writer would write, and the canonical writer
 * emits no blank line there. Measured on `main` before the fix, five of the six
 * shapes below were not fixed points; after it, none. That is the assertion
 * that discriminates, so it is the one the exact-string cases are checked
 * against as well.
 *
 * THE EMPTY CASE IS A DIFFERENT ROLE and is deliberately unchanged. A top-level
 * list or table with no attribute line opens with a newline of its own; the two
 * roles were conflated in one statement, which is why removing the blank line
 * naively would have moved output for every unattributed list too.
 */
class AnAttributeLineEndsItsOwnLineOnImportTest extends TestCase
{
    private function import(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function attributedBlockProvider(): array
    {
        return [
            // The three callers that added the second newline.
            'a list with an id' => [
                '<ul id="x"><li>a</li></ul>',
                "{#x}\n- a\n",
            ],
            'a table with an id' => [
                '<table id="x"><tr><td>a</td></tr></table>',
                "{#x}\n| a |\n",
            ],
            'a definition list with an id' => [
                '<dl id="x"><dt>t</dt><dd>d</dd></dl>',
                "{#x}\n:: t\n: d\n",
            ],
            // The key from carve-php#1648 rides the same line, so it has to
            // come out on one line too rather than gaining the blank back.
            'a loose one-item list with an id' => [
                '<ul id="x"><li><p>a</p></li></ul>',
                "{loose #x}\n- a\n",
            ],
            // With a block in front of it, the separator comes from THAT block,
            // which is what showed the newline was not the list's to add.
            'a list after a paragraph' => [
                '<p>x</p><ul id="y"><li>a</li></ul>',
                "x\n\n{#y}\n- a\n",
            ],
            // A caller that was already right, as a control: if a sweep ever
            // "fixes" this one it will take a line away that was never there.
            'a block quote with an id' => [
                '<blockquote id="x"><p>a</p></blockquote>',
                "{#x}\n> a\n",
            ],
        ];
    }

    #[DataProvider('attributedBlockProvider')]
    public function testTheAttributeLineIsFollowedByItsBlock(string $html, string $expected): void
    {
        // Verified against carve-js `main` while writing this: it writes each of
        // these with no blank line, so these are the shared answer rather than
        // this engine's preference.
        $this->assertSame($expected, $this->import($html));
    }

    /**
     * THE DISCRIMINATING PROPERTY. The importer's source must be a fixed point
     * of the canonical writer - five of these six were not before the fix.
     *
     * @param string $html
     * @param string $expected the provider's source, asserted above
     */
    #[DataProvider('attributedBlockProvider')]
    public function testTheImportedSourceIsWhatTheCanonicalWriterWrites(string $html, string $expected): void
    {
        $written = $this->import($html);
        $formatter = CarveConverter::carve();

        $this->assertSame($written, $formatter->render($formatter->parse($written)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unattributedBlockProvider(): array
    {
        return [
            'a list with no attributes' => ['<ul><li>a</li></ul>', "- a\n"],
            'a table with no attributes' => ['<table><tr><td>a</td></tr></table>', "| a |\n"],
            'a definition list with no attributes' => ['<dl><dt>t</dt><dd>d</dd></dl>', ":: t\n: d\n"],
            'a loose one-item list with no attributes' => ['<ul><li><p>a</p></li></ul>', "{loose}\n- a\n"],
        ];
    }

    /**
     * THE HALF THAT MUST NOT MOVE. The empty-attribute path carries the block's
     * own opening newline, not the blank line this removes.
     */
    #[DataProvider('unattributedBlockProvider')]
    public function testAnUnattributedBlockIsUnchanged(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->import($html));
    }

    /**
     * The attribute attaches either way, which is exactly why the assertions
     * above are written on the source rather than on the round trip. Pinned so
     * the reason stays visible: this is the check that CANNOT tell the defect
     * from the fix.
     */
    public function testTheAttributeAttachesEitherWay(): void
    {
        $converter = CarveConverter::create();

        foreach (["{#x}\n\n- a\n", "{#x}\n- a\n"] as $source) {
            $this->assertStringContainsString('<ul id="x">', $converter->convert($source), $source);
        }
    }
}
