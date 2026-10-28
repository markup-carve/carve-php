<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalNativeTableHeadersTest extends TestCase
{
    #[DataProvider('tables')]
    public function testCanonicalAndSourcePreservingForms(string $source, string $canonical): void
    {
        $parser = new BlockParser();
        $document = $parser->parse($source);
        $writer = new CarveRenderer();
        $formatted = $writer->render($document);

        self::assertSame($canonical, $formatted);
        self::assertSame($formatted, $writer->render($parser->parse($formatted)));
        self::assertSame((new HtmlRenderer())->render($document), (new HtmlRenderer())->render($parser->parse($formatted)));
        self::assertSame($source, (new CarveRenderer())->setPreserveTableSeparators(true)->render($document));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tables(): iterable
    {
        yield 'plain' => ["| A | B |\n|---|---|\n| x | y |\n", "|= A |= B |\n| x | y |\n"];
        yield 'all alignments' => ["| A | B | C | D |\n|---|:--|:-:|--:|\n| w | x | y | z |\n", "|= A |=< B |=~ C |=> D |\n| w | x | y | z |\n"];
        yield 'header only' => ["| A |\n|---|\n", "|= A |\n"];
        yield 'ragged body' => ["| A |\n|---|\n| x | y |\n", "|= A |\n| x | y |\n"];
    }

    public function testGeneratedTablesKeepTheirHtmlAndBecomeStable(): void
    {
        $converter = new CarveConverter();
        $count = 0;
        foreach (['---', ':--', ':-:', '--:'] as $rule) {
            foreach (range(1, 5) as $columns) {
                foreach (range(0, 4) as $extraCells) {
                    $headers = array_map(static fn (int $index): string => 'H' . $index, range(1, $columns));
                    $body = array_map(static fn (int $index): string => 'D' . $index, range(1, $columns + $extraCells));
                    $table = '| ' . implode(' | ', $headers) . " |\n|" . implode('|', array_fill(0, $columns, $rule)) . "|\n| " . implode(' | ', $body) . " |\n";
                    foreach (range(0, 4) as $host) {
                        $nested = match ($host) {
                            1 => implode("\n", array_map(static fn (string $line): string => '> ' . $line, explode("\n", rtrim($table, "\n")))) . "\n",
                            2 => "- item\n  " . str_replace("\n", "\n  ", rtrim($table, "\n")) . "\n",
                            3 => "::: box\n{$table}:::\n",
                            4 => "::: note\n{$table}:::\n",
                            default => $table,
                        };
                        foreach ([false, true] as $sibling) {
                            $source = $nested . ($sibling ? "\nAfter\n" : '');
                            $formatted = CarveConverter::toCarve($source);
                            self::assertSame($converter->convert($source), $converter->convert($formatted), "variant {$count}");
                            self::assertSame($formatted, CarveConverter::toCarve($formatted), "variant {$count}");
                            $count++;
                        }
                    }
                }
            }
        }
        self::assertSame(1000, $count);
    }
}
