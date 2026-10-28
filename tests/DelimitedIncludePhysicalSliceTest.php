<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DelimitedIncludePhysicalSliceTest extends TestCase
{
    #[DataProvider('slices')]
    public function testPartialRecordsRemainCode(string $separator, string $format, string $range, bool $table): void
    {
        $file = implode("\n", [
            "A{$separator}B",
            '"first',
            'middle',
            "last\"{$separator}x",
            "Z{$separator}y",
        ]) . "\n";
        $source = "{table}\n{{ data.{$format} @code:{$format} @lines:{$range} }}\n";
        $resolver = new class ($file) implements IncludeResolverInterface {
            public function __construct(private string $file)
            {
            }

            public function resolve(string $path, IncludeContext $context): string
            {
                return $this->file;
            }
        };
        $converter = new CarveConverter();
        $expanded = (new IncludeExpander($resolver, source: $source))->transform($converter->parse($source));
        $html = $converter->render($expanded);

        self::assertSame($table, str_contains($html, '<table'));
        self::assertSame(!$table, str_contains($html, '<pre'));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function slices(): iterable
    {
        foreach ([',' => 'csv', "\t" => 'tsv'] as $separator => $format) {
            yield "{$format} middle" => [$separator, $format, '3-3', false];
            yield "{$format} first edge" => [$separator, $format, '2-3', false];
            yield "{$format} last edge" => [$separator, $format, '3-4', false];
            yield "{$format} whole record" => [$separator, $format, '2-4', true];
        }
    }

    public function testGeneratedPhysicalSlicesRespectWholeRecords(): void
    {
        $count = 0;
        foreach ([',' => 'csv', "\t" => 'tsv'] as $separator => $format) {
            foreach (range(0, 4) as $shift) {
                $siblings = $shift === 0 ? [] : array_map(static fn (int $index): string => "R{$index}{$separator}v", range(1, $shift));
                $file = implode("\n", ["A{$separator}B", ...$siblings, '"first', 'middle', "last\"{$separator}x", "Z{$separator}y"]) . "\n";
                $first = 2 + $shift;
                foreach (range(0, 4) as $host) {
                    foreach ([[$first + 1, $first + 1, false], [$first, $first + 1, false], [$first + 1, $first + 2, false], [$first, $first + 2, true]] as [$start, $end, $whole]) {
                        foreach (range(0, 4) as $suffix) {
                            $directive = "{table}\n{{ data.{$format} @code:{$format} @lines:{$start}-{$end} }}";
                            $source = match ($host) {
                                1 => '> ' . str_replace("\n", "\n> ", $directive),
                                2 => "- item\n  " . str_replace("\n", "\n  ", $directive),
                                3 => "::: box\n{$directive}\n:::",
                                4 => "::: note\n{$directive}\n:::",
                                default => $directive,
                            };
                            $source .= "\n\nTail{$suffix}.\n";
                            $resolver = new class ($file) implements IncludeResolverInterface {
                                public function __construct(private string $file)
                                {
                                }

                                public function resolve(string $path, IncludeContext $context): string
                                {
                                    return $this->file;
                                }
                            };
                            $converter = new CarveConverter();
                            $expanded = (new IncludeExpander($resolver, source: $source))->transform($converter->parse($source));
                            $html = $converter->render($expanded);
                            self::assertSame($whole, str_contains($html, '<table'), "variant {$count}");
                            self::assertSame(!$whole, str_contains($html, '<pre'), "variant {$count}");
                            $count++;
                        }
                    }
                }
            }
        }
        self::assertSame(1000, $count);
    }
}
