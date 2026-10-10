<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DjotOpaqueMaskTest extends TestCase
{
    public function testRepeatedUnfinishedCommentsRemainLiteral(): void
    {
        $source = str_repeat('{% ', 8192);
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'maskDjotOpaque');
        self::assertSame($source, $method->invoke($converter, $source));
        self::assertSame('<p>' . rtrim($source) . '</p>', trim((new CarveConverter())->convert($converter->convert($source))));
    }

    public function testRepeatedInlineBracketsDoNotBecomeDefinitions(): void
    {
        $source = str_repeat('[] [a]: ', 8192);
        $method = new ReflectionMethod(DjotToCarve::class, 'maskDjotOpaque');
        self::assertSame($source, $method->invoke(new DjotToCarve(), $source));
        self::assertSame("[a]:  \n[b]:  ", $method->invoke(new DjotToCarve(), "[a]: u\n[b]: v"));
    }

    public function testObservesOnlyAcceptedDestinations(): void
    {
        $ranges = [];
        $method = new ReflectionMethod(DjotToCarve::class, 'maskDjotOpaque');
        $method->invoke(new DjotToCarve(), '[a](u) [b](unfinished', true, [
            'onDestination' => static function (int $start, int $end) use (&$ranges): void {
                $ranges[] = [$start, $end];
            },
        ]);
        self::assertSame([[3, 6]], $ranges);
    }

    public function testUnfinishedRawFormatsStopAtLineBoundaries(): void
    {
        $source = str_repeat('`a`{=', 8192);
        $method = new ReflectionMethod(DjotToCarve::class, 'maskDjotOpaque');
        self::assertSame(str_repeat('   {=', 8192), $method->invoke(new DjotToCarve(), $source));
        self::assertSame(str_repeat('   {=', 8192) . "\n}", $method->invoke(new DjotToCarve(), $source . "\n}"));
        self::assertSame('   {=html}', $method->invoke(new DjotToCarve(), '`a`{=html}', true, ['code' => false]));
    }
}
