<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedMarkerLineNotePlusContributesNoTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodies(): iterable
    {
        yield 'later note owns its body' => ["[^a]: +\n[^b]: +\n  own\n\n[^a] [^b]\n", ''];
        yield 'earlier note claims paragraph, later note owns indented body' => ["[^a]: +\n[^b]: +\npara\n\n  own\n\n[^a] [^b]\n", 'para'];
    }

    #[DataProvider('bodies')]
    public function testARefusedPlusIsAbsentFromTheBody(string $input, string $firstBody): void
    {
        $html = (new CarveConverter())->convert($input);
        self::assertStringNotContainsString("<p>+\n", $html);
        self::assertStringContainsString("<li id=\"fn2\">\n      <p>own<a", $html);
        if ($firstBody !== '') {
            self::assertStringContainsString("<li id=\"fn1\">\n      <p>{$firstBody}<a", $html);
        }
    }
}
