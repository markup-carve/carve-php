<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blank followed only by invisible lines before a sibling marker loosens the
 * list at any depth inside the item (PART 9 §17 L1c, CARVE-P9-013,
 * markup-carve/carve#4903).
 */
class ABlankBeforeDeepInvisibleLinesLoosensTheListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'comment in a description body' => [
                "- :: term\n  : payload\n\n    %% c\n- sub\n",
                <<<'HTML'
                <ul>
                  <li>
                    <dl>
                      <dt>term</dt>
                      <dd>payload</dd>
                    </dl>
                  </li>
                  <li><p>sub</p></li>
                </ul>
                HTML,
            ],
            'definition in a nested list' => [
                "- a\n  - b\n\n    [r]: /u\n- sub\n",
                <<<'HTML'
                <ul>
                  <li><p>a</p>
                    <ul>
                      <li>b</li>
                    </ul>
                  </li>
                  <li><p>sub</p></li>
                </ul>
                HTML,
            ],
            'comment span in a nested list' => [
                "- a\n  - b\n\n    %%%\n    x\n    %%%\n- sub\n",
                <<<'HTML'
                <ul>
                  <li><p>a</p>
                    <ul>
                      <li>b</li>
                    </ul>
                  </li>
                  <li><p>sub</p></li>
                </ul>
                HTML,
            ],
            'definition in a quoted description body' => [
                "> - :: term\n>   : payload\n>\n>     [r]: /u\n> - sub\n",
                <<<'HTML'
                <blockquote>
                  <ul>
                    <li>
                      <dl>
                        <dt>term</dt>
                        <dd>payload</dd>
                      </dl>
                    </li>
                    <li><p>sub</p></li>
                  </ul>
                </blockquote>
                HTML,
            ],
            'a visible line keeps the blank inside the item' => [
                "- :: term\n  : payload\n\n    %% c\n    more\n- sub\n",
                <<<'HTML'
                <ul>
                  <li>
                    <dl>
                      <dt>term</dt>
                      <dd>
                        <p>payload</p>
                        <p>more</p>
                      </dd>
                    </dl>
                  </li>
                  <li>sub</li>
                </ul>
                HTML,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheBlankStandsBeforeTheSibling(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::create()->convert($source));
    }
}
