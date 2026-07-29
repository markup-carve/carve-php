<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CommonMark does not honour an intraword underscore, so escaping one protects
 * nothing and only litters identifiers in output meant to be read and searched.
 * An asterisk is not symmetric here - `a*b*c` does emphasise - so `*` stays
 * escaped everywhere.
 */
class MarkdownUnderscoreEscapeTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function identifierProvider(): array
    {
        return [
            'single underscore' => ['company_id'],
            'several underscores' => ['a_b_c'],
            'snake case' => ['snake_case_name'],
            'longer identifier' => ['read_write_delete'],
        ];
    }

    #[DataProvider('identifierProvider')]
    public function testAnIntrawordUnderscoreIsLeftBare(string $identifier): void
    {
        $this->assertSame($identifier, trim(CarveConverter::markdown()->convert($identifier)));
    }

    public function testAnUnderscoreThatCouldOpenOrCloseEmphasisStaysEscaped(): void
    {
        $this->assertSame('trailing\_', trim(CarveConverter::markdown()->convert('trailing_')));
        $this->assertSame('\_leading', trim(CarveConverter::markdown()->convert('_leading')));
    }

    public function testAnAsteriskBetweenWordCharactersStaysEscaped(): void
    {
        // `a*b*c` emphasises in CommonMark, unlike the underscore form.
        $this->assertSame('a\*b\*c', trim(CarveConverter::markdown()->convert('a*b*c')));
    }

    public function testCodeSpansAreUntouched(): void
    {
        $this->assertSame('`code_span`', trim(CarveConverter::markdown()->convert('`code_span`')));
    }

    /**
     * A backslash the author typed is content, not an escape this renderer
     * added. The de-escaping used to run over the assembled document, where it
     * could not tell the two apart, and rewrote verbatim regions that carry a
     * literal backslash before an underscore (carve-js issue 400).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function verbatimProvider(): array
    {
        return [
            'code span' => ['`a\_b`', '`a\_b`'],
            'code block' => ["```\ncompany\\_id\n```", "```\ncompany\\_id\n```"],
            'link destination' => ['[x](a\_b)', '[x](a\_b)'],
            'image source' => ['![a](x\_y)', '![a](x\_y)'],
            'raw html' => ["```=html\n<i>a\\_b</i>\n```", '&lt;i&gt;a\_b&lt;/i&gt;'],
        ];
    }

    #[DataProvider('verbatimProvider')]
    public function testABackslashTheRendererDidNotWriteIsKept(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::markdown()->convert($source)));
    }

    public function testAnAuthoredEscapeIsDeEscapedWhenIntraword(): void
    {
        // `a\_b` and `a_b` are two spellings of the same document, so they have
        // to render the same - the escape the author wrote is still an escape.
        $this->assertSame('a_b', trim(CarveConverter::markdown()->convert('a\_b')));
    }

    public function testUnderlineEmphasisStillRenders(): void
    {
        $this->assertSame('<u>underline</u>', trim(CarveConverter::markdown()->convert('_underline_')));
    }

    public function testAnIdentifierBesideRealEmphasis(): void
    {
        $this->assertSame(
            'company_id and **strong**',
            trim(CarveConverter::markdown()->convert('company_id and *strong*')),
        );
    }
}
