<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FootnoteIdUniquenessTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function collisions(): array
    {
        return [
            'heading takes note id' => ["= fn1\n\na[^x]\n\n[^x]: n\n", 'href="#fn1-2"', '<li id="fn1-2">'],
            'authored mark takes note id' => ['a^[x] and b[^n]{#fn1}' . "\n\n[^n]: y\n", 'href="#fn1-2"', '<li id="fn1-2">'],
            'authored mark takes later mark id' => [
                'a^[x]{#fnref2} b^[y]', 'id="fnref2-2" href="#fn2"',
                'href="#fnref2-2" role="doc-backlink"',
            ],
            'occupied second mark suffix' => [
                'a^[x] b[^n]{#fnref2-2} c[^n]' . "\n\n[^n]: z\n",
                'id="fnref2-2-2" href="#fn2"', 'href="#fnref2-2-2" role="doc-backlink"',
            ],
            'authored second mark id is reserved first' => [
                'a[^x]{#fnref1-2} b[^x]' . "\n\n[^x]: n\n",
                'id="fnref1-2-2" href="#fn1"', 'href="#fnref1-2-2" role="doc-backlink"',
            ],
        ];
    }

    #[DataProvider('collisions')]
    public function testC2H202NoteAndMarkIdsAreUnique(string $source, string $reference, string $target): void
    {
        $html = Carve::toHtml($source);
        self::assertStringContainsString($reference, $html);
        self::assertStringContainsString($target, $html);
    }
}
