<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 section 13b. Every collision used to emit BOTH attributes, which is
 * two attributes of one name and so invalid HTML, and the precedence varied by
 * carrier: the carrier led for `data-marker`, the authored attribute led for
 * `data-char`, `data-carve-col-widths` and `data-carve-src`.
 */
class ACarrierWinsACollisionWithAnAuthoredAttributeTest extends TestCase
{
    /**
     * Each case authors the carrier's own name on a node that writes that
     * carrier, so the carrier and the author want the same attribute slot.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function collisions(): array
    {
        return [
            'a thematic break character' => ["{data-char=x}\n***\n", 'data-char', '*'],
            'a bullet list marker' => ["{data-marker=x}\n* a\n", 'data-marker', '*'],
            'a table separator width' => [
                "{data-carve-col-widths=x}\n| a | b |\n|---|-----|\n| c | d |\n",
                'data-carve-col-widths',
                '',
            ],
            'a heading explicit id' => [
                "> {#h data-carve-explicit-id=x}\n> # T\n",
                'data-carve-explicit-id',
                '1',
            ],
            'a link reference label' => ["[a][r]{data-carve-ref=x}\n\n[r]: /u\n", 'data-carve-ref', 'r'],
            'an image reference label' => ["![a][r]{data-carve-ref=x}\n\n[r]: /i.png\n", 'data-carve-ref', 'r'],
            'an autolink marker' => ["<https://e.test>{data-carve-autolink=x}\n", 'data-carve-autolink', '1'],
            'a list tightness' => ["{data-carve-tight=x}\n- {.k}\n  a\n- b\n", 'data-carve-tight', '1'],
        ];
    }

    #[DataProvider('collisions')]
    public function testTheCarrierIsWrittenOnceAndTheAuthoredValueIsGone(
        string $carve,
        string $name,
        string $value,
    ): void {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport($carve);
        $html = $report->value;

        $this->assertSame(1, substr_count($html, $name . '='), "{$name} was emitted twice");
        $this->assertStringNotContainsString($name . '="x"', $html, 'the authored value survived');
        if ($value !== '') {
            $this->assertStringContainsString($name . '="' . $value . '"', $html);
        }
    }

    #[DataProvider('collisions')]
    public function testTheDropIsOneRowNamingTheAttribute(string $carve, string $name, string $value): void
    {
        unset($value);
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport($carve);

        $rows = array_values(array_filter(
            $report->losses,
            static fn (array $row): bool => $row['code'] === 'carrier-attribute-dropped',
        ));
        $this->assertCount(1, $rows);
        $this->assertSame($name, $rows[0]['attribute']);
        $this->assertSame('html', $rows[0]['target']);
        $this->assertSame(['carrier-attribute-dropped' => 1], $report->lossCounts);
    }

    public function testACodeBlockDropsAndReportsAuthoredStoredSource(): void
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))
            ->convertWithReport("{data-carve-src=x}\n``` php\na\n```\n");

        $open = substr($report->value, 0, (int)strpos($report->value, '><code'));
        $this->assertStringNotContainsString('data-carve-src="x"', $open);
        $this->assertStringNotContainsString('data-carve-src', $report->value);
        $this->assertSame('data-carve-src', $report->losses[0]['attribute']);
        $this->assertSame(['carrier-attribute-dropped' => 1], $report->lossCounts);
    }

    /**
     * PART 10 section 13a reserves `data-carve-src` without a row, on every
     * element: roundtrip export writes none and reports the authored one,
     * default export passes it through.
     *
     * @return array<string, array{string}>
     */
    public static function retiredSourceHosts(): array
    {
        return [
            'a paragraph' => ["{data-carve-src=x}\ntext\n"],
            'a wrapped heading' => ["{data-carve-src=x}\n# T\n"],
            'a heading in a quote' => ["> {data-carve-src=x}\n> # T\n"],
            'a bullet list' => ["{data-carve-src=x}\n- a\n"],
            'an ordered list' => ["{data-carve-src=x}\n1. a\n"],
            'a block quote' => ["{data-carve-src=x}\n> a\n"],
            'a thematic break' => ["{data-carve-src=x}\n***\n"],
            'a table' => ["{data-carve-src=x}\n| a | b |\n|---|---|\n| c | d |\n"],
            'a div' => ["{data-carve-src=x}\n:::\na\n:::\n"],
            'a code block' => ["{data-carve-src=x}\n```php\na\n```\n"],
            'a span' => ["[a]{data-carve-src=x}\n"],
            'a link' => ["[a](/u){data-carve-src=x}\n"],
            'an image' => ["![a](/i.png){data-carve-src=x}\n"],
            'an attributed paragraph in a tight item' => ["- {data-carve-src=x}\n  a\n- b\n"],
            'another letter case' => ["{DATA-CARVE-SRC=x}\ntext\n"],
        ];
    }

