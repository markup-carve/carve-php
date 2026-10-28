<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\IncludeLinter;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableIncludeSelectionTest extends TestCase
{
    public static function corpus(): array
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../spec/tests/include-selection/cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($data['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    #[DataProvider('corpus')]
    public function testSelectionCorpus(array $case): void
    {
        $lang = $case['lang'] ?? 'csv';
        $path = "data.{$lang}";
        $entry = ($case['attrs'] ?? '') . "\n{{ {$path} @code:{$lang} {$case['options']} }}\n"
            . (isset($case['caption']) ? '^ ' . $case['caption'] . "\n" : '');
        $resolver = self::resolver($path, $case['file']);
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $expander = new IncludeExpander($resolver, source: $entry);
        $expanded = $expander->transform($converter->parse($entry));
        $html = $converter->render($expanded);
        foreach ($case['html'] ?? [] as $text) {
            self::assertStringContainsString($text, $html);
        }
        foreach ($case['absent'] ?? [] as $text) {
            self::assertStringNotContainsString($text, $html);
        }
        if ($case['literal'] ?? false) {
            self::assertStringContainsString('{{', $html);
        }
        if ($case['codeFallback'] ?? false) {
            self::assertStringContainsString('<pre', $html);
        }
        if (isset($case['markdown'])) {
            $markdown = CarveConverter::markdown()->render($expanded);
            self::assertStringContainsString($case['markdown'], $markdown);
        }
    }

    public function testPhysicalLineSelectionHint(): void
    {
        foreach (['csv', 'tsv'] as $format) {
            $entry = "{table}\n{{ data.{$format} @code:{$format} @lines:2-3 }}\n";
            $findings = (new IncludeLinter())->lint($entry, resolver: self::resolver("data.{$format}", "Name,Price\nA,1\nB,2\n"));
            self::assertContains('include-lines-table-rows-hint', array_map(static fn ($finding) => $finding->rule, $findings));
        }
    }

    public function testInvalidSelectionKeepsTheResolvedDependency(): void
    {
        foreach (
            [
                '@rows:0-2' => 'include-rows-invalid',
                '@rows:9-10' => 'include-rows-out-of-range',
                '@cols:"Missing"' => 'include-cols-invalid',
                '@cols:"Name,Name"' => 'include-cols-duplicate',
                '@rows:2-2 @lines:2-2' => 'include-selection-conflict',
            ] as $options => $rule
        ) {
            $source = "{table}\n{{ data.csv @code:csv {$options} }}\n";
            $converter = new CarveConverter();
            $expander = new IncludeExpander(self::resolver('data.csv', "Name,Price\nA,1\n"), source: $source);
            $html = $converter->render($expander->transform($converter->parse($source)));
            self::assertStringContainsString('{{ data.csv', $html);
            self::assertContains($rule, array_map(static fn ($warning): ?string => $warning->getRule(), $expander->getWarnings()));
            self::assertTrue($expander->getDependencies()[0]->isResolved());
        }
    }

    public function testGeneratedSelectionsAcrossContainers(): void
    {
        $count = 0;
        foreach ([',' => 'csv', "\t" => 'tsv'] as $separator => $lang) {
            foreach ([0, 1] as $headerRows) {
                foreach ([1, 2, 3, 4] as $first) {
                    foreach ([0, 1, 2, 3] as $lastOffset) {
                        foreach ([0, 1, 2, 3] as $shift) {
                            foreach ([2, 3, 4, 5] as $width) {
                                $names = array_map(static fn (int $i): string => 'C' . ($i + $shift), range(0, 6));
                                $body = [];
                                for ($row = 1; $row <= 8; $row++) {
                                    $body[] = array_map(static fn (int $column): string => "R{$row}C{$column}", range(0, 6));
                                }
                                $records = $headerRows ? [$names, ...$body] : $body;
                                $columns = range($shift, $shift + $width - 2);
                                $cols = $headerRows
                                    ? '@cols:"' . implode(',', array_map(static fn (int $i): string => $names[$i], $columns)) . '"'
                                    : '@cols:' . ($columns[0] + 1) . '-' . (end($columns) + 1);
                                $start = $first + $headerRows;
                                $end = $start + $lastOffset;
                                $source = "{table header-rows={$headerRows}}\n{{ data.{$lang} @code:{$lang} @rows:{$start}-{$end} {$cols} }}";
                                $hosted = match ($lastOffset) {
                                    1 => implode("\n", array_map(static fn (string $line): string => '> ' . $line, explode("\n", $source))),
                                    2 => '- ' . str_replace("\n", "\n  ", $source),
                                    3 => "::: note\n{$source}\n::: ",
                                    default => $source,
                                };
                                $nested = "{$hosted}\n\nTail.";
                                for ($depth = 0; $depth < $shift; $depth++) {
                                    $nested = implode("\n", array_map(static fn (string $line): string => '> ' . $line, explode("\n", $nested)));
                                }
                                $file = implode("\n", array_map(static fn (array $row): string => implode($separator, $row), $records)) . "\n";
                                $converter = new CarveConverter();
                                $expander = new IncludeExpander(self::resolver("data.{$lang}", $file), source: $nested);
                                $expanded = $expander->transform($converter->parse($nested));
                                $table = self::findTable($expanded);
                                self::assertNotNull($table, "variant {$count}");
                                $actual = array_map(
                                    static fn ($row): array => array_map(static fn ($cell): string => $cell->getChildren()[0]->getContent(), array_values($row->getChildren())),
                                    array_values($table->getChildren()),
                                );
                                $expected = $headerRows ? [array_map(static fn (int $i): string => $names[$i], $columns)] : [];
                                foreach (array_slice($body, $first - 1, $lastOffset + 1) as $row) {
                                    $expected[] = array_map(static fn (int $i): string => $row[$i], $columns);
                                }
                                self::assertSame($expected, $actual, "variant {$count}");
                                $count++;
                            }
                        }
                    }
                }
            }
        }
        self::assertSame(1024, $count);
    }

    private static function findTable(Node $node): ?Table
    {
        if ($node instanceof Table) {
            return $node;
        }
        foreach ($node->getChildren() as $child) {
            $table = self::findTable($child);
            if ($table !== null) {
                return $table;
            }
        }

        return null;
    }

    private static function resolver(string $path, string $content): IncludeResolverInterface
    {
        return new class ($path, $content) implements IncludeResolverInterface {
            public function __construct(private string $path, private string $content)
            {
            }

            public function resolve(string $path, IncludeContext $context): ?string
            {
                return $path === $this->path ? $this->content : null;
            }
        };
    }
}
