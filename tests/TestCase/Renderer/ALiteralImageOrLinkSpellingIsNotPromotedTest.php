<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text that SPELLS an image or a link is written so it reads back as text.
 *
 * Two shapes reached the writer bare and came back as live markup
 * (markup-carve/carve-php#2634). A `!` ending a text node bound to the `[` the
 * next node wrote, so `!` beside a link became an image; and a `(` after a
 * paired `]` was excused whenever the destination was wrapped in angle
 * brackets, so literal `[link](<foo>)` stayed bare and a conforming reader
 * takes it as a link.
 *
 * Both are PART 11 §2: escape a character exactly where omitting it would
 * change the re-parse, decided on the bytes the EMITTED LINE carries rather
 * than on the node's own text (§8d states that reach for the Markdown target,
 * and §8f the `!` case there). The destination half is §5's unconditional
 * `(`, read against `link_destination` from PART 3, which admits any character
 * but `(`, `)` and whitespace.
 */
class ALiteralImageOrLinkSpellingIsNotPromotedTest extends TestCase
{
    /**
     * The third column is what the written source reads back as, where the
     * BLOCK spelling differs from the imported fragment: a heading carries its
     * section and a lone image is a block image.
     *
     * @return array<string, array{0: string, 1: string, 2?: string}>
     */
    public static function importProvider(): array
    {
        return [
            // The two rows the sweep over the CommonMark outputs reported.
            'a bang before a link' => [
                '<p>!<a href="/url" title="title">foo</a></p>',
                "\\![foo](/url \"title\")\n",
            ],
            'an angle-bracketed destination' => [
                '<p>[link](&lt;foo&gt;)</p>',
                "[link]\\(<foo>)\n",
            ],
            'a bang between words' => [
                '<p>a!<a href="/u">t</a>b</p>',
                "a\\![t](/u)b\n",
            ],
            'a bang inside emphasis' => [
                '<p><em>a!<a href="/u">t</a></em></p>',
                "/a\\![t](/u)/\n",
            ],
            'a bang already behind a literal backslash' => [
                '<p>\\!<a href="/u">t</a></p>',
                "\\\\\\![t](/u)\n",
            ],
            'a bang in a heading' => [
                '<h1>!<a href="/u">t</a></h1>',
                "# \\![t](/u)\n",
                "<section id=\"t\">\n  <h1>!<a href=\"/u\">t</a></h1>\n</section>",
            ],
            // Over-escaping controls: a character that opens nothing here.
            'a bang that ends a sentence' => ['<p>Wow! Yes.</p>', "Wow! Yes.\n"],
            'a bang before a space and a link' => ['<p>hello! <a href="/u">t</a></p>', "hello! [t](/u)\n"],
            'a bang before emphasis' => ['<p>Wow!<em>yes</em></p>', "Wow!/yes/\n"],
            'a bang before a span, which is no image' => [
                '<p>x!<span class="k">n</span></p>',
                "x![n]{.k}\n",
            ],
            'a genuine image' => ['<p><img src="/u" alt="a"></p>', "![a](/u)\n", '<img src="/u" alt="a">'],
            'a genuine link' => ['<p><a href="/u">t</a></p>', "[t](/u)\n"],
            'a paren that opens no destination' => [
                '<p>f(x) and (see above) and [a] (b) and [a](b c)</p>',
                "f(x) and (see above) and [a] (b) and [a](b c)\n",
            ],
            'a colon and a paren that need no escape at all' => [
                '<p>50% faster: yes (ok)</p>',
                "50% faster: yes (ok)\n",
            ],
        ];
    }

    #[DataProvider('importProvider')]
    public function testTheImportWritesTheLiteralSpelling(string $html, string $carve, ?string $readBack = null): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    #[DataProvider('importProvider')]
    public function testTheWrittenSourceReadsBackAsTheHtml(string $html, string $carve, ?string $readBack = null): void
    {
        $this->assertSame($readBack ?? $html, trim(CarveConverter::create()->convert($carve)));
    }

    /**
     * @return array<string, array{0: \Closure(): array<\MarkupCarve\Carve\Node\Node>, 1: string, 2: string}>
     */
    public static function treeProvider(): array
    {
        return [
            'a bang before an inline link' => [
                fn (): array => [new Text('x!'), self::link('n')],
                "x\\![n](u)\n",
                '<p>x!<a href="u">n</a></p>',
            ],
            'control: a bang before a span writes no escape' => [
                fn (): array => [new Text('x!'), self::span('n')],
                "x![n]{.k}\n",
                '<p>x!<span class="k">n</span></p>',
            ],
        ];
    }

    /**
     * @param \Closure(): array<\MarkupCarve\Carve\Node\Node> $inlines
     * @param string $carve
     * @param string $readBack
     */
    #[DataProvider('treeProvider')]
    public function testTheTreeIsWritten(Closure $inlines, string $carve, string $readBack): void
    {
        $this->assertSame($carve, (new CarveRenderer())->render(self::document($inlines())));
    }

    /**
     * @param \Closure(): array<\MarkupCarve\Carve\Node\Node> $inlines
     * @param string $carve
     * @param string $readBack
     */
    #[DataProvider('treeProvider')]
    public function testTheWrittenSourceReadsBackAsTheTree(Closure $inlines, string $carve, string $readBack): void
    {
        $written = (new CarveRenderer())->render(self::document($inlines()));

        $this->assertSame($readBack, trim(CarveConverter::create()->convert($written)));
    }

    /**
     * A FULL reference is a tail too: `![n][r]` is an image wherever `[r]`
     * resolves, so the `!` before it takes the escape.
     */
    public function testABangBeforeAFullReferenceIsEscaped(): void
    {
        $document = self::document([new Text('x!'), self::reference('n', 'r')]);
        $document->appendChild(new LinkReferenceDefinition('r', '/u'));

        $written = (new CarveRenderer())->render($document);

        $this->assertSame("x\\![n][r]\n\n[r]: /u\n", $written);
        $this->assertSame(
            '<p>x!<a href="/u">n</a></p>',
            trim(CarveConverter::create()->convert($written)),
        );
    }

    /**
     * A SHORTCUT reference writes the label alone, so nothing follows its `]`,
     * no image tail exists and `![r]` stays a literal `!` beside the link.
     */
    public function testABangBeforeAShortcutReferenceStaysBare(): void
    {
        $source = "x![r]\n\n[r]: /u\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
        $this->assertSame(
            '<p>x![r]</p>',
            trim(CarveConverter::create()->convert($source)),
        );
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $inlines
     */
    private static function document(array $inlines): Document
    {
        $paragraph = new Paragraph();
        foreach ($inlines as $inline) {
            $paragraph->appendChild($inline);
        }
        $document = new Document();
        $document->appendChild($paragraph);

        return $document;
    }

    private static function link(string $label): Link
    {
        $link = new Link('u');
        $link->appendChild(new Text($label));

        return $link;
    }

    private static function reference(string $label, string $reference): Link
    {
        $link = self::link($label);
        $link->setReferenceLabel($reference);

        return $link;
    }

    private static function span(string $label): Node
    {
        $span = new Span();
        $span->setAttribute('class', 'k');
        $span->appendChild(new Text($label));

        return $span;
    }
}
