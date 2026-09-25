<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An importer escapes a block marker on a paragraph continuation line exactly
 * when that marker would be structural at column 0 (carve#2256). A pipe that
 * opens no row is not a table - a pipe table needs its delimiter row - so the
 * escape protected nothing, which is the decorative escaping carve#2244 ruled
 * against (carve-php#2339).
 *
 * A CLOSED pipe row keeps its escape: at column 0 it IS a table and it does
 * interrupt a paragraph there, so dropping the escape would change the reading.
 * `***` and `___` keep theirs for the same reason, and so do `#`, `-`, `>`, `+`
 * and `1.`.
 *
 * THE TEST IS `fmt` ON THIS ENGINE ALONE, not a comparison against another. The
 * imported line sits at the container column - so the question the ruling asks
 * is whether the escape is still needed there. A dropped escape on a structural
 * marker shows up as a render that changes under `fmt`; a decorative one does
 * not. No second engine is needed and the answer does not go stale when one
 * changes.
 */
final class APipeThatOpensNoRowNeedsNoEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function bareProvider(): array
    {
        return [
            'a pipe four columns in' => ["foo\n    | bar\n", "foo\n    | bar\n"],
            'a pipe three columns in' => ["foo\n   | bar\n", "foo\n| bar\n"],
            'a pipe with no indent' => ["foo\n| bar\n", "foo\n| bar\n"],
            'a pipe under an item paragraph' => ["- foo\n      | bar\n", "- foo\n  | bar\n"],
            'a pipe opening a cell but not closing the row' => ["foo\n    | a | b\n", "foo\n    | a | b\n"],
        ];
    }

    #[DataProvider('bareProvider')]
    public function testThePipeKeepsNoEscape(string $markdown, string $carve): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertStringNotContainsString('\\|', $imported);
    }

    /**
     * Every shape that IS structural at column 0 and keeps its escape. These are
     * the controls: they pass before the change and after it, and one of them
     * failing is the only way the narrowing could have gone too far.
     *
     * @return array<string, array{string}>
     */
    public static function escapedProvider(): array
    {
        return [
            'a closed pipe row' => ["foo\n    | a | b |\n"],
            'a closed one-cell row' => ["foo\n    | a |\n"],
            'a star break' => ["foo\n    ***\n"],
            'an underscore break' => ["foo\n    ___\n"],
            'a heading marker' => ["foo\n    # bar\n"],
            'a bullet' => ["foo\n    - bar\n"],
            'a star bullet' => ["foo\n    * bar\n"],
            'a quote marker' => ["foo\n    > bar\n"],
            'an ordered marker' => ["foo\n    1. bar\n"],
        ];
    }

    #[DataProvider('escapedProvider')]
    public function testAStructuralMarkerKeepsItsEscape(string $markdown): void
    {
        $this->assertStringContainsString('\\', (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * The ruling's own test: once `fmt` has taken the line to the container
     * column, the document still reads the way it did. A missing escape on a
     * structural marker breaks this; a missing escape on a pipe that opens no
     * row cannot.
     *
     * @return array<string, array{string}>
     */
    public static function fmtProvider(): array
    {
        $shapes = [];
        foreach (self::bareProvider() as $name => $case) {
            $shapes['bare: ' . $name] = [$case[0]];
        }
        foreach (self::escapedProvider() as $name => $case) {
            $shapes['escaped: ' . $name] = [$case[0]];
        }

        return $shapes;
    }

    #[DataProvider('fmtProvider')]
    public function testFmtChangesNeitherTheBytesTwiceNorTheReading(string $markdown): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);
        $formatted = CarveConverter::toCarve($imported);

        $this->assertSame($formatted, CarveConverter::toCarve($formatted), 'fmt is not a fixed point');
        $this->assertSame($this->render($imported), $this->render($formatted), 'fmt changed the reading');
    }

    /**
     * A folded heading is untouched here. carve#2244 ruled that path the other
     * way and this engine was already right: a fold leaves no column for the
     * marker to be dedented to, so the two positions decide differently.
     */
    public function testAFoldedMarkerKeepsItsBareSpelling(): void
    {
        $this->assertSame("> ## foo | bar\n", (new MarkdownToCarve())->convert("> foo\n>     | bar\n> ---\n"));
        $this->assertSame("> ## foo # bar\n", (new MarkdownToCarve())->convert("> foo\n>     # bar\n> ---\n"));
    }

    private function render(string $carve): string
    {
        $html = (new CarveConverter())->convert($carve);

        return trim((string)preg_replace(['/\s+id="[^"]*"/', '/>\s+</', '/\s+/'], ['', '><', ' '], $html));
    }
}
