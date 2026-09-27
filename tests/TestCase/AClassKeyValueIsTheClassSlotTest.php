<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * `CARVE-P4-007`: a `class` key-value is a SPELLING OF THE CLASS SLOT. The value
 * joins `attrs.classes` in source order and nothing reaches `attrs.keyValues`, so
 * `{class=foo}` and `{.foo}` parse to one tree.
 *
 * Read as an ordinary key it OVERWROTE the class slot, so `{.b class=a}` lost `b`
 * and `{class=a class=b}` lost `a` (markup-carve/carve#2438), and an engine
 * keeping the two apart renders two `class` attributes on one element, which no
 * HTML parser reads as the author wrote it (markup-carve/carve#2439).
 *
 * The two spellings are not interchangeable in SOURCE: `.` reads the
 * `explicit_identifier` a fence word does, while a value reaches past it, so
 * `-col` and `w-1/2` are classes only the key-value form can spell
 * (markup-carve/carve#2435).
 */
class AClassKeyValueIsTheClassSlotTest extends TestCase
{
    /**
     * Every spelling that reaches the class slot, on an attribute line.
     *
     * @var list<string>
     */
    private const SPELLINGS = [
        '{class=a .b}',
        '{.b class=a}',
        '{class=-col}',
        '{class=a class=b}',
        '{class}',
        '{class=""}',
        '{.a .b}',
        '{#i class=-col .b k=v}',
        '{class="w-1/2" .grid}',
    ];

    public function testAClassKeyValueLandsInTheClassSlot(): void
    {
        $node = (new CarveConverter())->parse("{class=a .b}\nHello\n")->getChildren()[0];

        self::assertSame(['a', 'b'], $node->getClassList());
        self::assertSame(['.class'], $node->getAttributeOrder());
        self::assertSame(['class' => 'a b'], $node->getAttributes());
    }

    public function testTheClassSlotKeepsSourceOrderAcrossBothSpellings(): void
    {
        $cases = [
            '{class=a .b}' => ['a', 'b'],
            '{.b class=a}' => ['b', 'a'],
            // A second key-value appends rather than overwriting.
            '{class=a class=b}' => ['a', 'b'],
            '{class="w-1/2" .grid}' => ['w-1/2', 'grid'],
            // Each authored value retains its internal whitespace.
            '{class="a  b" .c}' => ['a  b', 'c'],
        ];
        foreach ($cases as $attrLine => $classes) {
            $node = (new CarveConverter())->parse($attrLine . "\nHello\n")->getChildren()[0];
            self::assertSame($classes, $node->getClassList(), $attrLine);
            self::assertSame(['.class'], $node->getAttributeOrder(), $attrLine);
        }
    }

    public function testABareClassFoldsLikeAnEmptyValue(): void
    {
        $bare = (new CarveConverter())->parse("{class}\nHello\n")->getChildren()[0];
        $empty = (new CarveConverter())->parse("{class=\"\"}\nHello\n")->getChildren()[0];

        self::assertSame($empty->getAttributes(), $bare->getAttributes());
        self::assertSame($empty->getAttributeOrder(), $bare->getAttributeOrder());
        self::assertSame(['class' => ''], $bare->getAttributes());
        // And an empty value never erases a class the same block already gave.
        $kept = (new CarveConverter())->parse("{.b class=\"\"}\nHello\n")->getChildren()[0];
        self::assertSame(['b', ''], $kept->getClassList());
    }

    public function testBareDivPreservesTheAuthoredClassSlot(): void
    {
        $cases = [
            '{class}' => '',
            '{class=""}' => '',
            '{class .b}' => 'b',
            '{.b}' => 'b',
            '{class="a b" .c}' => 'a b c',
        ];
        foreach ($cases as $attrLine => $classes) {
            self::assertSame(
                '<div class="' . $classes . "\">\n  <p>y</p>\n</div>\n",
                (new CarveConverter())->convert($attrLine . "\n:::\ny\n:::\n"),
                $attrLine,
            );
        }
    }

    public function testNoShapeRendersTwoClassAttributes(): void
    {
        foreach (self::SPELLINGS as $attrLine) {
            foreach ([$attrLine . "\nHello\n", $attrLine . "\n::: note\nbody\n:::\n"] as $source) {
                $html = (new CarveConverter())->convert($source);
                self::assertSame(1, substr_count($html, 'class='), $source . ' rendered ' . $html);
            }
            $html = (new CarveConverter())->convert($attrLine . "\n::: \nbody\n:::\n");
            self::assertSame(1, substr_count($html, 'class='), $attrLine . ' rendered ' . $html);
        }
        // A span takes the same slot, so the inline path answers alike.
        self::assertSame(1, substr_count((new CarveConverter())->convert("[t]{class=a .b}\n"), 'class='));
        self::assertSame(
            "<p><span class=\"a b\">t</span></p>\n",
            (new CarveConverter())->convert("[t]{class=a .b}\n"),
        );
    }

    public function testTheKeyValueNeverReachesTheKeyValuesMap(): void
    {
        $codec = new AstCodec();
        foreach (self::SPELLINGS as $attrLine) {
            $wire = json_decode(json_encode($codec->encode((new CarveConverter())->parse($attrLine . "\nHello\n"))), true);
            $attrs = $wire['children'][0]['attrs'] ?? [];
            self::assertArrayNotHasKey('class', $attrs['keyValues'] ?? [], $attrLine);
            self::assertContains('.class', $attrs['order'] ?? [], $attrLine);
            self::assertArrayHasKey('classes', $attrs, $attrLine);
        }
        // An empty class is ONE empty class rather than none: the `.class` slot the
        // order names has to have something to rebuild, or decoding reports a lost
        // field and refuses the payload.
        $wire = json_decode(json_encode($codec->encode((new CarveConverter())->parse("{class}\nHello\n"))), true);
        self::assertSame([''], $wire['children'][0]['attrs']['classes']);
        self::assertSame(
            "<p class=\"\">Hello</p>\n",
            (new CarveConverter())->render($codec->decode($wire)),
        );
    }

    public function testAClassTheShorthandCannotSpellIsWrittenAsAQuotedKeyValue(): void
    {
        // Written `.` these are source this engine's own parser reads as a
        // paragraph, so the fold would otherwise have the writer emit invalid
        // Carve. Quoted, because `unquoted_value` cannot hold `w-1/2` either.
        $cases = [
            '{class=-col}' => '{class="-col"}',
            '{class="w-1/2" .grid}' => '{class="w-1/2" .grid}',
            '{class}' => '{class=""}',
            '{class=a .b}' => '{.a .b}',
            '{#i class=-col .b k=v}' => '{#i class="-col" .b k=v}',
        ];
        foreach ($cases as $attrLine => $expected) {
            $written = (new CarveRenderer())->render((new CarveConverter())->parse($attrLine . "\nHello\n"));
            self::assertSame($expected, explode("\n", $written)[0], $attrLine);
        }
    }

    public function testEverySpellingRoundTripsAndIsAFormatterFixedPoint(): void
    {
        $surfaces = [
            "%s\nHello\n",
            "%s\n::: \nbody\n:::\n",
            "%s\n::: note\nbody\n:::\n",
            "%s\n# Title\n",
            "- %s item\n",
            "| a |%s\n",
            "[t]%s\n",
            "*x*%s\n",
        ];
        foreach (self::SPELLINGS as $attrLine) {
            foreach ($surfaces as $surface) {
                $source = sprintf($surface, $attrLine);
                $html = (new CarveConverter())->convert($source);
                $written = (new CarveRenderer())->render((new CarveConverter())->parse($source));
                // What the writer emits has to render the same document, which it
                // cannot do if this engine's own parser refuses the bytes.
                self::assertSame($html, (new CarveConverter())->convert($written), $source . ' -> ' . $written);
                // And idempotent, so a second pass is not a third document.
                self::assertSame(
                    $written,
                    (new CarveRenderer())->render((new CarveConverter())->parse($written)),
                    $source,
                );
            }
        }
    }
}
