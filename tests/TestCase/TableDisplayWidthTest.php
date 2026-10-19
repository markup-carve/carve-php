<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Util\TableDisplayWidth;
use PHPUnit\Framework\TestCase;

class TableDisplayWidthTest extends TestCase
{
    public function testUnicodeGraphemeFixtures(): void
    {
        $path = dirname(__DIR__) . '/fixtures/unicode/grapheme-break-18.txt';
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (!str_starts_with($line, '÷')) {
                continue;
            }
            $line = trim(explode('#', $line, 2)[0]);
            $expected = [];
            $current = '';
            foreach (preg_split('/\s+/', $line) as $token) {
                if ($token === '÷') {
                    if ($current !== '') {
                        $expected[] = $current;
                        $current = '';
                    }
                } elseif ($token !== '×') {
                    $current .= mb_chr(hexdec($token), 'UTF-8');
                }
            }
            $this->assertSame($expected, TableDisplayWidth::graphemeClusters(implode('', $expected)), $line);
        }
    }

    public function testWidths(): void
    {
        $this->assertSame(8, TableDisplayWidth::width('山田太郎'));
        $this->assertSame(1, TableDisplayWidth::width("e\u{0301}"));
        $this->assertSame(2, TableDisplayWidth::width('💻'));
        $this->assertSame(1, TableDisplayWidth::width('·'));
        $this->assertSame(0, TableDisplayWidth::width(''));
        $this->assertSame(1001, TableDisplayWidth::width(str_repeat('🇦', 2001)));
    }
}
