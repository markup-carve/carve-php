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
    }

    /**
     * Where PART 9 section 13 wraps a heading, the carrier rides the
     * `<section>` and the heading writes none - so the heading's authored
     * attribute collides with nothing and survives on the `<h1>`.
     */
    public function testAWrappedHeadingHasNoCollisionToReport(): void
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))
            ->convertWithReport("{#h data-carve-explicit-id=x}\n# T\n");

        $this->assertStringContainsString('<section id="h" data-carve-explicit-id="1">', $report->value);
        $this->assertStringContainsString('<h1 data-carve-explicit-id="x">', $report->value);
        $this->assertSame(0, $report->totalLosses);
    }

    /**
     * The carriers this node will actually write, never the whole inventory: a
     * link that resolved no reference collides with nothing.
     */
    public function testAnAuthoredNameTheNodeWritesNoCarrierForSurvives(): void
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport("[a](/u){data-carve-ref=x}\n");

        $this->assertStringContainsString('data-carve-ref="x"', $report->value);
        $this->assertSame(0, $report->totalLosses);
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
        $this->assertStringContainsString('data-marker="x"', $report->value);
        $this->assertSame(1, substr_count($report->value, 'data-marker='));
        $this->assertSame(0, $report->totalLosses);
    }

    public function testWithoutRoundtripModeNothingCollides(): void
    {
        $report = (new CarveConverter(renderer: new HtmlRenderer()))
            ->convertWithReport("{data-char=x}\n***\n");

        $this->assertStringContainsString('data-char="x"', $report->value);
        $this->assertSame(0, $report->totalLosses);
    }
}
