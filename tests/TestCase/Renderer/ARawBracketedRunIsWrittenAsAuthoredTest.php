<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §2: a character is escaped IF AND ONLY IF omitting the escape would
 * change the re-parsed AST. A run the reader reads RAW cannot satisfy that: it
 * resolves no escape, so a backslash the writer adds is a backslash the reader
 * hands back as content (markup-carve/carve#1197, and
 * markup-carve/carve-js#1068 as the reference implementation).
 *
 * FIVE writers carried the same escape, not one. Every place that puts a value
 * between brackets a raw reader will pick up again: an image's alt text, an
 * admonition label, a div label, a code-fence label, and a footnote id in both
 * its definition and every reference to it. Only alt text had a corpus case
 * behind it; the other four grew one backslash per format pass in silence.
 *
 * Idempotence is asserted SEPARATELY rather than inferred from the round trip,
 * because a single `toHtml(fmt(x)) == toHtml(x)` pass is what the defect
 * survived where it was cheapest to catch: the first pass over a label whose
 * only special character is a backslash escapes it once, and the SECOND pass is
 * where the backslash starts eating itself.
 */
class ARawBracketedRunIsWrittenAsAuthoredTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function rawRunProvider(): array
    {
        return [
            'an alt text holding a balanced pair' => ["a ![t[z]](/i.png) b\n"],
            'an alt text holding a backslash' => ["a ![t\\]z](/i.png) b\n"],
            'an alt text holding a code span' => ["a ![t`]`z](/i.png) b\n"],
            'an alt text holding an editorial comment' => ["a ![t{# ] #}z](/i.png) b\n"],
            'an alt text in the reference form' => ["a ![t[z]][r] b\n\n[r]: /i.png\n"],
            'an admonition label holding a backslash' => ["::: note [a\\b]\nx\n:::\n"],
            'a div label holding a backslash' => ["::: [a\\b]\nx\n:::\n"],
            'a typed div label holding a backslash' => ["::: tip [a\\b]\nx\n:::\n"],
            'a code-fence label holding a backslash' => ["```php [a\\b]\nx\n```\n"],
            'a footnote id holding a backslash' => ["x [^a\\b]\n\n[^a\\b]: n\n"],
        ];
    }

    /**
     * The bytes are the authored bytes: the writer adds nothing.
     */
    #[DataProvider('rawRunProvider')]
    public function testTheRunIsWrittenBackVerbatim(string $source): void
    {
        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    /**
     * `fmt(fmt(x)) === fmt(x)`, stated on its own. The one-pass round trip below
     * held throughout this defect's life for four of the five sites.
     */
    #[DataProvider('rawRunProvider')]
    public function testTheWriterSettles(string $source): void
    {
        $once = CarveConverter::toCarve($source);
        $twice = CarveConverter::toCarve($once);

        $this->assertSame($once, $twice);
        $this->assertSame($twice, CarveConverter::toCarve($twice));
    }

    #[DataProvider('rawRunProvider')]
    public function testTheDocumentStillSaysTheSameThing(string $source): void
    {
        $this->assertSame(
            CarveConverter::create()->convert($source),
            CarveConverter::create()->convert(CarveConverter::toCarve($source)),
        );
    }

    /**
     * A container label and a code-fence label are not rendered the same way, so
     * read the VALUE back out of the tree: an HTML comparison alone would hold
     * however mangled a code-fence label got.
     *
     * @return array<string, array{string, string}>
     */
    public static function labelValueProvider(): array
    {
        return [
            'an admonition label' => ["::: note [a\\b]\nx\n:::\n", 'a\\b'],
            'a div label' => ["::: [a\\b]\nx\n:::\n", 'a\\b'],
            'a code-fence label' => ["```php [a\\b]\nx\n```\n", 'a\\b'],
        ];
    }

    #[DataProvider('labelValueProvider')]
    public function testTheLabelValueSurvivesTheFormatPass(string $source, string $label): void
    {
        $converter = CarveConverter::create();
        $before = $converter->parse($source);
        $after = CarveConverter::create()->parse(CarveConverter::toCarve($source));

        $this->assertSame($label, self::firstLabel($before->getChildren()));
        $this->assertSame($label, self::firstLabel($after->getChildren()));
    }

    public function testAFootnoteIdSurvivesTheFormatPass(): void
    {
        $source = "x [^a\\b]\n\n[^a\\b]: n\n";
        $formatted = CarveConverter::toCarve($source);

        $this->assertStringContainsString('[^a\\b]:', $formatted);
        $this->assertStringNotContainsString('[^a\\\\b]:', $formatted);
    }

    /**
     * CONTROL: an ABBREVIATION is not one of these runs and keeps its escape.
     * Its definition is read as `*[([A-Za-z0-9]+)]: `, so neither character can
     * reach it from a parse - a shared shape, not a shared rule - and only an
     * INGESTED tree can carry one. Asserted on an ingested node rather than on
     * source, because on source the escape has nothing to act on and the
     * assertion would hold whether the escape were there or not.
     */
    public function testAnAbbreviationDefinitionKeepsItsEscape(): void
    {
        $document = new Document();
        $document->appendChild(new AbbreviationDefinition('a]b', 'x'));

        $this->assertSame("*[a\\]b]: x\n", (new CarveRenderer())->render($document));
    }

    /**
     * And an abbreviation the parser CAN produce is untouched by either rule.
     */
    public function testAnAuthoredAbbreviationRoundTrips(): void
    {
        $source = "*[HTML]: HyperText Markup Language\n\nHTML\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    /**
     * CONTROL: a LINK's text, a SPAN's and an INLINE NOTE's are inline content,
     * not raw runs, and the asymmetry is visible in the rendered output. The
     * reader RESOLVES the escape there, so `\]` reaches HTML as a bare `]`;
     * inside an alt the same two characters both survive. That is the whole
     * reason one side is written verbatim and the other is not, so pin it where
     * the difference shows rather than only on the bytes the writer emits.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function inlineContentControlProvider(): array
    {
        return [
            'a link text' => [
                "a [t\\]z](/u) b\n",
                "a [t\\]z](/u) b\n",
                '<p>a <a href="/u">t]z</a> b</p>',
            ],
            'a span' => [
                "a [t\\]z]{.c} b\n",
                "a [t\\]z]{.c} b\n",
                '<p>a <span class="c">t]z</span> b</p>',
            ],
            'an inline note' => [
                "a ^[t\\]z] b\n",
                "a ^[t\\]z] b\n",
                '<p>a <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> b</p>',
            ],
        ];
    }

    #[DataProvider('inlineContentControlProvider')]
    public function testInlineContentStillCarriesItsEscape(string $source, string $expected, string $html): void
    {
        $this->assertSame($expected, CarveConverter::toCarve($source));
        $this->assertStringContainsString($html, CarveConverter::create()->convert($source));
        $this->assertSame(
            CarveConverter::create()->convert($source),
            CarveConverter::create()->convert(CarveConverter::toCarve($source)),
        );
    }

    /**
     * CONTROL, the other half of the same asymmetry: an alt text is RAW, so the
     * backslash the reader drops from a link text stays in the alt.
     */
    public function testTheAltKeepsTheBackslashTheLinkTextResolves(): void
    {
        $this->assertSame(
            "<p>a <img src=\"/i.png\" alt=\"t\\]z\"> b</p>\n",
            CarveConverter::create()->convert("a ![t\\]z](/i.png) b\n"),
        );
    }

    /**
     * An alt text with no Carve spelling at all keeps the escape. `parse` cannot
     * produce one - an unbalanced `]` never opened an image in the first place -
     * but an ingested AST can, and the escaped spelling is still an image where
     * the verbatim one is a paragraph of literal text. It settles, because the
     * escaped alt is itself representable.
     */
    public function testAnUnrepresentableAltKeepsTheEscape(): void
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Image('/i.png', 't]z'));
        $document = new Document();
        $document->appendChild($paragraph);

        $formatted = (new CarveRenderer())->render($document);

        $this->assertSame("![t\\]z](/i.png)\n", $formatted);
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    /**
     * The question the writer asks is the reader's, so pin it where it is
     * answered. A run ending inside an unclosed code span has no Carve spelling
     * with the escape or without it, and this is where that is decided.
     *
     * @return array<string, array{string, bool}>
     */
    public static function rawRunClosesProvider(): array
    {
        return [
            'plain' => ['tz', true],
            'a balanced pair' => ['t[z]', true],
            'nested two deep' => ['t[z[q]]', true],
            'an escaped closer' => ['t\\]z', true],
            'a closer inside a code span' => ['t`]`z', true],
            'a closer inside an editorial comment' => ['t{# ] #}z', true],
            'a bare closer' => ['t]z', false],
            'a bare opener' => ['t[z', false],
            'an unclosed code span' => ['t`z', false],
        ];
    }

    #[DataProvider('rawRunClosesProvider')]
    public function testTheWriterAsksTheReaderWhetherTheRunCloses(string $run, bool $closes): void
    {
        $this->assertSame($closes, BracketScanner::rawRunCloses($run));
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected static function firstLabel(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if (method_exists($node, 'getLabel')) {
                $label = $node->getLabel();
                if (is_string($label)) {
                    return $label;
                }
            }
            if (method_exists($node, 'getChildren')) {
                $found = self::firstLabel($node->getChildren());
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
