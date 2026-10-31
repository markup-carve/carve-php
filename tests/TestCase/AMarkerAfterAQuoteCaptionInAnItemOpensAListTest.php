<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `^ ` caption after a quote ends the quote (CARVE-P2-008), so inside a list
 * item a list marker after it opens a sublist (PART 9 §10 I2, markup-carve/carve#4060).
 */
class AMarkerAfterAQuoteCaptionInAnItemOpensAListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCases(): array
    {
        return [
            'a bullet item' => [
                '- > q
  ^ p
  - b
',
                '<ul>
  <li>
    <figure>
      <blockquote><p>q</p></blockquote>
      <figcaption>p</figcaption>
    </figure>
    <ul>
      <li>b</li>
    </ul>
  </li>
</ul>',
            ],
            'an ordered item' => [
                '1. > q
   ^ p
   1. b
',
                '<ol>
  <li>
    <figure>
      <blockquote><p>q</p></blockquote>
      <figcaption>p</figcaption>
    </figure>
    <ol>
      <li>b</li>
    </ol>
  </li>
</ol>',
            ],
            'a task item' => [
                '- [ ] > q
  ^ p
  - b
',
                '<ul class="task-list">
  <li><input type="checkbox" disabled> 
    <figure>
      <blockquote><p>q</p></blockquote>
      <figcaption>p</figcaption>
    </figure>
    <ul>
      <li>b</li>
    </ul>
  </li>
</ul>',
            ],
            'a nested item' => [
                '- - > q
    ^ p
    - b
',
                '<ul>
  <li>
    <ul>
      <li>
        <figure>
          <blockquote><p>q</p></blockquote>
          <figcaption>p</figcaption>
        </figure>
        <ul>
          <li>b</li>
        </ul>
      </li>
    </ul>
  </li>
</ul>',
            ],
            'an item in a quote' => [
                '> - > q
>   ^ p
>   - b
',
                '<blockquote>
  <ul>
    <li>
      <figure>
        <blockquote><p>q</p></blockquote>
        <figcaption>p</figcaption>
      </figure>
      <ul>
        <li>b</li>
      </ul>
    </li>
  </ul>
</blockquote>',
            ],
            'document level keeps the marker as caption text' => [
                '> q
^ p
- b
',
                '<figure>
  <blockquote><p>q</p></blockquote>
  <figcaption>p
- b</figcaption>
</figure>',
            ],
            'an indented caret line stays lazy quote text' => [
                '- > ^ x
   ^ cap
',
                '<ul>
  <li>
    <blockquote><p>^ x
^ cap</p></blockquote>
  </li>
</ul>',
            ],
        ];
    }

    #[DataProvider('provideCases')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
