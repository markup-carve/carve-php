<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function json_encode;
use function rtrim;
use function str_contains;
use function var_export;

/**
 * A HALF pair closes into nothing, so its caret opens no construct.
 *
 * The reader refuses a `{^` opener outright when no `^}` lies at or after the
 * content start, so `{^x`, `x^}`, `{^`, `^}` and `{^}` are text however they
 * are written. The writer escaped them anyway, and PART 11 §2 escapes a
 * character IF AND ONLY IF omitting the escape would change the re-parsed AST -
 * it does not here. What the escape did change is the tree: `{^x` is ONE text
 * node where `{\^x` is text plus an `escaped_text` node plus text, which is
 * exactly the difference §1 calls the same document
 * (markup-carve/carve-php#1522).
 *
 * markup-carve/carve-php#1520 fixed the doubled-caret pair `{^^}` and said not
 * to over-correct into the neighbouring shapes, because the rest of the family
 * was open across engines at the time. Two of them - `}^p` and `[^` - now write
 * bare, so what is left is the half-pair list here, and carve-js writes every
 * one of these bare byte for byte (measured at `d47a5795`).
 *
 * THE ESCAPE IS NOT REMOVED, IT IS NARROWED. Where the pair does complete
 * inside one text run the escape is still written, and the near-miss set below
 * pins the shapes a blunter correction would take with it - an authored `\^`
 * survives whatever sits beside it, which regressed once already
 * (markup-carve/carve#374).
 */
class AHalfFormedBracedPairKeepsItsCaretsBareTest extends TestCase
{
    /**
     * Half pairs: an opener with no closer, or a closer with no opener.
     *
     * The first six are the ticket's own list. The rest are the same rule in
     * the other positions a caret can take, so a fix that happened to suit six
     * strings does not read as a fix of the rule.
     *
     * @return array<string, array{0: string}>
     */
    public static function halfPairProvider(): array
    {
        return [
            'opener with no closer' => ['{^x'],
            'closer with no opener' => ['x^}'],
            'a bare opener' => ['{^'],
            'a bare closer' => ['^}'],
            'brace, caret, brace' => ['{^}'],
            'a real pair inside a half one' => ['{^{^x^}^}'],
            'opener hugged by text' => ['x{^y'],
            'closer hugged by text' => ['a^}b'],
            'opener after a word' => ['a{^b'],
            'a space where the content would be' => ['{^ }'],
            'a pair then a dangling opener' => ['{^x^}y{^z'],
            'the brace and the caret not adjacent' => ['{ ^x^}'],
            'a closer before an opener' => ['^}{^'],
        ];
    }

    /**
     * The shapes a blunter correction would unescape too.
     *
     * Every one carries an escape the AUTHOR wrote, or sits where the caret
     * genuinely reads. `\^ ` at the start of a block line is a caption marker
     * suppressed; `\^[` is an inline note suppressed; the rest are prose.
     *
     * @return array<string, array{0: string}>
     */
    public static function nearMissProvider(): array
    {
        return [
            'mid-prose' => ['A \^ caret'],
            'mid-prose beside a half pair' => ['a \^ b {^c'],
            'hugged by letters' => ['a\^b'],
            'leading' => ['\^leading'],
            'inside a half pair' => ['{\^x'],
            'before a note bracket' => ['\^[not a note]'],
            'a caption marker suppressed' => ['\^ not a caption'],
        ];
    }

    /**
     * Pairs that DO complete, which the writer still has to keep apart.
     *
     * @return array<string, array{0: string}>
     */
    public static function completePairProvider(): array
    {
        return [
            'one letter' => ['{^x^}'],
            'hugged by text' => ['a{^b^}c'],
            'a space inside' => ['{^x ^}'],
            'two in a row' => ['{^a^}{^b^}'],
            'inside another brace' => ['{{^x^}}'],
            'the empty pair' => ['{^^}'],
        ];
    }

    /**
     * The encoded inline shape of a document, without its byte length.
     *
     * `srcByteLength` counts the source, so it moves whenever an escape is
     * added - which would make the comparison fail for the wrong reason and say
     * nothing about the document.
     */
    private static function shape(string $source): string
    {
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse($source));