    #[DataProvider('retiredSourceHosts')]
    public function testRoundtripExportDropsAndReportsTheRetiredSourceName(string $carve): void
    {
        $report = (new CarveConverter(roundTripMode: true))->convertWithReport($carve);

        $this->assertStringNotContainsStringIgnoringCase('data-carve-src', $report->value);
        $rows = array_values(array_filter(
            $report->losses,
            static fn (array $row): bool => $row['code'] === 'carrier-attribute-dropped',
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('data-carve-src', $rows[0]['attribute']);
        $this->assertSame(['carrier-attribute-dropped' => 1], $report->lossCounts);
    }

    #[DataProvider('retiredSourceHosts')]
    public function testDefaultExportPassesTheRetiredSourceNameThrough(string $carve): void
    {
        $report = (new CarveConverter())->convertWithReport($carve);

        $this->assertSame(1, substr_count(strtolower($report->value), 'data-carve-src="x"'));
        $this->assertSame([], $report->losses);
    }

    /**
     * PART 10 section 13a: a carrier follows the structural attributes and
     * leads the authored ones, whichever carrier it is.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function placements(): array
    {
        return [
            'a tight bullet list' => [
                "{.l #i k=v}\n- {.x}\n  a\n- b\n",
                '<ul class="l" id="i" k="v">',
                '<ul data-carve-tight="1" class="l" id="i" k="v">',
            ],
            'a tight star list' => [
                "{.l}\n* {.x}\n  a\n* b\n",
                '<ul class="l">',
                '<ul data-marker="*" data-carve-tight="1" class="l">',
            ],
            'a tight ordered list' => [
                "{.l}\n3) {.x}\n   a\n4) b\n",
                '<ol start="3" data-delim=")" class="l">',
                '<ol start="3" data-delim=")" data-carve-tight="1" class="l">',
            ],
            'a thematic break' => ["{.c #i}\n***\n", '<hr class="c" id="i">', '<hr data-char="*" class="c" id="i">'],
            'a section with a trailing id' => [
                "{.c #mine}\n# H\n",
                '<section id="mine">',
                '<section id="mine" data-carve-explicit-id="1" data-carve-id-slot="1">',
            ],
            'a section with a generated id' => [
                "{.c}\n# H\n",
                '<section id="H">',
                '<section id="H" data-carve-generated-id="1">',
            ],
        ];
    }

    #[DataProvider('placements')]
    public function testACarrierLeadsTheAuthoredAttributes(string $carve, string $default, string $roundtrip): void
    {
        $this->assertStringContainsString($default, (new CarveConverter())->convert($carve));
        $this->assertStringContainsString($roundtrip, (new CarveConverter(roundTripMode: true))->convert($carve));
    }

    /**
     * PART 10 section 13: the carrier is written beside the report, not in
     * place of it.
     */
    public function testARawInlineReportsItsDroppedFormatWithTheOptionOnOrOff(): void
    {
        $carve = "a `x<y`{=latex} b\n";
        $default = (new CarveConverter())->convertWithReport($carve);
        $roundtrip = (new CarveConverter(roundTripMode: true))->convertWithReport($carve);

        $this->assertSame(preg_replace('/\n\z/', '', "<p>a  b</p>\n"), $default->value);
        $this->assertSame(preg_replace('/\n\z/', '', "<p>a <span data-carve-raw=\"latex\">x&lt;y</span> b</p>\n"), $roundtrip->value);
        foreach ([$default, $roundtrip] as $report) {
            $this->assertSame(['raw-format-dropped' => 1], $report->lossCounts);
            $this->assertSame('latex', $report->losses[0]['format']);
        }
    }

    /**
     * PART 10 section 13b: the carrier of a wrapped heading rides the
     * `<section>`, and the authored attribute is still the heading's to lose.
     * An importer consumes all three names on either element.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function headingCarrierNames(): array
    {
        $shapes = [
            'wrapped, authored id' => ["{#h %s=x}\n# T\n", '<section id="h" data-carve-explicit-id="1">' . "\n  <h1>T</h1>"],
            'wrapped, authored id in the second slot' => [
                "{.c #h %s=x}\n# T\n",
                '<section id="h" data-carve-explicit-id="1" data-carve-id-slot="1">' . "\n  <h1 class=\"c\">T</h1>",
            ],
            'wrapped, generated id' => ["{%s=x}\n# T\n", '<section id="T" data-carve-generated-id="1">' . "\n  <h1>T</h1>"],
            'in a quote, authored id' => ["> {#h %s=x}\n> # T\n", '<h1 data-carve-explicit-id="1" id="h">T</h1>'],
            'in a quote, generated id' => ["> {%s=x}\n> # T\n", '<h1 data-carve-generated-id="1" id="T">T</h1>'],
        ];
        $cases = [];
        foreach ($shapes as $label => [$carve, $html]) {
            foreach (['data-carve-explicit-id', 'data-carve-id-slot', 'data-carve-generated-id'] as $name) {
                $cases[$label . ', ' . $name] = [sprintf($carve, $name), $name, $html];
            }
        }

        return $cases;
    }

    #[DataProvider('headingCarrierNames')]
    public function testAHeadingDropsAndReportsAnAuthoredCarrierName(string $carve, string $name, string $html): void
    {
        $report = (new CarveConverter(roundTripMode: true))->convertWithReport($carve);

        $this->assertStringContainsString($html, $report->value);
        $this->assertStringNotContainsString($name . '="x"', $report->value);
        $this->assertCount(1, $report->losses);
        $this->assertSame('carrier-attribute-dropped', $report->losses[0]['code']);
        $this->assertSame($name, $report->losses[0]['attribute']);
        $this->assertSame(['carrier-attribute-dropped' => 1], $report->lossCounts);
    }

    #[DataProvider('headingCarrierNames')]
    public function testDefaultExportPassesAHeadingsCarrierNameThrough(string $carve, string $name, string $html): void
    {
        unset($html);
        $report = (new CarveConverter())->convertWithReport($carve);

        $this->assertSame(1, substr_count($report->value, $name . '="x"'));
        $this->assertStringContainsString('<h1 ', $report->value);
        $this->assertSame([], $report->losses);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unreservedHeadingNames(): array
    {
        return [
            'wrapped' => ["{#h data-keep=x}\n# T\n", '<h1 data-keep="x">T</h1>'],
            'in a quote' => ["> {#h data-keep=x}\n> # T\n", '<h1 data-carve-explicit-id="1" id="h" data-keep="x">T</h1>'],
            'an unlisted prefixed name' => ["{#h data-carve-mine=x}\n# T\n", '<h1 data-carve-mine="x">T</h1>'],
        ];
    }

    #[DataProvider('unreservedHeadingNames')]
    public function testAHeadingKeepsANameTheInventoryDoesNotList(string $carve, string $html): void
    {
        $report = (new CarveConverter(roundTripMode: true))->convertWithReport($carve);

        $this->assertStringContainsString($html, $report->value);
        $this->assertSame(0, $report->totalLosses);
    }

    /**
     * The carriers this node will actually write, never the whole inventory: a
     * link that resolved no reference collides with nothing.
     */
    public function testAReservedNameIsDroppedWithoutAnEmittedCarrier(): void
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport("[a](/u){data-carve-ref=x}\n");

        $this->assertStringNotContainsString('data-carve-ref="x"', $report->value);
        $this->assertSame(1, $report->totalLosses);
    }

    /**
     * PART 10 section 13a: an ordered list's marker IS section 12's
     * `data-delim`, so `<ol>` writes no `data-marker` and an authored one of
     * that name collides with nothing.
     */
    public function testAnOrderedListWritesNoMarkerCarrier(): void
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport("{data-marker=x}\n1) a\n");

        $this->assertStringContainsString('data-delim=")"', $report->value);
        $this->assertStringNotContainsString('data-marker="x"', $report->value);
        $this->assertSame(0, substr_count($report->value, 'data-marker='));
        $this->assertSame(1, $report->totalLosses);
    }

    public function testWithoutRoundtripModeNothingCollides(): void
    {
        $report = (new CarveConverter(renderer: new HtmlRenderer()))
            ->convertWithReport("{data-char=x}\n***\n");

        $this->assertStringContainsString('data-char="x"', $report->value);
        $this->assertSame(0, $report->totalLosses);
    }

    /**
     * Default export reserves no carrier name, `data-carve-src` included.
     */
    public function testWithoutRoundtripModeACodeBlockKeepsAuthoredStoredSource(): void
    {
        $report = (new CarveConverter(renderer: new HtmlRenderer()))
            ->convertWithReport("{data-carve-src=\"x\"}\n```php\necho 1;\n```\n");

        $this->assertSame(
            preg_replace('/\n\z/', '', "<pre data-carve-src=\"x\"><code class=\"language-php\">echo 1;\n</code></pre>\n"),
            $report->value,
        );
        $this->assertSame([], $report->losses);
        $this->assertSame(0, $report->totalLosses);
    }
}
