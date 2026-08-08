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

    /**
     * PART 11 section 8a M1b is an IF AND ONLY IF, not a floor: a lone underscore
     * is not adjacent to another one, so its escape protects nothing under any
     * reader this target answers to and it is emitted bare.
     *
     * This case used to assert the opposite, from the older intraword-only rule.
     * A run of two is the shape that still keeps its escapes, and it is asserted
     * here so the narrowing is bounded rather than open.
     */
    public function testALoneUnderscoreIsBareAndARunKeepsItsEscapes(): void
    {
        $this->assertSame('trailing_', trim(CarveConverter::markdown()->convert('trailing_')));
        $this->assertSame('_leading', trim(CarveConverter::markdown()->convert('_leading')));
        $this->assertSame('a \_\_b', trim(CarveConverter::markdown()->convert('a __b')));
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

    /**
     * PART 11 section 8 M2: an `escaped_text` node is emitted AS AN ESCAPE,
     * whatever the character. section 8a is explicit that M1 - and therefore the
     * line test - governs a character that reached the writer inside a TEXT
     * node, which this is not: the author said which reading they meant.
     *
     * So `a\_b` and `a_b` are NOT two spellings of one document on this target
     * any more, and this case asserts the pair rather than their sameness. The
     * underscore used to run through the sentinel here and lose its backslash to
     * the intraword rule, which was the line test deciding a node M1 never
     * governed.
     */
    public function testAnAuthoredEscapeIsKeptAsAnEscape(): void
    {
        $this->assertSame('a\_b', trim(CarveConverter::markdown()->convert('a\_b')));
        $this->assertSame('a_b', trim(CarveConverter::markdown()->convert('a_b')));
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
