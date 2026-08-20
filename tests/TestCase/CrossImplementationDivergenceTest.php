<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use PHPUnit\Framework\TestCase;

class CrossImplementationDivergenceTest extends TestCase
{
    public function testSpaceBeforeDroppedRawSpanIsKept(): void
    {
        $converter = new CarveConverter();

        // a17: a raw-format span for another output format renders to nothing,
        // but the space before it is interior in the source - carve-js and
        // carve-rs keep it. Plain line-end whitespace stays trimmed (corpus
        // case 102).
        $this->assertSame("<p>foo </p>\n", $converter->convert('foo `x`{=latex}'));
        $this->assertSame("<p> bar</p>\n", $converter->convert('`x`{=latex} bar'));
        $this->assertSame("<p>abc</p>\n", $converter->convert("abc \n"));
    }

    public function testConstructProducedSpacesSurviveTheTrailingWhitespaceStrip(): void
    {
        $converter = new CarveConverter();

        // The paragraph trailing-whitespace strip (corpus 102) is a SOURCE-level
        // rule: it removes whitespace the author typed at the end of the final
        // line, not spaces a construct legitimately produced. Trimming rendered
        // output could not tell the two apart and emptied a paragraph whose only
        // content is an all-space verbatim span. carve-js and carve-rs both keep
        // those spaces; these pin the parity.
        $this->assertSame("<p>  </p>\n", $converter->convert('!`  `'));
        $this->assertSame("<p> </p>\n", $converter->convert('!` `'));
        $this->assertSame("<p><code>  </code></p>\n", $converter->convert('`  `'));

        // ... while authored trailing whitespace is still stripped, including
        // when it follows a construct.
        $this->assertSame("<p>abc</p>\n", $converter->convert("abc \t \n"));
        $this->assertSame("<p><code>x</code></p>\n", $converter->convert("`x`  \n"));

        // A trailing NBSP is content everywhere in Carve and must survive.
        $this->assertSame("<p>abc&nbsp;</p>\n", $converter->convert("abc\u{00A0}\n"));
    }

    public function testSeparatorShapedRowIsNeverPromotedToHeader(): void
    {
        $converter = new CarveConverter();

        // A first row that itself matches the separator shape (|:-:|) must
        // not be promoted to a header by a following separator line - both
        // stay ordinary data rows (carve-js / carve-rs behavior).
        $this->assertSame(
            "<table>\n"
            . "  <tbody>\n"
            . "    <tr><td>:-:</td></tr>\n"
            . "    <tr><td>:-:</td></tr>\n"
            . "    <tr><td>x</td></tr>\n"
            . "  </tbody>\n"
            . "</table>\n",
            $converter->convert("|:-:|\n|:-:|\n|x|"),
        );

        // Normal header promotion is unaffected.
        $this->assertStringContainsString(
            "<thead>\n    <tr><th scope=\"col\" style=\"text-align: center;\">h</th></tr>\n  </thead>",
            $converter->convert("| h |\n|:-:|\n| b |"),
        );
    }

    public function testCollapsedReferenceFallsBackToHeadingCaseInsensitively(): void
    {
        $this->assertSame(
            "<p>See <a href=\"#Name\">name</a></p>\n"
            . "<section id=\"Name\">\n"
            . "  <h1>Name</h1>\n"
            . "</section>\n",
            (new CarveConverter())->convert("See [name][]\n\n# Name"),
        );

        $this->assertStringContainsString(
            '<a href="#name">NAME</a>',
            (new CarveConverter())->convert("See [NAME][]\n\n# name"),
        );
    }

    public function testCollapsedReferenceFindsMarkerLineListItemHeading(): void
    {
        $this->assertSame(
            "<p>See <a href=\"#In-an-item\">In an item</a>.</p>\n"
            . "<ul>\n"
            . "  <li>\n"
            . "    <h1 id=\"In-an-item\">In an item</h1>\n"
            . "  </li>\n"
            . "</ul>\n",
            (new CarveConverter())->convert("See [In an item][].\n\n- # In an item"),
        );
    }

    public function testCollapsedReferenceFindsIndentedListItemHeading(): void
    {
        $this->assertSame(
            "<p>See <a href=\"#Indented\">Indented</a>.</p>\n"
            . "<ul>\n"
            . "  <li>item\n"
            . "    <h1 id=\"Indented\">Indented</h1>\n"
            . "  </li>\n"
            . "</ul>\n",
            (new CarveConverter())->convert("See [Indented][].\n\n- item\n  # Indented"),
        );
    }

