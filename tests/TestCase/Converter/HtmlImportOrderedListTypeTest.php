<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `<ol type="a">` used to leave a raw `{type=a}` attribute block above a
 * decimal list. That renders an `<ol type="a">` again, which is why it looked
 * done, but the tree carried `attrs.type` and never the `olType` field the
 * style belongs in. The assertions therefore read the encoded tree rather than
 * the HTML: the HTML was already right while the mapping was missing, so an
 * HTML assertion here could not fail.
 */
class HtmlImportOrderedListTypeTest extends TestCase
{
    protected HtmlToCarve $converter;

    protected CarveConverter $carve;

    protected AstCodec $codec;

    protected function setUp(): void
    {
        $this->converter = new HtmlToCarve();
        $this->carve = new CarveConverter();
        $this->codec = new AstCodec();
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    protected function importedListStyle(string $html): array
    {
        $tree = $this->codec->encode($this->carve->parse($this->converter->convert($html)));
        $json = json_encode($tree);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $list = $decoded['children'][0] ?? [];
        $this->assertIsArray($list);
        $this->assertSame('list', $list['type'] ?? null, 'the import did not produce a list at all');

        return [$list['olType'] ?? null, $list['start'] ?? 1];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function numberingStyleProvider(): array
    {
        return [
            'lowercase alphabetic' => ['<ol type="a"><li>one</li><li>two</li></ol>', 'a', 1],
            'uppercase alphabetic' => ['<ol type="A"><li>one</li><li>two</li></ol>', 'A', 1],
            'lowercase roman' => ['<ol type="i"><li>one</li><li>two</li></ol>', 'i', 1],
            'uppercase roman' => ['<ol type="I"><li>one</li><li>two</li></ol>', 'I', 1],
            'single alphabetic item' => ['<ol type="a"><li>one</li></ol>', 'a', 1],
            'single roman item' => ['<ol type="I"><li>one</li></ol>', 'I', 1],
            'alphabetic with a start' => ['<ol type="a" start="3"><li>one</li><li>two</li></ol>', 'a', 3],
            'alphabetic starting on a roman letter' => ['<ol type="a" start="9"><li>one</li><li>two</li></ol>', 'a', 9],
            'alphabetic ending on z' => ['<ol type="a" start="25"><li>one</li><li>two</li></ol>', 'a', 25],
            'roman with a start' => ['<ol type="i" start="4"><li>one</li></ol>', 'i', 4],
            'roman with a subtractive start' => ['<ol type="I" start="9"><li>one</li></ol>', 'I', 9],
            'roman past the single letters' => ['<ol type="i" start="27"><li>one</li></ol>', 'i', 27],
            'roman past the subtractive range' => ['<ol type="I" start="4000"><li>one</li></ol>', 'I', 4000],
        ];
    }

    #[DataProvider('numberingStyleProvider')]
    public function testNumberingStyleReachesTheTree(string $html, string $expectedType, int $expectedStart): void
    {
        $this->assertSame([$expectedType, $expectedStart], $this->importedListStyle($html));
    }

    /**
     * The style used to be written as a list-level attribute block, and that
     * block is only written for a top-level list - so a nested `<ol type="i">`
     * lost its style outright. A marker carries at any depth.
     */
    public function testNestedListKeepsItsOwnNumberingStyle(): void
    {
        $carve = $this->converter->convert(
            '<ol type="a"><li>one<ol type="i"><li>deep</li><li>deeper</li></ol></li></ol>',
        );

        $this->assertSame(
            "a. one\n\n   i. deep\n   ii. deeper\n",
            $carve,
        );
        $this->assertStringContainsString('<ol type="i">', $this->carve->convert($carve));
    }

    /**
     * `type="1"` is decimal, which is what an absent `olType` means, so it
     * needs no spelling - and writing it as a raw attribute said in the tree
     * what the markers already said.
     */
    public function testDecimalTypeIsNotWrittenAsAnAttribute(): void
    {
        $carve = $this->converter->convert('<ol type="1"><li>one</li></ol>');

        $this->assertSame("1. one\n", $carve);
        $this->assertSame([null, 1], $this->importedListStyle('<ol type="1"><li>one</li></ol>'));
    }

    /**
     * A style Carve markers cannot spell keeps the previous behavior. There is
     * no marker past `z.`, and `aa.` is not a marker at all - it would come
     * back as a paragraph - so the raw attribute, which still renders the right
     * `<ol>`, is the better of the two losses.
     *
     * @return array<string, array{0: string}>
     */
    public static function unspellableStyleProvider(): array
    {
        return [
            'alphabetic past z' => ['<ol type="a" start="27"><li>one</li></ol>'],
            'alphabetic running past z' => ['<ol type="a" start="25"><li>one</li><li>two</li><li>three</li></ol>'],
            'one item starting on a roman letter' => ['<ol type="a" start="9"><li>one</li></ol>'],
        ];
    }

    #[DataProvider('unspellableStyleProvider')]
    public function testUnspellableStyleFallsBackToTheAttribute(string $html): void
    {
        $carve = $this->converter->convert($html);

        $this->assertStringContainsString('{type=', $carve);
        $this->assertStringContainsString('<ol', $this->carve->convert($carve));
    }

    /**
     * The one-item case reads as Roman precisely because the parser resolves
     * the overlap that way, so the fallback is not a guess: had the markers
     * been written, the list would have come back as a different one.
     */
    public function testTheAmbiguousOneItemCaseWouldHaveReparsedWrong(): void
    {
        $this->assertSame(['i', 9], $this->importedListStyle('<ol type="i" start="9"><li>one</li></ol>'));
        $this->assertSame(['a', 9], $this->importedListStyle('<ol type="a" start="9"><li>one</li><li>two</li></ol>'));
    }

    /**
     * CONTROL. A list with no `type` is decimal and stays decimal, markers
     * included.
     */
    public function testPlainOrderedListIsUnaffected(): void
    {
        $this->assertSame("1. one\n2. two\n", $this->converter->convert('<ol><li>one</li><li>two</li></ol>'));
        $this->assertSame([null, 1], $this->importedListStyle('<ol><li>one</li><li>two</li></ol>'));
    }

    /**
     * CONTROL. `start` on a decimal list still moves the first marker, and the
     * tree still says so.
     */
    public function testDecimalStartIsUnaffected(): void
    {
        $this->assertSame("3. one\n", $this->converter->convert('<ol start="3"><li>one</li></ol>'));
        $this->assertSame([null, 3], $this->importedListStyle('<ol start="3"><li>one</li></ol>'));
    }

    /**
     * CONTROL. Other list-level attributes still reach the attribute block;
     * only `type` moved to the markers.
     *
     * The blank line this used to expect between the attribute block and the
     * first marker was incidental to that claim - it captured what the writer
     * happened to emit rather than anything about where `class` lands - and it
     * is gone since carve-php#1653. The claim itself is unchanged: `{.steps}`
     * still reaches the block, and the markers still carry the `type`.
     */
    public function testOtherListAttributesStillReachTheBlock(): void
    {
        $carve = $this->converter->convert('<ol type="a" class="steps"><li>one</li><li>two</li></ol>');

        $this->assertSame("{.steps}\na. one\nb. two\n", $carve);
    }

    /**
     * CONTROL. A decimal list nested under a decimal one keeps its
     * indentation, which is the behavior the marker-recognition fix in
     * cleanup() had to leave alone.
     */
    public function testDecimalNestingIsUnaffected(): void
    {
        $this->assertSame(
            "1. one\n\n   1. deep\n",
            $this->converter->convert('<ol><li>one<ol><li>deep</li></ol></li></ol>'),
        );
    }
}
