<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotSourceAttributeHeadingIdsTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function sourceIds(): array
    {
        return [
            'before first heading' => ["see {#x} here\n\n# x\n", ['x-1']],
            'before two matching headings' => ["see {#x} here\n\n# x\n\n# x\n", ['x-1', 'x-2']],
            'before three headings with a later suffix' => ["see {#x-2} here\n\n# x\n\n# x\n\n# x\n", ['x', 'x-1', 'x-3']],
            'after a different heading' => ["# y\n\nsee {#x} here\n\n# x\n\n# x\n\n# x\n", ['y', 'x-1', 'x-2', 'x-3']],
            'after first heading' => ["# x\n\nsee {#x} here\n\n# x\n", ['x', 'x-1']],
            'after both headings' => ["# x\n\n# x\n\nsee {#x-1} here\n", ['x', 'x-1']],
            'id assignment' => ["# x\n\nsee {id=x-1} here\n\n# x\n", ['x', 'x-2']],
            'last id wins' => ["# x\n\nsee {#x-1 #z} here\n\n# x\n", ['x', 'x-1']],
            'heading line attribute' => ["# x {#x-1}\n\n# x\n", ['x', 'x-2']],
            'heading line matching attribute' => ["# x {#x}\n\n# x\n", ['x-1', 'x-2']],
            'heading child matching attribute' => ["# [x]{#x}\n", ['x-1']],
            'code span is inert' => ["# x\n\n`{#x-1}`\n\n# x\n", ['x', 'x-1']],
            'code fence is inert' => ["# x\n\n```\n{#x-1}\n```\n\n# x\n", ['x', 'x-1']],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('sourceIds')]
    public function testSourceIdsKeepDocumentOrder(string $source, array $expected): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));
        preg_match_all('/<section id="([^"]+)/', $html, $matches);

        self::assertSame($expected, $matches[1]);
    }

    public function testIgnoredInlineAttributeStillReservesItsId(): void
    {
        $source = "# x\n\nsee {#x-1} here\n\n# x\n";
        $carve = (new DjotToCarve())->convert($source);
        $html = (new CarveConverter())->convert($carve);

        self::assertStringContainsString('<section id="x-2">', $html);
        self::assertStringNotContainsString('<section id="x-1">', $html);
        self::assertStringNotContainsString('{#x-1}', $carve);
    }

    public function testOnlyAffectedHeadingsGetPinned(): void
    {
        $carve = (new DjotToCarve())->convert("# x\n\n# x\n\n# y\n\n# z\n");

        self::assertStringContainsString('{#x-1}', $carve);
        self::assertStringNotContainsString('{#y}', $carve);
        self::assertStringNotContainsString('{#z}', $carve);
    }
}
