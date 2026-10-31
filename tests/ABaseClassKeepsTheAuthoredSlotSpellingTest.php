<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 section 1 keeps the first class slot's position and spelling.
 */
class ABaseClassKeepsTheAuthoredSlotSpellingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'inline math keeps uppercase class after id' => [
            "\$`a`{id=x CLASS=c}\n",
            '<p><span id="x" CLASS="math inline c" role="math">\(a\)</span></p>',
        ];

        yield 'display math keeps mixed case' => [
            "\$\$`a`{ClAsS=c}\n",
            '<p><span ClAsS="math display c" role="math">\[a\]</span></p>',
        ];

        yield 'task list already keeps uppercase class' => [
            "{CLASS=c}\n- [ ] a\n",
            "<ul CLASS=\"task-list c\">\n  <li><input type=\"checkbox\" disabled aria-label=\"a\"> a</li>\n</ul>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheBaseClassKeepsTheAuthoredSlot(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
