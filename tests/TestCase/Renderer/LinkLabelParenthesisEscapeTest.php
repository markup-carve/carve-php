<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class LinkLabelParenthesisEscapeTest extends TestCase
{
    public function testIncompleteInnerDestinationKeepsItsParenthesisBare(): void
    {
        $source = '[http://a.b/[x](y](http://a.b/[x]\\(y)';
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($source . "\n", $formatted);
        self::assertSame(CarveConverter::carve()->convert($source), CarveConverter::carve()->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testIncompleteInnerDestinationStillEscapesWhenOuterTailCompletesIt(): void
    {
        $source = '[[x]\\(y](z)';
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($source . "\n", $formatted);
        self::assertSame(CarveConverter::carve()->convert($source), CarveConverter::carve()->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testCompleteInnerDestinationKeepsItsEscape(): void
    {
        $source = '[[x]\\(y)](z)';
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($source . "\n", $formatted);
        self::assertSame(CarveConverter::carve()->convert($source), CarveConverter::carve()->convert($formatted));
    }

    public function testCompletePairBeforeIncompletePairKeepsItsEscape(): void
    {
        $markdown = 'http://a.b/[x](y)[w](q';
        $source = '[http://a.b/[x]\\(y)[w](q](http://a.b/[x](y)[w]\\(q)';
        $formatted = (new MarkdownToCarve())->convert($markdown . "\n");

        self::assertSame($source . "\n", $formatted);
        self::assertSame(CarveConverter::carve()->convert($source), CarveConverter::carve()->convert($formatted));
        self::assertSame(CarveConverter::carve()->convert($formatted), CarveConverter::carve()->convert(CarveConverter::toCarve($formatted)));
    }

    public function testImageAltUsesItsSeparateEscapePolicy(): void
    {
        $source = '![[x]\\(y](z)';
        $formatted = CarveConverter::toCarve($source);

        self::assertSame('![[x](y](z)' . "\n", $formatted);
        self::assertSame(CarveConverter::carve()->convert($source), CarveConverter::carve()->convert($formatted));
    }

    public function testSeededLabelsAndFmtCorpusPreserveRendering(): void
    {
        $random = new Randomizer(new Mt19937(3616));
        $atoms = ['a', '[x]', '[x](y)', '[x](y', '[x]\\(y)', '[x][y]', '[x](y)[w](q', '[', '[x', ']', '(', ')', '\\'];
        $html = CarveConverter::carve();

        for ($i = 0; $i < 864; $i++) {
            $label = '';
            for ($j = 0; $j <= $i % 4; $j++) {
                $label .= $atoms[$random->getInt(0, count($atoms) - 1)];
            }
            $source = ($i % 2 === 0 ? '[' : '![') . $label . '](u)';
            $source = match (intdiv($i, 2) % 4) {
                0 => $source,
                1 => '# ' . $source,
                2 => '- ' . $source,
                3 => "|= h |\n| " . $source . ' |',
            };
            self::assertSame($html->convert($source), $html->convert(CarveConverter::toCarve($source)), 'seed 3616, case ' . $i);
        }

        foreach (glob(dirname(__DIR__, 2) . '/spec/tests/corpus/*.fmt') ?: [] as $formatPath) {
            $source = (string)file_get_contents(substr($formatPath, 0, -4) . '.crv');
            self::assertSame($html->convert($source), $html->convert(CarveConverter::toCarve($source)), basename($formatPath));
        }
    }
}
