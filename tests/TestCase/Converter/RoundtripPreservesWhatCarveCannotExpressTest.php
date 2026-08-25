<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `roundtrip` KEEPS WHAT CARVE CANNOT EXPRESS, BYTE FOR BYTE
 * (`markup-carve/carve-php#1713`).
 *
 * This engine was the only one of the three that answered `<iframe>`, `<math>`
 * and `<form>` in the fidelity mode by dropping, unwrapping or degrading them.
 * `docs/html-import.md` calls preserving a MAY, which made that permitted
 * rather than right: three engines answering one document three ways in the
 * mode whose whole contract is fidelity is not a cosmetic difference.
 *
 * EVERY EXPECTATION HERE WAS MEASURED AGAINST carve-js, per input, rather than
 * ported from memory - the point of the change is that the engines stop
 * disagreeing, so its behavior is the target and not a fresh design.
 *
 * THE ARM OWES BOTH HALVES. `raw-preserved` for the element, and
 * `attribute-preserved` for the element's own refused attributes with the
 * severity `markup-carve/carve#1710` ruled. Landing the first without the
 * second would reintroduce exactly the false row `markup-carve/carve-js#1468`
 * removed, in the engine that never had it.
 */
class RoundtripPreservesWhatCarveCannotExpressTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<int, array{string, string}>}>
     */
    public static function preservedProvider(): array
    {
        return [
            // THE FOUR BLOCK-LEVEL NAMES take the raw BLOCK, because they carry
            // blocks and an inline span around them would put block markup
            // inside a paragraph.
            'a form keeps its handler and says the handler is live' => [
                '<form onclick="x()" id="q"><p>a</p></form>',
                "```=html\n<form onclick=\"x()\" id=\"q\"><p>a</p></form>\n```\n",
                [['attribute-preserved', 'error'], ['raw-preserved', 'warning']],
            ],
            'a fieldset with nothing refused takes one row' => [
                '<fieldset id="f"><p>a</p></fieldset>',
                "```=html\n<fieldset id=\"f\"><p>a</p></fieldset>\n```\n",
                [['raw-preserved', 'warning']],
            ],
            'an address' => [
                '<address id="a">x</address>',
                "```=html\n<address id=\"a\">x</address>\n```\n",
                [['raw-preserved', 'warning']],
            ],
            // `<hgroup>` used to UNWRAP to its heading, which is the loss that
            // is hardest to notice: the output is well-formed and the grouping
            // is simply gone.
            'an hgroup' => [
                '<hgroup><h1>t</h1></hgroup>',
                "```=html\n<hgroup><h1>t</h1></hgroup>\n```\n",
                [['raw-preserved', 'warning']],
            ],
            // THE EMBEDS took the worst of the three old answers: DROPPED
            // outright, content and all.
            'an iframe' => [
                '<iframe src="a" onload="z()" id="i"></iframe>',
                "`<iframe src=\"a\" onload=\"z()\" id=\"i\"></iframe>`{=html}\n",
                [['attribute-preserved', 'error'], ['raw-preserved', 'warning']],
            ],
            'an audio element' => [
                '<audio src="a.mp3"></audio>',
                "`<audio src=\"a.mp3\"></audio>`{=html}\n",
                [['raw-preserved', 'warning']],
            ],
            'an object' => [
                '<object data="d"></object>',
                "`<object data=\"d\"></object>`{=html}\n",
                [['raw-preserved', 'warning']],
            ],
            'an svg' => [
                '<svg><circle r="1"/></svg>',
                "`<svg><circle r=\"1\"></circle></svg>`{=html}\n",
                [['raw-preserved', 'warning']],
            ],
            // A `<video>` UNWRAPPED to its fallback text, so the document came
            // back saying the fallback as if it were the content.
            'a video keeps its fallback inside the markup' => [
                '<video onerror="q()" id="v"><p>fb</p></video>',
                "`<video onerror=\"q()\" id=\"v\"><p>fb</p></video>`{=html}\n",
                [['attribute-preserved', 'error'], ['raw-preserved', 'warning']],
            ],
            'a canvas' => [
                '<canvas id="c">fb</canvas>',
                "`<canvas id=\"c\">fb</canvas>`{=html}\n",
                [['raw-preserved', 'warning']],
            ],
            'a ruby' => [
                '<p><ruby>x</ruby></p>',
                "`<ruby>x</ruby>`{=html}\n",
                [['raw-preserved', 'warning']],
            ],
            // A `<math>` with no TeX anywhere: its children are a token stream
            // whose concatenation is meaningless, so the markup is the only
            // thing left that means anything.
            'a math element with no TeX in it' => [
                '<math id="m" onclick="w()"><mi>x</mi></math>',
                "`<math id=\"m\" onclick=\"w()\"><mi>x</mi></math>`{=html}\n",
                [['attribute-preserved', 'error'], ['raw-preserved', 'warning']],
            ],
            // AN UNKNOWN TAG reaches the same arm, which is what makes the rule
            // derived rather than a roster: what preserves is what this
            // converter has no spelling for.
            'a tag no one has heard of' => [
                '<xyzzy id="k">a</xyzzy>',
                "`<xyzzy id=\"k\">a</xyzzy>`{=html}\n",
                [['raw-preserved', 'warning']],
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $carve
     * @param array<int, array{string, string}> $expectedRows
     */
    #[DataProvider('preservedProvider')]
    public function testTheElementIsKept(string $html, string $carve, array $expectedRows): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $rows = [];
        foreach ($result->diagnostics as $diagnostic) {
            $row = $diagnostic->toArray();
            $rows[] = [$row['code'], $row['severity']];
        }
        $this->assertSame($expectedRows, $rows);
    }

    /**
     * THE OTHER MODES ARE UNCHANGED, and that is the half a preserve arm could
     * break by reaching too far. `safe` and `semantic` read untrusted HTML, so
     * handing raw markup back there would be a hole, not a fidelity win.
     */
    #[DataProvider('preservedProvider')]
    public function testTheUntrustedModesKeepTheirAnswer(string $html, string $carve, array $expectedRows): void
    {
        unset($carve, $expectedRows);
        foreach (['safe', 'semantic'] as $mode) {
            $value = (new HtmlToCarve(importMode: $mode))->convert($html);
            $this->assertStringNotContainsString('{=html}', $value, $mode);
            $this->assertStringNotContainsString('```=html', $value, $mode);
        }
    }

    /**
     * A FIGURE IS PRESERVED ONLY WHERE NO CARVE SPELLING REPRODUCES IT
     * (`markup-carve/carve#1704`).
     *
     * An image, a quote and a code block each write a `^ ` line the parser
     * reads back as the same figure, so they rebuild and lose nothing.
     *
     * @return array<string, array{string, string, array<int, string>}>
     */
    public static function figureProvider(): array
    {
        return [
            'around a list, which no caption line reproduces' => [
                '<figure onclick="c()" id="g"><ul><li>a</li></ul><figcaption>Cap</figcaption></figure>',
                "```=html\n<figure onclick=\"c()\" id=\"g\"><ul><li>a</li></ul><figcaption>Cap</figcaption></figure>\n```\n",
                ['attribute-preserved', 'raw-preserved'],
            ],
            // The worst of the old shapes: the body came back as prose with the
            // caption as a detached paragraph under it, so the figure was gone
            // and the caption was no longer merely lost but turned into text
            // the document never said.
            'around a bare paragraph' => [
                '<figure><p>body</p><figcaption>Cap</figcaption></figure>',
                "```=html\n<figure><p>body</p><figcaption>Cap</figcaption></figure>\n```\n",
                ['raw-preserved'],
            ],
            'around an image, which rebuilds' => [
                '<figure><img src="i.png" alt="A"><figcaption>Cap</figcaption></figure>',
                "![A](i.png)\n^ Cap\n",
                [],
            ],
            'around a quote, which rebuilds' => [
                '<figure><blockquote><p>q</p></blockquote><figcaption>Cap</figcaption></figure>',
                "> q\n^ Cap\n",
                [],
            ],
            'around a code block, which rebuilds' => [
                '<figure><pre><code>c</code></pre><figcaption>Cap</figcaption></figure>',
                "```\nc\n```\n^ Cap\n",
                [],
            ],
            // A CAPTION IS WHAT MAKES IT A FIGURE (PART 9 §4b), so an
            // uncaptioned wrapper is not one to preserve and unwraps as before.
            // The unwrap now SAYS so: the boundary was always right and the
            // silence next to it was not (carve-php#1723).
            'with no caption at all, which is not a figure' => [
                '<figure><ul><li>a</li></ul></figure>',
                "- a\n",
                ['element-unwrapped'],
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $carve
     * @param array<int, string> $expectedCodes
     */
    #[DataProvider('figureProvider')]
    public function testTheFigureRule(string $html, string $carve, array $expectedCodes): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $this->assertSame($expectedCodes, array_column(
            array_map(static fn ($d) => $d->toArray(), $result->diagnostics),
            'code',
        ));
    }

    /**
     * THE ROWS FROM INSIDE A PRESERVED ELEMENT COME BACK OUT, because that
     * subtree's losses did not happen: it is in the output exactly as it was
     * written. A row naming an attribute the preserved bytes still carry would
     * be a false report.
     *
     * The `title` here spans a line break, which this importer refuses on its
     * own - and inside a preserved `<form>` it is simply in the output.
     */
    public function testTheRowsFromInsideAPreservedElementRollBack(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport("<form><p title=\"a\nb\">x</p></form>");

        $this->assertStringContainsString("title=\"a\nb\"", $result->value);
        $this->assertSame(
            ['raw-preserved'],
            array_column(array_map(static fn ($d) => $d->toArray(), $result->diagnostics), 'code'),
        );
    }

    /**
     * AN ELEMENT THAT IS NOT PRESERVED STILL REPORTS ITS REAL LOSSES, which is
     * what keeps the arm from being a blanket silence. A `<section>` maps to a
     * container and goes on mapping; an `<article>` the same.
     */
    public function testAnUnpreservedElementStillReportsWhatItLoses(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<p onclick="x()">a</p>');

        $this->assertSame("a\n", $result->value);
        $rows = array_map(static fn ($d) => $d->toArray(), $result->diagnostics);
        $this->assertSame(['attribute-dropped'], array_column($rows, 'code'));
        $this->assertSame('warning', $rows[0]['severity']);
    }

    /**
     * A SECTIONING WRAPPER IS NOT UNSUPPORTED MARKUP. Those names map to
     * containers, so preserving them would trade a lossless spelling for an
     * opaque block - the arm has to know the difference.
     */
    public function testASectioningWrapperIsNotPreserved(): void
    {
        foreach (['article', 'main', 'header', 'footer', 'nav', 'aside', 'section'] as $tag) {
            $value = (new HtmlToCarve(importMode: 'roundtrip'))->convert('<' . $tag . '><p>a</p></' . $tag . '>');
            $this->assertStringNotContainsString('{=html}', $value, $tag);
            $this->assertStringNotContainsString('```=html', $value, $tag);
        }
    }

    /**
     * THE PRESERVED BYTES ARE THE ELEMENT'S OWN. Read back, the raw block has
     * to render the element again - a preserve that mangled the markup would
     * still pass every byte assertion above if the expectation were written
     * from the same mangling.
     */
    public function testThePreservedBytesRenderTheElementAgain(): void
    {
        $carve = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convert('<form onclick="x()" id="q"><p>a</p></form>');
        $html = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString('<form onclick="x()" id="q">', $html);
        $this->assertStringContainsString('<p>a</p>', $html);
    }
}
