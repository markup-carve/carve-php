<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#6010: Markdown import follows the #2254 importer rule. A
 * destination the PART 9 section 25 sink denylist blanks is not written; only
 * the content is.
 */
class AMarkdownDeniedDestinationIsNotWrittenTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    private static function dropped(string $markdown): array
    {
        $rows = array_filter(
            (new MarkdownToCarve())->convertWithFidelityReport($markdown)->diagnostics,
            static fn (MigrationDiagnostic $row): bool => $row->code === 'attribute-dropped',
        );

        return array_values(array_map(static fn (MigrationDiagnostic $row): array => [$row->severity, $row->message], $rows));
    }

    private static function written(string $markdown): string
    {
        return rtrim((new MarkdownToCarve())->convert($markdown), "\n");
    }

    public function testTheReproductionImportsAsItsContentWithOneWarningPerDestination(): void
    {
        $markdown = "Siehe [x](javascript:alert(1)) und ![b](data:image/png;base64,AA).\n";

        $this->assertSame("Siehe x und b.\n", (new MarkdownToCarve())->convert($markdown));
        $this->assertSame([
            ['warning', 'Dropped a link destination with a denied URL scheme'],
            ['warning', 'Dropped an image source with a denied URL scheme'],
        ], self::dropped($markdown));
    }

    public function testTheReportLocatesEachDroppedDestination(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("Siehe [x](javascript:alert(1)) und ![b](data:image/png;base64,AA).\n");

        $rows = array_map(static fn (MigrationDiagnostic $row): array => [$row->code, $row->severity, $row->fidelity, $row->confidence, $row->path], $result->diagnostics);
        $this->assertSame([
            ['markdown-paragraph', 'info', 'preserved', 'exact', 'line:1'],
            ['attribute-dropped', 'warning', 'dropped', 'exact', 'line:1'],
            ['attribute-dropped', 'warning', 'dropped', 'exact', 'line:1'],
        ], $rows);
    }

    public function testADroppedDefinitionIsLocatedAtItsLine(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("[x][r]\n\n[r]: javascript:x\n");

        $rows = array_values(array_filter($result->diagnostics, static fn (MigrationDiagnostic $row): bool => $row->code === 'attribute-dropped'));
        $this->assertCount(1, $rows);
        $this->assertSame('line:3', $rows[0]->path);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function deniedProvider(): array
    {
        return [
            'scheme case' => ['[x](JaVaScRiPt:x)', 'x'],
            'a title goes with the destination' => ['[x](vbscript:x "t")', 'x'],
            'an entity-encoded scheme' => ['[x](&#106;avascript:x)', 'x'],
            'an OS-handler scheme' => ['[x](ms-msdt:x)', 'x'],
            'an escaped colon' => ['[x](javascript\\:x)', 'x'],
            'a named-entity colon' => ['[x](javascript&colon;x)', 'x'],
            'link text keeps its markup' => ['[*a* `c`](javascript:x)', '/a/ `c`'],
            'a pointy destination' => ['[x](<javascript:x>)', 'x'],
            'an autolink keeps its text' => ['<javascript:alert(1)>', 'javascript:alert(1)'],
            'a block image becomes its alt text' => ['![a b](data:x)', 'a b'],
            'an empty block image leaves nothing' => ["p\n\n![](data:x)", 'p'],
            'an image inside an allowed link' => ['[![i](data:x)](https://e)', '[i](https://e)'],
            'an allowed image inside a denied link' => ['[![i](https://e/i.png)](javascript:x)', '![i](https://e/i.png)'],
            'a reference link and its definition' => ["[x][r] ![i][r]\n\n[r]: javascript:x", 'x i'],
            'an unused definition' => ["p\n\n[r]: data:x", 'p'],
            'an empty link inside emphasis' => ['a *[](javascript:x)* b', 'a  b'],
            'an empty link before a line break' => ["a [](javascript:x)\nb", "a\nb"],
            'in a definition description' => ["Term\n: [y](javascript:x) d", "Term\n: y d"],
            'in a quoted list item' => ['> - [y](javascript:x)', '> - y'],
            'in a table cell' => ["| a |\n|---|\n| [](javascript:x) [ok](https://e) |", "|= a |\n| [ok](https://e) |"],
            'in a footnote body' => ["a[^1]\n\n[^1]: [x](javascript:x)", "a[^1]\n\n[^1]: x"],
        ];
    }

    #[DataProvider('deniedProvider')]
    public function testADeniedDestinationIsDropped(string $markdown, string $carve): void
    {
        $this->assertSame($carve, self::written($markdown));
    }

    public function testAReferenceDefinitionIsReportedOnceForEveryLinkThatUsedIt(): void
    {
        $this->assertSame([
            ['warning', 'Dropped a reference definition with a denied URL scheme'],
        ], self::dropped("[x][r] [y][r]\n\n[r]: javascript:x\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allowedProvider(): array
    {
        return [
            'https' => ['[x](https://e)', '[x](https://e)'],
            'mailto' => ['[x](mailto:a@b.c)', '[x](mailto:a@b.c)'],
            'a relative path' => ['[x](/rel)', '[x](/rel)'],
            'a scheme that only starts like a denied one' => ['[x](javascripts:x)', '[x](javascripts:x)'],
            'a percent-encoded scheme is no scheme' => ['[x](%6Aavascript:x)', '[x](%6Aavascript:x)'],
            'an allowed reference' => ["[x][r]\n\n[r]: https://e", "[x][r]\n\n[r]: https://e"],
        ];
    }

    #[DataProvider('allowedProvider')]
    public function testAnAllowedDestinationIsKept(string $markdown, string $carve): void
    {
        $this->assertSame($carve, self::written($markdown));
        $this->assertSame([], self::dropped($markdown));
    }
}
