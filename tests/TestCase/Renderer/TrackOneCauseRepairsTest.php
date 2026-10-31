<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrackOneCauseRepairsTest extends TestCase
{
    #[DataProvider('indentedFrontmatterDelimiterProvider')]
    public function testAnIndentedDashLineNeitherOpensNorClosesFrontmatter(string $source): void
    {
        $this->assertSame(
            "<hr>\n<p>a</p>\n<hr>\n<p>x</p>",
            trim(CarveConverter::create()->convert($source)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function indentedFrontmatterDelimiterProvider(): iterable
    {
        yield 'indented opener' => [" ---\na\n---\nx\n"];
        yield 'tab-indented opener' => ["\t---\na\n---\nx\n"];
        yield 'indented closer' => ["---\na\n ---\nx\n"];
    }

    public function testUnindentedFrontmatterIsStillCaptured(): void
    {
        $this->assertSame('<p>x</p>', trim(CarveConverter::create()->convert("---\na\n---\nx\n")));
    }

    public function testACrossReferenceToAnEmptyCaptionLabelIsTheNumberAlone(): void
    {
        $html = CarveConverter::create()->convert("{#t}\n| a |\n^ # x\n\n</#t>\n");

        $this->assertStringContainsString('<p><a href="#t">1</a></p>', $html);
    }

    public function testACrossReferenceToALabeledCaptionKeepsTheSeparator(): void
    {
        $html = CarveConverter::create()->convert("{#t}\n| a |\n^ Table # x\n\n</#t>\n");

        $this->assertStringContainsString('<p><a href="#t">Table 1</a></p>', $html);
    }

    public function testADuplicateExplicitIdResolvesToTheFirstHeading(): void
    {
        $html = CarveConverter::create()->convert("{#x}\n# A\n\n{#x}\n# B\n\n</#x>\n");

        $this->assertStringContainsString('<p><a href="#x">A</a></p>', $html);
    }

    #[DataProvider('literalCodeIncludeProvider')]
    public function testAnUnresolvedOrMalformedCodeIncludeIsLiteral(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literalCodeIncludeProvider(): iterable
    {
        yield 'malformed language inside a paragraph' => [
            "x {{ a @code:a,b }} y\n",
            '<p>x {{ a @code:a,b }} y</p>',
        ];

        yield 'malformed language on its own line' => [
            "{{ a @code:a!b }}\n",
            '<p>{{ a @code:a!b }}</p>',
        ];

        yield 'standalone line in a quote' => [
            "> {{ _a_ @code:js }}\n",
            '<blockquote><p>{{ _a_ @code:js }}</p></blockquote>',
        ];

        yield 'standalone line in a list item' => [
            "- {{ _a_ @code:js }}\n",
            "<ul>\n  <li>{{ _a_ @code:js }}</li>\n</ul>",
        ];
    }

    public function testAStandaloneQuotedPathKeepsCoreInlineSyntax(): void
    {
        $html = CarveConverter::create()->convert("> {{ \"_a_\" @code:js }}\n");

        $this->assertStringContainsString('<u>a</u>', $html);
    }
}