    public function testCollapsedReferenceDeclinesFencedHeadingText(): void
    {
        $this->assertSame(
            "<p>See [Fenced][].</p>\n"
            . "<pre><code># Fenced\n"
            . "</code></pre>\n",
            (new CarveConverter())->convert("See [Fenced][].\n\n```\n# Fenced\n```"),
        );
    }

    public function testCollapsedReferenceDeclinesBlockquotedHeading(): void
    {
        $this->assertSame(
            "<p>See [Quoted][].</p>\n"
            . "<blockquote>\n"
            . "  <h1 id=\"Quoted\">Quoted</h1>\n"
            . "</blockquote>\n",
            (new CarveConverter())->convert("See [Quoted][].\n\n> # Quoted"),
        );
    }

    public function testCollapsedReferenceDeclinesListThenBlockquoteHeading(): void
    {
        $this->assertSame(
            "<p>See [Q][].</p>\n"
            . "<ul>\n"
            . "  <li>\n"
            . "    <blockquote>\n"
            . "      <h1 id=\"Q\">Q</h1>\n"
            . "    </blockquote>\n"
            . "  </li>\n"
            . "</ul>\n",
            (new CarveConverter())->convert("See [Q][].\n\n- > # Q"),
        );
    }

    public function testCollapsedReferenceDeclinesBlockquoteThenListHeading(): void
    {
        $this->assertSame(
            "<p>See [Q][].</p>\n"
            . "<blockquote>\n"
            . "  <ul>\n"
            . "    <li>\n"
            . "      <h1 id=\"Q\">Q</h1>\n"
            . "    </li>\n"
            . "  </ul>\n"
            . "</blockquote>\n",
            (new CarveConverter())->convert("See [Q][].\n\n> - # Q"),
        );
    }

    public function testCollapsedReferenceStillFindsTopLevelAndDivHeadings(): void
    {
        $this->assertSame(
            "<p>See <a href=\"#Top\">Top</a> and <a href=\"#Inside\">Inside</a>.</p>\n"
            . "<section id=\"Top\">\n"
            . "  <h1>Top</h1>\n"
            . "  <aside class=\"admonition note\">\n"
            . "    <h1 id=\"Inside\">Inside</h1>\n"
            . "  </aside>\n"
            . "</section>\n",
            (new CarveConverter())->convert("See [Top][] and [Inside][].\n\n# Top\n\n::: note\n# Inside\n:::"),
        );
    }

    public function testCollapsedHeadingReferenceRendersAsResolvedLinkInNonHtmlFormats(): void
    {
        $source = "See [name][]\n\n# Name";

        $this->assertSame("See [name](#Name)\n\n# Name {#Name}\n", CarveConverter::markdown()->convert($source));
        $this->assertSame("See name\n\nName\n", CarveConverter::plainText()->convert($source));

        $ansi = CarveConverter::ansi()->convert($source);
        $this->assertStringContainsString('name', $this->stripSgr($ansi));
        $this->assertStringNotContainsString(' (#Name)', $this->stripSgr($ansi));
        $this->assertStringNotContainsString('[name][]', $ansi);
    }

    public function testAnsiHeadingUnderlineUsesVisibleDisplayWidth(): void
    {
        $document = (new BlockParser())->parse('# H *em*');
        $ansi = (new AnsiRenderer(useColors: true))->render($document);
        $plain = $this->stripSgr($ansi);
        $lines = explode("\n", trim($plain));

        $this->assertSame('H em', $lines[0]);
        $this->assertSame('════', $lines[1]);
    }

    public function testHeaderRowspanIsRaggedInTextAndPaddedInAnsi(): void
    {
        $source = "|=A|\n|^|x|";

        $this->assertSame("| A |\n| --- |\n|  | x |\n", CarveConverter::markdown()->convert($source));
        $this->assertSame("A\n | x\n", CarveConverter::plainText()->convert($source));

        $ansi = $this->stripSgr(CarveConverter::ansi()->convert($source));
        $this->assertStringContainsString('│ A │   │', $ansi);
        $this->assertStringContainsString('│   │ x │', $ansi);
    }

    private function stripSgr(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }
}
