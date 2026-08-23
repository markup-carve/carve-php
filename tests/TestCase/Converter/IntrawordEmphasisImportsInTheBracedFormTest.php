<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bare delimiter neither opens nor closes next to a word character, which is
 * the entire reason the braced form exists. So an element sitting INSIDE a word
 * has exactly one spelling: `Sy{*rup*}-free`. Written bare, `Sy*rup*-free`
 * renders as `<p>Sy*rup*-free</p>` - the emphasis is gone and two asterisks the
 * author never typed are now visible prose, which is content loss rather than a
 * formatting difference (markup-carve/carve-php#1602).
 *
 * ALL FIVE single-character kinds are covered, because the defect was one
 * missing call repeated per dispatch arm and a test for one arm proves nothing
 * about the next. `=` was the only arm that already asked; `*`, `/`, `_` and
 * `~` did not.
 *
 * THE RENDER-BACK ASSERTION IS THE LOAD-BEARING ONE. Pinning the spelling alone
 * would let a future writer swap `{*x*}` for some other braced form and stay
 * green while the document broke; asserting that the imported source renders
 * back to the HTML that produced it is the property that actually matters, and
 * it is the one that was false.
 */
class IntrawordEmphasisImportsInTheBracedFormTest extends TestCase
{
    /**
     * The five kinds whose delimiter is a single character, so the bare form is
     * word-boundary gated and the braced form is the intraword spelling.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function intrawordProvider(): array
    {
        return [
            'strong' => ['<p>x<strong>bo</strong>y</p>', 'x{*bo*}y', '<p>x<strong>bo</strong>y</p>'],
            'b' => ['<p>x<b>bo</b>y</p>', 'x{*bo*}y', '<p>x<strong>bo</strong>y</p>'],
            'em' => ['<p>x<em>it</em>y</p>', 'x{/it/}y', '<p>x<em>it</em>y</p>'],
            'i' => ['<p>x<i>it</i>y</p>', 'x{/it/}y', '<p>x<em>it</em>y</p>'],
            'u' => ['<p>x<u>un</u>y</p>', 'x{_un_}y', '<p>x<u>un</u>y</p>'],
            's' => ['<p>x<s>st</s>y</p>', 'x{~st~}y', '<p>x<s>st</s>y</p>'],
            'strike' => ['<p>x<strike>st</strike>y</p>', 'x{~st~}y', '<p>x<s>st</s>y</p>'],
            'mark' => ['<p>x<mark>hi</mark>y</p>', 'x{=hi=}y', '<p>x<mark>hi</mark>y</p>'],
            // The report's own case, and the shape a word processor produces
            // most: a hyphenated compound with one part emphasized.
            'the reported compound' => [
                '<p>Sy<strong>rup</strong>-free</p>',
                'Sy{*rup*}-free',
                '<p>Sy<strong>rup</strong>-free</p>',
            ],
            // ONE side is enough. A word character on either end blocks the
            // delimiter that touches it, and a span with one bare end is not a
            // span at all.
            'a word character on the left only' => [
                '<p>x<strong>bo</strong> y</p>',
                'x{*bo*} y',
                '<p>x<strong>bo</strong> y</p>',
            ],
            'a word character on the right only' => [
                '<p>x <strong>bo</strong>y</p>',
                'x {*bo*}y',
                '<p>x <strong>bo</strong>y</p>',
            ],
            // Digits and the underscore are word characters too - `H<sub>2</sub>O`
            // is the canonical example and it has always been braced, but the
            // strong/em/u/strike arms had no digit coverage at all.
            'digits' => ['<p>1<strong>2</strong>3</p>', '1{*2*}3', '<p>1<strong>2</strong>3</p>'],
            'an underscore' => [
                '<p>a_<strong>b</strong>_c</p>',
                'a_{*b*}_c',
                '<p>a_<strong>b</strong>_c</p>',
            ],
            // A neighbour that flattens to text carries its own last character
            // across, so the boundary is the text edge and not the element.
            'after a flattened span' => [
                '<p><span>ab</span><strong>c</strong> d</p>',
                'ab{*c*} d',
                '<p>ab<strong>c</strong> d</p>',
            ],
            // A verbatim span's CONTENT is what sits next to the delimiter, the
            // way the canonical writer measures it.
            'after a code span' => [
                '<p><code>ab</code><strong>c</strong> d</p>',
                '`ab`{*c*} d',
                '<p><code>ab</code><strong>c</strong> d</p>',
            ],
            // AN ELEMENT THAT RENDERS NOTHING IS NOT A NEIGHBOUR. Editors emit
            // empty wrappers constantly, and stopping the search at one reads
            // the word character before it as absent - which is the bare form
            // and the content loss all over again, just one element further
            // out.
            'after an empty span' => [
                '<p>a<span></span><strong>b</strong> c</p>',
                'a{*b*} c',
                '<p>a<strong>b</strong> c</p>',
            ],
            'after an empty emphasis' => [
                '<p>a<strong></strong><strong>b</strong> c</p>',
                'a{*b*} c',
                '<p>a<strong>b</strong> c</p>',
            ],
            'after nested empty wrappers' => [
                '<p>a<span><em></em></span><strong>b</strong> c</p>',
                'a{*b*} c',
                '<p>a<strong>b</strong> c</p>',
            ],
            'after a comment' => [
                '<p>a<!-- note --><strong>b</strong> c</p>',
                'a{*b*} c',
                '<p>a<strong>b</strong> c</p>',
            ],
            // Nesting does not change the question: the inner span is intraword
            // inside its own parent's content.
            'inside another emphasis' => [
                '<p>a <em>x<strong>b</strong>y</em> c</p>',
                'a /x{*b*}y/ c',
                '<p>a <em>x<strong>b</strong>y</em> c</p>',
            ],
        ];
    }

    #[DataProvider('intrawordProvider')]
    public function testAnIntrawordElementImportsBraced(string $html, string $carve, string $rendered): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($carve, trim($imported));
        $this->assertSame($rendered . "\n", (new CarveConverter())->convert($imported));
    }

    /**
     * The other half of the rule, and the half a fix could break by bracing
     * everything: away from a word character the bare form is canonical, and
     * `carve fmt` writes it, so bracing here would leave the importer's output
     * outside the formatter's image.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function wordBoundedProvider(): array
    {
        return [
            'between spaces' => ['<p>a <strong>b</strong> c</p>', 'a *b* c'],
            'em between spaces' => ['<p>a <em>b</em> c</p>', 'a /b/ c'],
            'u between spaces' => ['<p>a <u>b</u> c</p>', 'a _b_ c'],
            's between spaces' => ['<p>a <s>b</s> c</p>', 'a ~b~ c'],
            'mark between spaces' => ['<p>a <mark>b</mark> c</p>', 'a =b= c'],
            'the whole paragraph' => ['<p><strong>b</strong></p>', '*b*'],
            'at the start' => ['<p><strong>b</strong> c</p>', '*b* c'],
            'at the end' => ['<p>a <strong>b</strong></p>', 'a *b*'],
            // Punctuation is not a word character. These are the cases the old
            // whitespace-bounded test over-braced.
            'inside parentheses' => ['<p>(<strong>b</strong>) c</p>', '(*b*) c'],
            'before a comma' => ['<p>a <strong>b</strong>, c</p>', 'a *b*, c'],
            'after a hyphen' => ['<p>a-<strong>b</strong> c</p>', 'a-*b* c'],
            // The rule is ASCII-only in every engine, so an accented letter
            // does not block the bare delimiter.
            'between accented letters' => ['<p>é<strong>b</strong>é</p>', 'é*b*é'],
            'between guillemets' => ['<p>«<strong>b</strong>»</p>', '«*b*»'],
            // A neighbouring construct ends in its own closing punctuation, so
            // it puts no word character next to the delimiter.
            'after an emphasis' => ['<p><em>a</em><strong>b</strong> c</p>', '/a/*b* c'],
            'before an emphasis' => ['<p>a <strong>b</strong><em>c</em></p>', 'a *b*/c/'],
            'after a link' => ['<p><a href="/x">ab</a><strong>c</strong> d</p>', '[ab](/x)*c* d'],
            // The `=` arm was the one that already asked the question, and it
            // asked a WHITESPACE-bounded one: anything but a space on either
            // side forced the braces, so it over-braced everywhere punctuation
            // or a sibling construct sat next to the mark. These two are what
            // that cost, and what the writer's rule gives back.
            // The empty-wrapper walk must not run the other way either: a
            // space before the wrapper is still a space.
            'after an empty span and a space' => [
                '<p>a <span></span><strong>b</strong> c</p>',
                'a *b* c',
            ],
            // A break IS a node whatever it holds, so the search stops at it
            // rather than reaching the word before - which is how the writer
            // measures it too.
            'after a break' => ['<p>a<br><strong>b</strong> c</p>', "a\\\n*b* c"],
            'mark inside parentheses' => ['<p>(<mark>b</mark>) c</p>', '(=b=) c'],
            'mark after an emphasis' => ['<p><em>a</em><mark>b</mark> c</p>', '/a/=b= c'],
            'after an attributed span' => [
                '<p><span class="z">ab</span><strong>c</strong> d</p>',
                '[ab]{.z}*c* d',
            ],
        ];
    }

    #[DataProvider('wordBoundedProvider')]
    public function testAWordBoundedElementKeepsTheBareForm(string $html, string $carve): void
    {
        $this->assertSame($carve, trim((new HtmlToCarve())->convert($html)));
    }

    /**
     * `docs/html-import.md` requires that "an importer emits the source `carve
     * fmt` emits". The formatter decides bare vs braced with
     * `CarveRenderer::renderEmphasis`, so an importer that answered the
     * question differently - in EITHER direction - would put its own output
     * outside the formatter's image, and formatting an imported document would
     * rewrite the line.
     *
     * @return array<string, array{0: string}>
     */
    public static function fixedPointProvider(): array
    {
        $html = [];
        foreach (self::intrawordProvider() as $name => $case) {
            $html['intraword: ' . $name] = [$case[0]];
        }
        foreach (self::wordBoundedProvider() as $name => $case) {
            $html['word-bounded: ' . $name] = [$case[0]];
        }

        return $html;
    }

    #[DataProvider('fixedPointProvider')]
    public function testTheImportedSpellingIsAFormatterFixedPoint(string $html): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }
}
