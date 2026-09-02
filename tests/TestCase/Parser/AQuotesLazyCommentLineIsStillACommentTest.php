<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment reaching a quote's open paragraph lazily is still a comment.
 *
 * PART 9 §10 I5, THE CLASSIFICATION HAS EXACTLY TWO EXCEPTIONS, first bullet:
 * "A COMMENT IS COLUMN-EXEMPT (A COMMENT IS THE ONE EXCEPTION, §24 C3). Below
 * a container's content column a comment is still invisible and still closes
 * the paragraph. The other four kinds are ordinary text there". So a comment is
 * the one invisible shape that survives the trip as itself, and the definition
 * control below is the other half of the same sentence.
 *
 * THE ORACLE HAS SINCE AGREED - the pins stay, the reason has moved. When they
 * were written the executable spec rendered `> x` over `%% c` as
 * `<blockquote><p>x\n%% c</p></blockquote>`, reading the line under PART 0 §4
 * alone, and only its QUOTE host lacked the exemption its item and
 * definition-description hosts already applied. That was filed as
 * markup-carve/carve#1899 and fixed by markup-carve/carve#1902: at
 * markup-carve/carve `caec9ff` the oracle answers that document
 * `<blockquote><p>x</p></blockquote>`, which is what the three engines always
 * answered.
 *
 * So these no longer pin an engine-versus-oracle divergence - there is none
 * left. Over a 4496-document sweep of container prefixes, payload shapes and
 * columns, all 562 documents carrying a `%% c` payload are byte-identical
 * across carve-js `c552d9f`, carve-rs `eb7091c` and this engine, and all 562
 * match the oracle at `caec9ff`. What they pin is the CLAUSE: §10 I5 names the
 * comment as the one column-exempt kind, and a reading that folds it back into
 * the paragraph is the shape that was wrong once and would be wrong again.
 */
class AQuotesLazyCommentLineIsStillACommentTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    /**
     * The whole of it: no list is involved, and the column does not enter into
     * it - the exemption is written without reference to one.
     *
     * @return array<string, array{0: string}>
     */
    public static function commentSpellingProvider(): array
    {
        return [
            'at document column 0' => ["> x\n%% c\n"],
            'at column 1' => ["> x\n %% c\n"],
            'at the quote content column' => ["> x\n  %% c\n"],
            'the fence spelling, unclosed' => ["> x\n%%% c\n"],
            'the fence spelling, closed' => ["> x\n%%% c\n%%%\n"],
        ];
    }

    #[DataProvider('commentSpellingProvider')]
    public function testTheCommentRendersNothingAndClosesTheParagraph(string $source): void
    {
        $this->assertSame('<blockquote><p>x</p></blockquote>', $this->html($source));
    }

    /**
     * The exemption's other half, and the reason this is not a rule about lazy
     * lines in general: every OTHER invisible kind IS ordinary text there. All
     * four readings agree on this one, the oracle included.
     */
    public function testADefinitionOnTheSameLineIsOrdinaryTextInstead(): void
    {
        $this->assertSame(
            '<blockquote><p>x [r]: /u</p></blockquote> <p>See [r][].</p>',
            $this->html("> x\n [r]: /u\n\nSee [r][].\n"),
        );
    }

    /**
     * Both controls agree everywhere, which is what isolates the disagreement
     * to the UNMARKED line under a quote.
     */
    public function testAtTheTopLevelACommentInterruptsTheParagraph(): void
    {
        $this->assertSame('<p>x</p>', $this->html("x\n%% c\n"));
    }

    public function testACommentCarryingItsOwnMarkerIsAComment(): void
    {
        $this->assertSame('<blockquote><p>x</p></blockquote>', $this->html("> x\n> %% c\n"));
    }

    /**
     * The comment closes the paragraph, so nothing after it lazily continues
     * the quote - a following unmarked line is a document paragraph and a
     * following marked line opens a second quote.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function afterTheCommentProvider(): array
    {
        return [
            'an unmarked line' => [
                "> x\n%% c\ny\n",
                '<blockquote><p>x</p></blockquote> <p>y</p>',
            ],
            'a marked line' => [
                "> x\n%% c\n> y\n",
                '<blockquote><p>x</p></blockquote> <blockquote><p>y</p></blockquote>',
            ],
            'a line past a blank' => [
                "> x\n%% c\n\ny\n",
                '<blockquote><p>x</p></blockquote> <p>y</p>',
            ],
        ];
    }

    #[DataProvider('afterTheCommentProvider')]
    public function testTheParagraphIsClosedForWhateverFollows(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The quote's body being a list changes nothing: the line supplies no `>`,
     * so it reaches the paragraph by the lazy fold whatever the body is.
     */
    public function testAQuotedListAnswersTheSameWay(): void
    {
        $this->assertSame(
            '<blockquote> <ul> <li>x</li> </ul> </blockquote>',
            $this->html("> - x\n%% c\n"),
        );
    }

    /**
     * The item and description hosts, pinned beside the quote so the exemption
     * is stated over containers rather than over quotes. All four readings
     * agree on both, at markup-carve/carve caec9ff.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function otherHostProvider(): array
    {
        return [
            'a list item' => ["- a\n %% c\n", '<ul> <li>a</li> </ul>'],
            'a definition description' => [":: t\n: d\n %% c\n", '<dl> <dt>t</dt> <dd>d</dd> </dl>'],
        ];
    }

    #[DataProvider('otherHostProvider')]
    public function testTheExemptionHoldsInTheOtherHostsToo(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * Rendering nothing is not the same as being gone. PART 9 §21 keeps a
     * comment's AST and source extent, so the line the reader does not see is
     * still addressable - and this node is structurally identical to the one
     * carve-js and carve-rs build for the same document, down to the offsets.
     */
    public function testTheCommentKeepsItsNodeAndItsSourceExtent(): void
    {
        $converter = new CarveConverter(parser: new BlockParser(trackPositions: true));
        $encoded = (new AstCodec())->encode($converter->parse("> x\n%% c\n"));

        /** @var array<int, array<string, mixed>> $children */
        $children = $encoded['children'];
        $this->assertSame(
            ['block_quote', 'comment'],
            array_map(static fn (array $child): string => (string)$child['type'], $children),
        );

        $comment = $children[1];
        $this->assertSame('c', $comment['content']);
        $this->assertFalse($comment['block']);
        $this->assertSame(
            ['startLine' => 2, 'endLine' => 2, 'startColumn' => 1, 'endColumn' => 5, 'startOffset' => 4, 'endOffset' => 8],
            $comment['pos'],
        );
    }
}
