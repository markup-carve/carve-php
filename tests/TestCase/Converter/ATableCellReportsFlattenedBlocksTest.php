<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ATableCellReportsFlattenedBlocksTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function blockProvider(): array
    {
        return [
            'paragraph' => ['<p>f</p>', '| f |', ['p[1]']],
            'blockquote' => ['<blockquote>f</blockquote>', '| f |', ['blockquote[1]']],
            'heading' => ['<h1>f</h1>', '| f |', ['h1[1]']],
            'code block' => ['<pre><code>f</code></pre>', '| `f` |', ['pre[1]']],
            'list' => ['<ul><li>f</li></ul>', '| f |', ['ul[1]/li[1]', 'ul[1]']],
            'nested blocks' => ['<blockquote><p>f</p></blockquote>', '| f |', ['blockquote[1]/p[1]', 'blockquote[1]']],
        ];
    }

    /**
     * @param string $content
     * @param string $source
     * @param list<string> $paths
     */
    #[DataProvider('blockProvider')]
    public function testEachFlattenedBlockReportsItsCellPath(string $content, string $source, array $paths): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr><td>' . $content . '</td></tr></table>');

        $this->assertSame($source, trim($result->value));
        $diagnostics = $result->report()['diagnostics'];
        $this->assertSame(array_fill(0, count($paths), 'element-unwrapped'), array_column($diagnostics, 'code'));
        $this->assertSame(
            array_map(static fn (string $path): string => '/table[1]/tr[1]/td[1]/' . $path, $paths),
            array_column($diagnostics, 'path'),
        );
    }

    public function testAnHrDropsItsCellRowAndReportsBothLosses(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr><td><hr></td></tr></table>');

        $this->assertSame('', trim($result->value));
        $this->assertSame(
            [['structure-unspellable', '/table[1]/tr[1]'], ['element-dropped', '/table[1]/tr[1]/td[1]/hr[1]']],
            array_map(
                static fn (array $row): array => [$row['code'], $row['path']],
                $result->report()['diagnostics'],
            ),
        );
    }

    public function testListTableKeepsTheBlocksAndNeedsNoFlatteningReport(): void
    {
        $html = '<table><tr><td><ul><li>f</li></ul></td></tr></table>';
        $result = (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport($html);

        $this->assertStringContainsString('::: list-table', $result->value);
        $this->assertSame([], $result->diagnostics);
    }

    public function testAnHrInANonemptyRowReportsOnlyItsDrop(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr><td><hr></td><td>x</td></tr></table>');

        $this->assertSame('| | x |', trim($result->value));
        $this->assertSame(['element-dropped'], array_column($result->report()['diagnostics'], 'code'));
    }

    public function testStoredSourceDoesNotSuppressTheNextImportsCellReport(): void
    {
        $converter = new HtmlToCarve(trustedRoundTrip: true);
        $stored = '<div data-djot-src="stored"><table><tr><td><p>x</p></td></tr></table></div>';
        $this->assertSame([], $converter->convertWithReport($stored)->diagnostics);

        $next = $converter->convertWithReport('<table><tr><td><p>f</p></td></tr></table>');
        $this->assertSame(['element-unwrapped'], array_column($next->report()['diagnostics'], 'code'));
    }
}
