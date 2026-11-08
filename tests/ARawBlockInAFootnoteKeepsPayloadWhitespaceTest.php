<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ARawBlockInAFootnoteKeepsPayloadWhitespaceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function payloads(): array
    {
        return [
            'one trailing space' => ['x ', "\n      x \n"],
            'two trailing spaces' => ['x  ', "\n      x  \n"],
            'trailing tab' => ["x\t", "\n      x\t\n"],
        ];
    }

    #[DataProvider('payloads')]
    public function testTheLastRawBlockKeepsHorizontalWhitespace(string $payload, string $expectedLine): void
    {
        $source = "a[^1]\n\n[^1]: ```=html\n  {$payload}\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString($expectedLine, $html);
        self::assertStringContainsString('<li id="fn1">', $html);
    }
}
