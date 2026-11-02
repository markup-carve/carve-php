<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `^ ` line after a blank that attaches to the item's block is part of that
 * block, so a later line repeating its text does not loosen the list
 * (PART 9 §17 L1, markup-carve/carve#4965).
 */
class ARepeatedCaptionLineDoesNotLoosenTheListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'image host' => [
                "- ![]()\n\n  ^ cap\n  ^ cap\n",
                <<<'HTML'
                <ul>
                  <li>
                    <figure>
                      <img src="" alt="">
                      <figcaption>cap</figcaption>
                    </figure>
                    ^ cap
                  </li>
                </ul>
                HTML,
            ],
            'quoted list' => [
                "> - ![]()\n>\n>   ^ cap\n>   ^ cap\n",
                <<<'HTML'
                <blockquote>
                  <ul>
                    <li>
                      <figure>
                        <img src="" alt="">
                        <figcaption>cap</figcaption>
                      </figure>
                      ^ cap
                    </li>
                  </ul>
                </blockquote>
                HTML,
            ],
            'table host' => [
                "- | a |\n\n  ^ cap\n  ^ cap\n",
                <<<'HTML'
                <ul>
                  <li>
                    <table>
                      <caption>cap</caption>
                      <tbody>
                        <tr><td>a</td></tr>
                      </tbody>
                    </table>
                    ^ cap
                  </li>
                </ul>
                HTML,
            ],
            'code fence host' => [
                "- ```\n  code\n  ```\n\n  ^ cap\n  ^ cap\n",
                "<ul>\n  <li>\n    <figure>\n      <pre><code>code\n</code></pre>\n      <figcaption>cap</figcaption>\n    </figure>\n    ^ cap\n  </li>\n</ul>",
            ],
            'repeated line before a sibling' => [
                "- ![]()\n\n  ^ cap\n  ^ cap\n- next\n",
                <<<'HTML'
                <ul>
                  <li>
                    <figure>
                      <img src="" alt="">
                      <figcaption>cap</figcaption>
                    </figure>
                    ^ cap
                  </li>
                  <li>next</li>
                </ul>
                HTML,
            ],
            'blank before the repeat stays loose' => [
                "- ![]()\n\n  ^ cap\n\n  ^ cap\n",
                <<<'HTML'
                <ul>
                  <li>
                    <figure>
                      <img src="" alt="">
                      <figcaption>cap</figcaption>
                    </figure>
                    <p>^ cap</p>
                  </li>
                </ul>
                HTML,
            ],
            'caption with no host stays loose' => [
                "- a\n\n  ^ cap\n  ^ cap\n",
                <<<'HTML'
                <ul>
                  <li><p>a</p>
                    <p>^ cap
                ^ cap</p>
                  </li>
                </ul>
                HTML,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testOnlyTheCaptionGapDecidesTightness(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::create()->convert($source));
    }
}
