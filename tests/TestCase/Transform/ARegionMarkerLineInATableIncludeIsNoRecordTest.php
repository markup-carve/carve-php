<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4515: a well-formed region marker line in a table include
 * is no row and no cell, and counts toward neither `@rows` nor `header-rows`.
 */
final class ARegionMarkerLineInATableIncludeIsNoRecordTest extends TestCase
{
    /**
     * @var string
     */
    private const FILE = "%% #region r\na,b\n1,2\n%% #endregion r\n3,4\n";

    public function testAMarkerLineIsNeitherHeaderNorBody(): void
    {
        self::assertSame(
            "<table>\n  <thead>\n    <tr><th scope=\"col\">a</th><th scope=\"col\">b</th></tr>\n  </thead>\n  <tbody>\n"
            . "    <tr><td>1</td><td>2</td></tr>\n    <tr><td>3</td><td>4</td></tr>\n  </tbody>\n</table>\n",
            self::render('', '', self::FILE),
        );
    }

    /**
     * @return array<string, array{string, string, string, list<string>, list<string>}>
     */
    public static function cases(): array
    {
        $nested = "Name\n%% #region o\nA\n// #region i\nB\n// #endregion i\n%% #endregion o\nC\n";
        $file = "%% #region r\nName,Price\nA,1\n%% #endregion r\nB,2\n";

        return [
            // The three spec model rows of tests/include-selection/cases.json (carve#4528).
            'region-marker-is-no-header-record' => ['', '', $file, ['<th scope="col">Name</th>', '<td>A</td>', '<td>B</td>'], ['region']],
            'region-marker-counts-toward-no-row' => ['', '@rows:2-2', $file, ['<th scope="col">Name</th>', '<td>A</td>'], ['region', '<td>B</td>']],
            'nested-region-marker-is-no-record' => ['', '@region:o', $nested, ['<td>A</td>', '<td>B</td>'], ['region', '<td>C</td>']],
            'no header rows' => [' header-rows=0', '', self::FILE, ['<td>a</td>'], ['region']],
            'selected region' => ['', '@region:r', self::FILE, ['<th scope="col">a</th>', '<td>1</td>'], ['region', '<td>3</td>']],
            'rows option' => ['', '@rows:2-2', self::FILE, ['<td>1</td><td>2</td>'], ['region', '<td>3</td>']],
            'lines option' => ['', '@lines:3-5', self::FILE, ['<th scope="col">a</th>', '<td>1</td>', '<td>3</td>'], ['region']],
            'nested markers in a selected region' => ['', '@region:o', "a\n%% #region o\n1\n%% #region i\n2\n%% #endregion i\n%% #endregion o\n", ['<td>1</td>', '<td>2</td>'], ['region']],
            'tsv and a comment leader' => ['', '', "-- #region r\na\tb\n-- #endregion r\n1\t2\n", ['<th scope="col">a</th>', '<td>1</td>'], ['region']],
            'malformed marker is a record' => ['', '', "#region-x\n1\n", ['<th scope="col">#region-x</th>'], []],
            'nameless opener is a record' => ['', '', "#region\n1\n", ['<th scope="col">#region</th>'], []],
            'marker inside a quoted field' => ['', '', "\"x\n%% #region r\ny\"\n1\n", ['%% #region r'], []],
            'triple percent is a record' => ['', '', "%%% #region r\n1\n", ['<th scope="col">%%% #region r</th>'], []],
        ];
    }

    /**
     * @param string $attrs
     * @param string $options
     * @param string $file
     * @param list<string> $present
     * @param list<string> $absent
     */
    #[DataProvider('cases')]
    public function testMarkerLines(string $attrs, string $options, string $file, array $present, array $absent): void
    {
        $html = self::render($attrs, $options, $file);
        foreach ($present as $text) {
            self::assertStringContainsString($text, $html);
        }
        foreach ($absent as $text) {
            self::assertStringNotContainsString($text, $html);
        }
    }

    private static function render(string $attrs, string $options, string $file): string
    {
        $path = str_contains($file, "\t") ? 'd.tsv' : 'd.csv';
        $lang = substr($path, 2);
        $entry = "{table{$attrs}}\n{{ {$path} @code:{$lang} {$options} }}\n";
        $resolver = new class ($path, $file) implements IncludeResolverInterface {
            public function __construct(private string $path, private string $content)
            {
            }

            public function resolve(string $path, IncludeContext $context): ?string
            {
                return $path === $this->path ? $this->content : null;
            }
        };
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $expanded = (new IncludeExpander($resolver, source: $entry))->transform($converter->parse($entry));

        return $converter->render($expanded);
    }
}
