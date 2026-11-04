<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * carve#4974 ruling A: fmt writes a separator row only for a placeholder with no
 * origin in the head row (a `^`, or a `<` in column 0) or one carrying an alignment.
 */
class AHeadRowSpanIsWrittenNativelyTest extends TestCase
{
    /**
     * @var string
     */
    private const SEPARATOR_ROW = '/^(?:[> ]*|\s*)\|[-:|]+\|$/m';

    public function testARealCellAfterAHeaderSpanIsWrittenNatively(): void
    {
        $carve = (new CarveConverter())->toCarve("| A | < | B |\n|---|---|:--|\n| 1 | 2 | 3 |\n");

        self::assertSame("|= A |= < |={<} B |\n| 1 | 2 | 3 |\n", $carve);
    }

    public function testAnAlignedCoveredPlaceholderKeepsTheSeparator(): void
    {
        $converter = new CarveConverter();
        $source = "| A | < |\n|---|:--|\n| 1 | 2 |\n";
        $carve = $converter->toCarve($source);

        self::assertSame("|= A |={<} < |\n| 1 | 2 |\n", $carve);
        self::assertSame($converter->convert($source), $converter->convert($carve));
    }

    public function testTheImporterWritesAMidRowHeaderSpanNatively(): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert("|=> Wide|<|=~ Last|\n|a|b|c|\n");
        $imported = (new HtmlToCarve())->convert($html);

        self::assertSame("|={>} Wide | < |={~} Last |\n| a | b | c |\n", $imported);
        self::assertSame($html, $converter->convert($imported));
    }

    public function testHeadRowSpanVariantsKeepHtmlAndReachAFixedPoint(): void
    {
        $containers = [
            static fn (string $s): string => $s,
            static fn (string $s): string => implode("\n", array_map(
                static fn (string $line): string => '> ' . $line,
                array_filter(explode("\n", $s), static fn (string $line): bool => $line !== ''),
            )) . "\n",
            static fn (string $s): string => "::: note\n" . $s . ":::\n",
            static fn (string $s): string => "- item\n+\n" . $s,
        ];
        $heads = ['A', '', '<', '^'];
        $colons = ['---', ':--', '--:', ':-:'];
        $converter = new CarveConverter();
        $count = 0;
        foreach ($containers as $containerIndex => $wrap) {
            $maxColumns = $containerIndex === 0 ? 3 : 2;
            for ($columns = 1; $columns <= $maxColumns; $columns++) {
                for ($k = 0; $k < 16 ** $columns; $k++) {
                    $x = $k;
                    $head = [];
                    $separator = [];
                    for ($i = 0; $i < $columns; $i++) {
                        $head[] = $heads[$x % 4];
                        $x = intdiv($x, 4);
                        $separator[] = $colons[$x % 4];
                        $x = intdiv($x, 4);
                    }
                    $body = array_map(static fn (int $i): string => 'v' . $i, array_keys($head));
                    $source = $wrap('| ' . implode(' | ', $head) . " |\n|" . implode('|', $separator) . "|\n| " . implode(' | ', $body) . " |\n");
                    $ruled = in_array('^', $head, true) || $head[0] === '<';
                    foreach ($head as $i => $cell) {
                        $ruled = $ruled || ($cell === '<' && $separator[$i] !== '---');
                    }
                    $once = $converter->toCarve($source);
                    $label = 'variant ' . $count . ': ' . json_encode($source);
                    self::assertSame(false, preg_match(self::SEPARATOR_ROW, $once) === 1, $label);
                    self::assertSame($converter->convert($source), $converter->convert($once), $label);
                    self::assertSame($once, $converter->toCarve($once), $label);
                    $count++;
                }
            }
        }

        self::assertSame(16 + 256 + 4096 + 3 * (16 + 256), $count);
    }
}
