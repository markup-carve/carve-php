<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AListMarkerAtAnOuterItemColumnEndsItsDescriptionTest extends TestCase
{
    /**
     * @return array<string, array{int, string, int, string}>
     */
    public static function siblingMarkers(): array
    {
        $cases = [];
        foreach ([1, 2, 3] as $depth) {
            foreach (['- b' => 'ul', '1. b' => 'ol', '* [ ] b' => 'task'] as $marker => $kind) {
                foreach ([0, 1, 2] as $extraColumn) {
                    $cases["depth {$depth}, {$kind}, extra {$extraColumn}"] = [$depth, $marker, $extraColumn, $kind];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('siblingMarkers')]
    public function testReachedOuterColumnOwnsMarker(int $depth, string $marker, int $extraColumn, string $kind): void
    {
        $column = 2 * $depth;
        $liIndent = 4 * $depth - 2;
        $source = '- ' . str_repeat('- ', $depth - 1) . "a\n"
            . str_repeat(' ', $column) . ":: t\n"
            . str_repeat(' ', $column) . ":  d\n"
            . str_repeat(' ', $column + $extraColumn) . $marker . "\n";

        $child = match ($kind) {
            'ol' => "<ol>\n" . str_repeat(' ', $liIndent + 4) . "<li>b</li>\n" . str_repeat(' ', $liIndent + 2) . '</ol>',
            'task' => '<ul class="task-list">' . "\n" . str_repeat(' ', $liIndent + 4)
                . '<li><input type="checkbox" disabled aria-label="b"> b</li>' . "\n" . str_repeat(' ', $liIndent + 2) . '</ul>',
            default => "<ul>\n" . str_repeat(' ', $liIndent + 4) . "<li>b</li>\n" . str_repeat(' ', $liIndent + 2) . '</ul>',
        };
        $inner = '<li>a' . "\n" . str_repeat(' ', $liIndent + 2)
            . "<dl>\n" . str_repeat(' ', $liIndent + 4) . "<dt>t</dt>\n"
            . str_repeat(' ', $liIndent + 4) . "<dd>d</dd>\n"
            . str_repeat(' ', $liIndent + 2) . "</dl>\n"
            . str_repeat(' ', $liIndent + 2) . $child . "\n"
            . str_repeat(' ', $liIndent) . '</li>';
        for ($level = $depth; $level > 1; $level--) {
            $inner = "<li>\n" . str_repeat(' ', 4 * $level - 4) . "<ul>\n"
                . str_repeat(' ', 4 * $level - 2) . $inner . "\n"
                . str_repeat(' ', 4 * $level - 4) . "</ul>\n"
                . str_repeat(' ', 4 * $level - 6) . '</li>';
        }
        $expected = "<ul>\n  {$inner}\n</ul>\n";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testMarkerOneColumnShortStaysInTheDescription(): void
    {
        $source = "- a\n  :: t\n  :  d\n - b\n";
        $expected = "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>d\n- b</dd>\n    </dl>\n  </li>\n</ul>\n";
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testOpenQuoteParagraphKeepsUnmarkedMarker(): void
    {
        $source = "- a\n  :: t\n  : > q\n    - b\n";
        $expected = "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <blockquote><p>q\n- b</p></blockquote>\n      </dd>\n    </dl>\n  </li>\n</ul>\n";
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
