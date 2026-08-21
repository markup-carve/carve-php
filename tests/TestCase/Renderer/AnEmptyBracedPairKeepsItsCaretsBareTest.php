<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function json_encode;
use function rtrim;
use function str_replace;
use function var_export;

/**
 * The canonical writer must leave an empty braced pair's carets bare.
 *
 * `{^^}` holds no content, so no superscript can start in it (PART 11 §2,
 * markup-carve/carve#1447, corpus 388). Writing `{\^\^}` manufactures the
 * difference PART 11 §1 forbids - the two spellings are one document modulo
 * escaping, and the escape changes the bytes without changing the document.
 * `{^^}` reads back as one text node, where `{\^\^}` reads back as text plus
 * two escaped_text nodes plus text.
 *
 * The guard for this landed in markup-carve/carve-php#1516 with no test of its
 * own, and reverting it leaves the whole suite green: the corpus formatter test
 * asserts `toHtml(fmt(x)) == toHtml(x)` and idempotency, and an invented escape
 * survives both. The render is intact; the tree is not, and nothing looked at
 * the tree. These are the pins that look at it.
 *
 * The near-miss is pinned alongside, because the rule cuts BOTH ways: an escape
 * is preserved where the author wrote one and not invented where they did not.
 * A naive reading of the fix - "a caret beside a brace never needs escaping" -
 * would also strip an authored `\^`, and `{\^\^}` is the shape where the two
 * rules meet.
 */
class AnEmptyBracedPairKeepsItsCaretsBareTest extends TestCase
{
    /**
     * The pair the writer must not escape, in the positions it can occupy.
     *
     * The last row carries both halves of the rule on one line: the authored
     * escape survives and the empty pair beside it stays bare.
     *
     * @return array<string, array{0: string}>
     */
    public static function bracedPairProvider(): array
    {
        return [
            'alone' => ['{^^}'],
            'between words' => ['a {^^} b'],
            'hugged by text' => ['x{^^}y'],
            'twice in a row' => ['{^^}{^^}'],
            'inside another brace pair' => ['{{^^}}'],
            'beside an authored escape' => ['a \^ {^^} b'],
        ];
    }

    /**
     * The shapes a naive over-correction would unescape too.
     *
     * Every one of these carries an escape the AUTHOR wrote, so the writer
     * reproduces it verbatim. `{\^\^}` is the sharpest of them: it differs from
     * `{^^}` in nothing but escaping, and the writer must still keep the two
     * spellings apart rather than normalize one into the other.
     *
     * @return array<string, array{0: string}>
     */
    public static function authoredEscapeProvider(): array
    {
        return [
            'mid-prose' => ['A \^ caret'],
            'hugged by letters' => ['a\^b'],
            'leading' => ['\^leading'],
            'doubled in prose' => ['x \^\^ y'],
            'doubled inside braces' => ['{\^\^}'],
            'before a note bracket' => ['\^[not a note]'],
        ];
    }

    /**
     * A pair that holds content is a real superscript and is left alone.
     *
     * @return array<string, array{0: string}>
     */
    public static function realSuperscriptProvider(): array
    {
        return [
            'one letter' => ['{^x^}'],
            'several words' => ['{^a b^}'],
            'hugged by text' => ['a{^2^}b'],
        ];
    }

    /**
     * The encoded inline shape of a document, without its byte length.
     *
     * `srcByteLength` counts the source, so it moves whenever an escape is
     * added - which would make the comparison below fail for the wrong reason
     * and say nothing about the document.
     */
    private static function shape(string $source): string
    {
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse($source));

        return (string)json_encode($encoded['children'] ?? []);
    }

    #[DataProvider('bracedPairProvider')]
    public function testAnEmptyBracedPairIsWrittenWithBareCarets(string $source): void
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
     * `toHtml(fmt(x)) == toHtml(x)` held throughout the defect, so it is not
     * evidence. `parse(fmt(x)) == parse(x)` is what broke.
     */
    #[DataProvider('bracedPairProvider')]
    public function testAnEmptyBracedPairSurvivesTheWriterAsTheSameDocument(string $source): void
    {
        $this->assertSame(
            self::shape($source),
            self::shape(CarveConverter::toCarve($source)),
            'formatting changed the document for ' . var_export($source, true),
        );
    }

    #[DataProvider('authoredEscapeProvider')]
    public function testAnAuthoredCaretEscapeIsReproducedVerbatim(string $source): void
    {
        $this->assertSame(
            $source,
            rtrim(CarveConverter::toCarve($source)),
            'the writer dropped an authored caret escape in ' . var_export($source, true),
        );
    }

    #[DataProvider('realSuperscriptProvider')]
    public function testABracedSuperscriptWithContentIsUntouched(string $source): void
    {
        $this->assertSame(
            $source,
            rtrim(CarveConverter::toCarve($source)),
            'the writer rewrote a real superscript in ' . var_export($source, true),
        );
    }

    /**
     * The two spellings are one document modulo escaping, and the writer keeps
     * them apart anyway - which is what makes both pins above meaningful rather
     * than a pair of restatements. `{^^}` is one text node; `{\^\^}` is four.
     */
    public function testTheBareAndEscapedSpellingsStayDistinct(): void
    {
        $converter = new CarveConverter();

        $this->assertSame($converter->convert('{^^}'), $converter->convert('{\^\^}'));
        $this->assertNotSame(self::shape('{^^}'), self::shape('{\^\^}'));
        $this->assertSame('{^^}', rtrim(CarveConverter::toCarve('{^^}')));
        $this->assertSame('{\^\^}', rtrim(CarveConverter::toCarve('{\^\^}')));
    }

    /**
     * One authored escape in the near-miss set is load-bearing rather than
     * cosmetic, so the set is not merely a taste preference: dropping the
     * escape before a note bracket opens an inline note the author did not
     * write, and the RENDER changes with it.
     */
    public function testDroppingTheEscapeBeforeANoteBracketChangesTheRender(): void
    {
        $converter = new CarveConverter();
        $authored = '\^[not a note]';

        $this->assertNotSame(
            $converter->convert($authored),
            $converter->convert(str_replace('\^', '^', $authored)),
        );
    }
}
