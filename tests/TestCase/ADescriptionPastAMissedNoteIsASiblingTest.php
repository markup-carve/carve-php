<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `: ` line past a note the description holds opens the next description
 * and is not the note's lazy line (CARVE-P0-024, carve#4043).
 */
class ADescriptionPastAMissedNoteIsASiblingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'unreferenced note' => [
                ":: m\n: [^c]: a\n: b\n",
                "<dl>\n  <dt>m</dt>\n  <dd></dd>\n  <dd>b</dd>\n</dl>",
            ],
            'note after a paragraph' => [
                ":: m\n: x\n  [^c]: a\n: b\n",
                "<dl>\n  <dt>m</dt>\n  <dd>x</dd>\n  <dd>b</dd>\n</dl>",
            ],
            'list in an item' => [
                "- x\n  :: m\n  : [^c]: a\n  : b\n",
                "<ul>\n  <li>x\n    <dl>\n      <dt>m</dt>\n      <dd></dd>\n      <dd>b</dd>\n    </dl>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testTheSiblingKeepsItsContent(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testTheNoteKeepsOnlyItsOwnText(): void
    {
        $html = (new CarveConverter())->convert(":: m\n: [^c]: a\n: b\n\nr[^c]\n");

        $this->assertStringContainsString('<dd>b</dd>', $html);
        $this->assertStringNotContainsString(': b', $html);
    }

    public function testALineAtTheNoteBodyColumnStaysInTheNote(): void
    {
        $html = (new CarveConverter())->convert(":: m\n: [^c]: a\n  : b\n\nr[^c]\n");

        $this->assertStringNotContainsString('<dd>b</dd>', $html);
        $this->assertStringContainsString("a\n: b", $html);
    }
}
