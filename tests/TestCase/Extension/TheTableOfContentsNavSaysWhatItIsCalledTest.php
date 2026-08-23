<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\TableOfContentsExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE TABLE-OF-CONTENTS NAV SAYS WHAT IT IS CALLED (Extensions §8b.1, ruled in
 * markup-carve/carve#1547 closing markup-carve/carve#1509).
 *
 * `<nav>` is a navigation landmark unconditionally - unlike `<section>`, which
 * maps to `generic` until it is named - so an unnamed one is an entry in a
 * reader's landmark list reading only "navigation". A page holds more than one
 * the moment both TOC extensions are registered, a document writes `::: toc`
 * twice, or a site template contributes its own, and unnamed they are
 * indistinguishable. That is the defect; a single anonymous nav is only how it
 * starts.
 *
 * AUTHORED, so it is a `labels` key rather than an extension option: the
 * directive's content is empty and nothing on the page names the nav, so there
 * is no string to derive from; `Table of contents` is ordinary English rather
 * than the class word `toc` an abbreviation-expanding reader would hear spelled
 * out; and no configuration put an `aria-label` on this nav before, so §1.5's
 * "unless the extension already exposes it as an option" does not fire.
 */
class TheTableOfContentsNavSaysWhatItIsCalledTest extends TestCase
{
    /**
     * @var string
     */
    protected const HEADINGS = "# One\n\n## Two\n\nbody\n";

    /**
     * @var string
     */
    protected const PLACED = "::: toc\n:::\n\n# One\n\n## Two\n\nbody\n";

    /**
     * THE THREE-ASSERTION STANDARD (markup-carve/carve#1511): a key is measured
     * by the documented default reaching the output, by the map entry CHANGING
     * it, and against a row for a key that already worked before this ruling -
     * without which a probe finding nothing in either render satisfies the
     * first two vacuously.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function labelBackedNames(): array
    {
        return [
            'the ::: toc directive' => ['placement', 'tocNav', 'Table of contents'],
            'the injected nav' => ['injected', 'tocNav', 'Table of contents'],
            'a key that already worked, as the control' => ['tabs', 'tabsGroup', 'Tabs'],
        ];
    }

    /**
     * The name as it reaches the rendered document, or null if the probe found
     * nothing.
     *
     * The probe reads the `aria-label` off the ELEMENT under test, not off the
     * document: every other named element in this suite writes the same
     * attribute, and the nav's class is an option (`$cssClass`), so neither the
     * attribute alone nor the class string identifies what was measured.
     *
     * @param string $row Which element to render and probe.
     * @param array<string, string> $labels The render's `labels` map.
     *
     * @return string|null
     */
    protected function renderedName(string $row, array $labels = []): ?string
    {
        $converter = new CarveConverter(labels: $labels);
        if ($row === 'tabs') {
            $converter->addExtension(new TabsExtension());
            $html = $converter->convert(":::: tabs\n\n::: tab [One]\na\n:::\n\n::::\n");
            $pattern = '/<div class="tabs"[^>]*?\saria-label="([^"]*)"/';
        } else {
            if ($row === 'injected') {
                $converter->addExtension(new TableOfContentsExtension(position: 'top'));
                $html = $converter->convert(self::HEADINGS);
            } else {
                $converter->addExtension(new TocPlacementExtension());
                $html = $converter->convert(self::PLACED);
            }
            $pattern = '/<nav\b[^>]*?\saria-label="([^"]*)"/';
        }

        return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
    }

    /**
     * Assertion one. Without it the assertion below could hold on a render
     * where the probe finds nothing at all.
     */
    #[DataProvider('labelBackedNames')]
    public function testTheDocumentedEnglishDefaultRenders(string $row, string $key, string $default): void
    {
        $this->assertSame($default, $this->renderedName($row));
    }

    /**
     * Assertion two: the map entry CHANGES it, which is what having a key means
     * observationally - and what a hard-coded English string cannot do.
     */
    #[DataProvider('labelBackedNames')]
    public function testTheLabelsMapReachesIt(string $row, string $key, string $default): void
    {
        $this->assertSame(
            'Sentinel-' . $key,
            $this->renderedName($row, [$key => 'Sentinel-' . $key]),
        );
    }

    public function testTheKeyIsDeclaredWithItsDocumentedDefault(): void
    {
        $this->assertSame('Table of contents', HtmlRenderer::LABEL_DEFAULTS['tocNav']);
    }

    /**
     * §8b.3 makes the nav fragment the cross-impl contract, and a name chosen
     * per-extension is the one change that would break byte-identity between
     * the two extensions that write it.
     */
    public function testBothExtensionsWriteTheSameNavByteForByte(): void
    {
        foreach ([[], ['tocNav' => 'Inhaltsverzeichnis']] as $labels) {
            $placement = new CarveConverter(labels: $labels);
            $placement->addExtension(new TocPlacementExtension());
            $injector = new CarveConverter(labels: $labels);
            $injector->addExtension(new TableOfContentsExtension(position: 'top'));

            $this->assertSame(
                $this->navOf($placement->convert(self::PLACED)),
                $this->navOf($injector->convert(self::HEADINGS)),
            );
        }
    }

    /**
     * The `<nav>…</nav>` fragment, which is the thing §8b.3 pins.
     */
    protected function navOf(string $html): string
    {
        $start = (int)strpos($html, '<nav');
        $end = (int)strpos($html, '</nav>') + 6;

        return substr($html, $start, $end - $start);
    }

    /**
     * A name the AUTHOR wrote outranks the label and nothing is added beside
     * it - §1.5's existing precedence rather than a new rule, since §8b.1
     * already carries `{#id .class}` onto the nav. The match is on the
     * attribute NAME, case-insensitively (§16a, the shapes carve#1468 closed),
     * and this engine echoes the author's own spelling back, so a
     * case-sensitive test would write a second name next to theirs.
     *
     * @return array<string, array{0: string}>
     */
    public static function authoredSpellings(): array
    {
        return [
            'lower case' => ['aria-label'],
            'upper case' => ['ARIA-LABEL'],
            'mixed case' => ['Aria-Label'],
        ];
    }

    #[DataProvider('authoredSpellings')]
    public function testAnAuthoredNameWinsUnderAnyAsciiCasing(string $spelling): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());
        $html = $converter->convert('{' . $spelling . "=\"Chapters\"}\n" . self::PLACED);

        $this->assertStringContainsString($spelling . '="Chapters"', $html);
        $this->assertStringNotContainsString('Table of contents', $html);
        $this->assertSame(1, preg_match_all('/aria-label=/i', $html));
    }

    public function testAnEmptyEntrySuppressesTheNameEntirely(): void
    {
        $converter = new CarveConverter(labels: ['tocNav' => '']);
        $converter->addExtension(new TocPlacementExtension());
        $html = $converter->convert(self::PLACED);

        $this->assertStringContainsString('<nav class="toc">', $html);
        $this->assertStringNotContainsString('aria-label', $html);
    }

    public function testTheNameIsEscapedWhereItLands(): void
    {
        $this->assertSame(
            'A &quot;quoted&quot; &amp; &lt;angled&gt;',
            $this->renderedName('placement', ['tocNav' => 'A "quoted" & <angled>']),
        );
    }

    /**
     * THE DEGRADED NAV IS STILL A LANDMARK. `::: toc` renders an EMPTY `<nav>`
     * when no heading falls in its window, and again once the cumulative byte
     * budget that bounds K blocks by N headings is exhausted. The budget bounds
     * the ENTRY LIST, not the element's identity - and the empty nav is exactly
     * where an unnamed landmark is least distinguishable, because there is no
     * link text to read instead.
     */
    public function testAnEmptyNavIsNamedToo(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());

        $this->assertStringContainsString(
            '<nav class="toc" aria-label="Table of contents"></nav>',
            $converter->convert("::: toc\n:::\n\nplain paragraph\n"),
        );
    }

    public function testANavTheByteBudgetDegradedKeepsItsName(): void
    {
        $source = str_repeat("::: toc\n:::\n\n", 5000);
        for ($i = 0; $i < 50; $i++) {
            $source .= '# Heading number ' . $i . " with length\n\n";
        }

        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());
        $html = $converter->convert($source);

        $degraded = [];
        preg_match_all('#<nav[^>]*></nav>#', $html, $degraded);
        // The budget IS reached - without this the loop below passes on a
        // render where nothing degraded at all.
        $this->assertNotEmpty($degraded[0]);
        foreach ($degraded[0] as $nav) {
            $this->assertSame('<nav class="toc" aria-label="Table of contents"></nav>', $nav);
        }
    }

    /**
     * NOTHING ELSE TAKES THE LABEL. With `$collapsible` on, the standalone
     * extension renders `<details class="toc">` with a `<summary>` and no
     * `<nav>` at all, so the two strings sit on mutually exclusive shapes: one
     * is a landmark's accessible name, the other visible text in a disclosure
     * widget. That is what dissolves the apparent collision with the
     * near-identical `$summary` default, which stays option-only
     * (markup-carve/carve#1510).
     */
    public function testTheDisclosureShapeIsNotNamed(): void
    {
        $converter = new CarveConverter(labels: ['tocNav' => 'Sentinel-tocNav']);
        $converter->addExtension(
            new TableOfContentsExtension(position: 'top', collapsible: true),
        );
        $html = $converter->convert(self::HEADINGS);

        $this->assertStringContainsString('<details class="toc">', $html);
        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringNotContainsString('Sentinel-tocNav', $html);
        $this->assertStringContainsString('<summary>Table of Contents</summary>', $html);
    }
}
