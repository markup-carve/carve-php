<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attribute attaches to the whole non-ASCII-whitespace run, escaped characters
 * included: the boundary is whitespace, not punctuation and not an escape. djot.js
 * splits the run at an escape against the definition in its own test/attributes.test,
 * so it is not the authority for these shapes (markup-carve/carve#2848).
 */
class ADjotAttributeWordKeepsEveryEscapedCharacterTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function words(): array
    {
        return [
            'an escaped brace pair already held the word' => ["\\{a\\_b}{.c}\n", "[\\{a\\_b}]{.c}\n", '<p><span class="c">{a_b}</span></p>'],
            'an escaped star already held the word' => ["foo\\*bar{.c}\n", "[foo\\*bar]{.c}\n", '<p><span class="c">foo*bar</span></p>'],
            'an escaped open brace before the word' => ["a\\{b{.c}\n", "[a\\{b]{.c}\n", '<p><span class="c">a{b</span></p>'],
            'an escaped close brace before the word' => ["a\\}b{.c}\n", "[a\\}b]{.c}\n", '<p><span class="c">a}b</span></p>'],
            'several escapes across the run' => ["\\{a\\_b\\*c\\}d{.c}\n", "[\\{a\\_b\\*c\\}d]{.c}\n", '<p><span class="c">{a_b*c}d</span></p>'],
            'the run after a space keeps its escapes' => ["x\\_y \\{z\\}{.c}\n", "x\\_y [\\{z\\}]{.c}\n", '<p>x_y <span class="c">{z}</span></p>'],
            'an escaped open brace ends the word' => ["a\\{{.c}\n", "[a\\{]{.c}\n", '<p><span class="c">a{</span></p>'],
            'an escaped close brace ends the word' => ["a\\}{.c}\n", "[a\\}]{.c}\n", '<p><span class="c">a}</span></p>'],
            'an escaped brace pair inside the word' => ["a\\{b\\}c{.c}\n", "[a\\{b\\}c]{.c}\n", '<p><span class="c">a{b}c</span></p>'],
            'an escaped brace and an escaped underscore' => ["a\\{b\\_c{.c}\n", "[a\\{b\\_c]{.c}\n", '<p><span class="c">a{b_c</span></p>'],
            'a word of nothing but escaped braces' => ["\\{\\}{.c}\n", "[\\{\\}]{.c}\n", '<p><span class="c">{}</span></p>'],
            'whitespace bounds the escaped run on the left' => ["a \\{b{.c}\n", "a [\\{b]{.c}\n", '<p>a <span class="c">{b</span></p>'],
            'an escaped close brace after a space already held the word' => ["a \\}b{.c}\n", "a [\\}b]{.c}\n", '<p>a <span class="c">}b</span></p>'],
            'an escaped bracket inside the word' => ["a\\]b{.c}\n", "[a\\]b]{.c}\n", '<p><span class="c">a]b</span></p>'],
            'an escaped quote inside the word' => ["a\\\"b{.c}\n", "[a\\\"b]{.c}\n", '<p><span class="c">a"b</span></p>'],
            'an escaped backtick inside the word' => ["a\\`b{.c}\n", "[a\\`b]{.c}\n", '<p><span class="c">a`b</span></p>'],
        ];
    }

    #[DataProvider('words')]
    public function testTheWordKeepsItsEscapes(string $djot, string $carve, string $html): void
    {
        $written = (new DjotToCarve())->convert($djot);
        $this->assertSame($carve, $written);
        $this->assertSame($html, rtrim((new CarveConverter())->convert($written), "\n"));
    }

    /**
     * The span has to survive a re-read, or it only looked like it covered the run.
     */
    #[DataProvider('words')]
    public function testTheWrittenSpanReadsBackUnchanged(string $djot, string $carve, string $html): void
    {
        $written = (new DjotToCarve())->convert($djot);
        $converter = new CarveConverter();
        $this->assertSame($carve, (new CarveRenderer())->render($converter->parse($written)));
        $this->assertSame($html, rtrim($converter->convert($written), "\n"));
    }

    public function testEscapedLiteralWhitespaceStaysAWordBoundary(): void
    {
        $written = (new DjotToCarve())->convert("a\\ b{.c}\n");

        $this->assertSame("a\\ [b]{.c}\n", $written);
        $this->assertSame('<p>a&nbsp;<span class="c">b</span></p>', rtrim((new CarveConverter())->convert($written), "\n"));
    }

    public function testAnUnescapedBracePairStaysInsideTheWord(): void
    {
        $converter = new CarveConverter();

        $this->assertSame("[w\\{x}]{.c}\n", (new DjotToCarve())->convert("w{x}{.c}\n"));
        $this->assertSame('<p><span class="c">w{x}</span></p>', rtrim($converter->convert((new DjotToCarve())->convert("w{x}{.c}\n")), "\n"));
        $this->assertSame('<p><span class="c">word</span></p>', rtrim($converter->convert((new DjotToCarve())->convert("word{.c}\n")), "\n"));
    }
}
