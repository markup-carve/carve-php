<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blank inside an item's `%%%` span with only the closer after it is interior, however the
 * span was opened (PART 9 §17 L1c, ruling markup-carve/carve#5030 B). With no closer the blank
 * still loosens the list.
 */
class ABlankInsideAClosedItemSpanKeepsTheListTightTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'span on the marker line' => [
                "- %%%\n\n  %%%\n- d\n",
                <<<'HTML'
                <ul>
                  <li></li>
                  <li>d</li>
                </ul>
                HTML,
            ],
            'span on its own item line' => [
                "- a\n  %%%\n\n  %%%\n- d\n",
                <<<'HTML'
                <ul>
                  <li>a</li>
                  <li>d</li>
                </ul>
                HTML,
            ],
            'span in a nested item' => [
                "- a\n  - b\n    %%%\n\n    %%%\n- c\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <ul>
                      <li>b</li>
                    </ul>
                  </li>
                  <li>c</li>
                </ul>
                HTML,
            ],
            'span on a nested marker line' => [
                "- a\n  - %%%\n\n    %%%\n- c\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <ul>
                      <li></li>
                    </ul>
                  </li>
                  <li>c</li>
                </ul>
                HTML,
            ],
            'span on a description lead in an item' => [
                "- a\n  :: u\n  : %%%\n\n    %%%\n- c\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <dl>
                      <dt>u</dt>
                      <dd></dd>
                    </dl>
                  </li>
                  <li>c</li>
                </ul>
                HTML,
            ],
            'an unterminated span stays loose' => [
                "- a\n  %%%\n\n- d\n",
                <<<'HTML'
                <ul>
                  <li><p>a</p></li>
                  <li><p>d</p></li>
                </ul>
                HTML,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testRendersAsTheRuledReading(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }
}
