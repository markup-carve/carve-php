<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-224: an escaped pipe is a literal pipe in a cell's destinations and reference labels.
 */
final class CellPipeEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cells(): array
    {
        return [
            'image destination' => ["| ![i](/a\\|b) |\n", '<td><img src="/a|b" alt="i"></td>'],
            'link destination' => ["| [i](/a\\|b \"t\") |\n", '<td><a href="/a|b" title="t">i</a></td>'],
            'full reference label' => ["| [x][r\\|s] |\n\n[r|s]: /u\n", '<td><a href="/u">x</a></td>'],
            'image reference label' => ["| ![x][r\\|s] |\n\n[r|s]: /u\n", '<td><img src="/u" alt="x"></td>'],
            'collapsed reference' => ["| [r\\|s][] |\n\n[r|s]: /u\n", '<td><a href="/u">r|s</a></td>'],
            'code span keeps the backslash' => ["| [a](`\\|`) `\\|` |\n", '<td><a href="`|`">a</a> <code>\\|</code></td>'],
        ];
    }

    #[DataProvider('cells')]
    public function testEscapedPipeIsLiteral(string $source, string $cell): void
    {
        $this->assertStringContainsString($cell, Carve::toHtml($source));
    }

    public function testOutsideACellTheDestinationKeepsTheBackslash(): void
    {
        $this->assertSame('<p>a <img src="/a\|b" alt="i"></p>', Carve::toHtml("a ![i](/a\\|b)\n"));
    }

    public function testCellReferenceKeyHoldsThePipe(): void
    {
        $json = Carve::toJson("| [x][r\\|s] |\n\n[r|s]: /u\n");

        $this->assertStringContainsString('"key":"r|s","namespace":"link"', $json);
        $this->assertStringNotContainsString('r\\\\|s', $json);
    }
}
