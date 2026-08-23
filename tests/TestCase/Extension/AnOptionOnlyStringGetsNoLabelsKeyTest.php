<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\HeadingPermalinksExtension;
use MarkupCarve\Carve\Extension\IndexExtension;
use MarkupCarve\Carve\Extension\TableOfContentsExtension;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE OTHER HALF OF THE ADMISSION RULE (markup-carve/carve#1510, ruled in
 * markup-carve/carve#1520).
 *
 * The rest of the label suite checks that a DOCUMENTED key reaches the output.
 * This checks the opposite direction. Extensions §1.5 used to say every
 * extension-written string with a fixed English default has a key in the
 * render's `labels` map, and two strings satisfied that sentence with no key:
 * the heading-permalink label, default `Permalink`, and the table-of-contents
 * summary, default `Table of Contents` and visible whenever `$collapsible` is
 * on. §1.5 was narrowed rather than the map grown - a string the extension
 * already exposes as an OPTION is configured there, and it does not get both
 * spellings - and PART 9 §16a's note recording the question as open became
 * that rule.
 *
 * ASSERTING THE ABSENCE ALONE CANNOT FAIL FOR THE RIGHT REASON. A key nothing
 * implements is inert whether the rule is honored or the string was simply
 * forgotten. So each row asserts three things: the documented default renders,
 * the map key changes NOTHING, and the extension option DOES reach the output.
 * Only the third separates "configured elsewhere" from "not configurable at
 * all", which is the state §1.5 says a string must not be in.
 */
class AnOptionOnlyStringGetsNoLabelsKeyTest extends TestCase
{
    /**
     * @var string
     */
    protected const SOURCE = "# One\n\nbody\n";

    /**
     * The rows: the `labels` name the rule refuses, and how to probe it.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function optionOnlyStrings(): array
    {
        return [
            'the heading permalink label' => ['headingPermalink', 'ariaLabel', 'Permalink'],
            'the table-of-contents summary' => ['tocSummary', 'summary', 'Table of Contents'],
        ];
    }

    /**
     * Build the extension that writes the string, with its own option set (or not).
     *
     * @param string $key The `labels` name the rule refuses.
     * @param array<string, string> $options The extension's own option, keyed by its name.
     */
    protected function extensionFor(string $key, array $options = []): HeadingPermalinksExtension|TableOfContentsExtension
    {
        if ($key === 'headingPermalink') {
            return new HeadingPermalinksExtension(...$options);
        }

        // `position` because the disclosure has to be in the output to be read,
        // and `collapsible` because the summary is what carries the string.
        return new TableOfContentsExtension(...$options + ['position' => 'top', 'collapsible' => true]);
    }

    /**
     * The string as it reaches the rendered document, or null if the probe found nothing.
     *
     * @param string $key The `labels` name the rule refuses.
     * @param array<string, string> $options The extension's own option, keyed by its name.
     * @param array<string, string> $labels The render's `labels` map.
     */
    protected function rendered(string $key, array $options = [], array $labels = []): ?string
    {
        $converter = new CarveConverter(labels: $labels);
        $converter->addExtension($this->extensionFor($key, $options));
        $html = $converter->convert(self::SOURCE);

        $pattern = $key === 'headingPermalink'
            ? '/class="permalink" aria-label="([^"]*)"/'
            : '#<summary>([^<]*)</summary>#';

        return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
    }

    /**
     * Without this the two assertions below could both hold on a probe that
     * finds nothing at all in either render.
     */
    #[DataProvider('optionOnlyStrings')]
    public function testTheDocumentedEnglishDefaultRenders(string $key, string $option, string $default): void
    {
        $this->assertSame($default, $this->rendered($key));
    }

    #[DataProvider('optionOnlyStrings')]
    public function testTheLabelsMapDoesNotReachIt(string $key, string $option, string $default): void
    {
        $this->assertSame($default, $this->rendered($key, [], [$key => 'Sentinel-' . $key]));
    }

    #[DataProvider('optionOnlyStrings')]
    public function testTheExtensionOptionDoesReachIt(string $key, string $option, string $default): void
    {
        $this->assertSame('Option-' . $key, $this->rendered($key, [$option => 'Option-' . $key]));
    }

    /**
     * The assertion that goes red if someone later adds the key the rule
     * refuses. `HtmlRenderer::LABEL_DEFAULTS` is this engine's whole `labels`
     * vocabulary, so a name absent from it has no key at all.
     */
    #[DataProvider('optionOnlyStrings')]
    public function testNeitherNameIsInTheLabelsVocabulary(string $key, string $option, string $default): void
    {
        $this->assertArrayNotHasKey($key, HtmlRenderer::LABEL_DEFAULTS);
    }

    /**
     * The control: a name the rule DOES admit behaves the other way round, so
     * the three assertions above are measuring the rule and not the probe.
     */
    public function testARealKeyIsReadFromTheMap(): void
    {
        $converter = new CarveConverter(labels: ['indexBackref' => 'Zurück zu']);
        $converter->addExtension(new IndexExtension());
        $html = $converter->convert("A :index[widget] here.\n\n::: index\n:::\n");

        $this->assertStringContainsString('aria-label="Zurück zu widget"', $html);
        $this->assertArrayHasKey('indexBackref', HtmlRenderer::LABEL_DEFAULTS);
    }
}