        return (string)json_encode($encoded['children'] ?? []);
    }

    #[DataProvider('halfPairProvider')]
    public function testAHalfFormedPairIsWrittenWithBareCarets(string $source): void
    {
        $this->assertSame(
            $source,
            rtrim(CarveConverter::toCarve($source)),
            'the writer invented a caret escape for ' . var_export($source, true),
        );
    }

    /**
     * The property the corpus formatter test cannot see.
     *
     * `toHtml(fmt(x)) == toHtml(x)` held on every one of these throughout the
     * defect, so it is not evidence. `parse(fmt(x)) == parse(x)` is what broke.
     */
    #[DataProvider('halfPairProvider')]
    public function testAHalfFormedPairSurvivesTheWriterAsTheSameDocument(string $source): void
    {
        $this->assertSame(
            self::shape($source),
            self::shape(CarveConverter::toCarve($source)),
            'formatting changed the document for ' . var_export($source, true),
        );
    }

    #[DataProvider('nearMissProvider')]
    public function testAnAuthoredCaretEscapeIsReproducedVerbatim(string $source): void
    {
        $this->assertSame(
            $source,
            rtrim(CarveConverter::toCarve($source)),
            'the writer dropped an authored caret escape in ' . var_export($source, true),
        );
    }

    #[DataProvider('completePairProvider')]
    public function testACompletePairIsWrittenAsAuthored(string $source): void
    {
        $this->assertSame(
            $source,
            rtrim(CarveConverter::toCarve($source)),
            'the writer rewrote a complete pair in ' . var_export($source, true),
        );
    }

    /**
     * THE HALF THAT IS LOAD-BEARING, and the reason the guard is narrowed
     * rather than deleted.
     *
     * A parsed document can never hold `{^x^}` inside a text node - the reader
     * would have made it a superscript - but an INGESTED one can, and PART 11
     * §1's invariant is over any document. Written bare that text would come
     * back as a superscript, so the opener keeps its escape and the run comes
     * back as text.
     *
     * The second row is why the check is per occurrence: escaping only the
     * first opener would free the second to form the pair the first one's
     * escape just released.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ingestedTextProvider(): array
    {
        return [
            'a complete pair in one text node' => ['{^x^}', '{\^x^}'],
            'two openers, one closer' => ['{^a{^b^}', '{\^a{\^b^}'],
            'a pair hugged by text' => ['a{^b^}c', 'a{\^b^}c'],
            'a half pair needs nothing' => ['{^x', '{^x'],
            'a lone closer needs nothing' => ['x^}', 'x^}'],
            'the empty pair needs nothing' => ['{^^}', '{^^}'],
        ];
    }

    #[DataProvider('ingestedTextProvider')]
    public function testAnIngestedTextNodeKeepsTheEscapeThatIsLoadBearing(string $content, string $expected): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->setChildren([new Text($content)]);
        $document->setChildren([$paragraph]);

        $carve = rtrim((new CarveRenderer())->render($document));

        $this->assertSame($expected, $carve, 'the writer mis-escaped an ingested text node');
        $this->assertFalse(
            str_contains(self::shape($carve . "\n"), '"superscript"'),
            'writing ' . var_export($content, true) . ' grew a superscript nobody wrote',
        );
    }

    /**
     * The bare and escaped spellings stay distinct, which is what makes the
     * pins above statements rather than restatements: `{^x` is one text node,
     * `{\^x` is three, and both render the same.
     */
    public function testTheBareAndEscapedSpellingsStayDistinct(): void
    {
        $converter = new CarveConverter();

        $this->assertSame($converter->convert('{^x'), $converter->convert('{\^x'));
        $this->assertNotSame(self::shape('{^x'), self::shape('{\^x'));
        $this->assertSame('{^x', rtrim(CarveConverter::toCarve('{^x')));
        $this->assertSame('{\^x', rtrim(CarveConverter::toCarve('{\^x')));
    }
}
