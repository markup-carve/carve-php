<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ownership walk copies blank, text and heading lines read under the
 * document alone straight through (carve-php#4674). The shapes around that
 * shortcut keep the reading the full walk gives them.
 */
class DocumentLevelLinesKeepTheirReadingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'caption after heading' => [
                '# H
^ cap',
                '<section id="H">
  <h1>H</h1>
  <p>^ cap</p>
</section>',
            ],
            'caption after text' => [
                'text
^ cap',
                '<p>text
^ cap</p>',
            ],
            'separator after text' => [
                'Term
: dd',
                '<p>Term
: dd</p>',
            ],
            'separator after heading' => [
                '# H
: dd',
                '<section id="H">
  <h1>H</h1>
  <p>: dd</p>
</section>',
            ],
            'lone plus after text' => [
                'text
+
more',
                '<p>text
+
more</p>',
            ],
            'lone plus after heading' => [
                '# H
+',
                '<section id="H">
  <h1>H</h1>
  <p>+</p>
</section>',
            ],
            'marker after text' => [
                'text
- a',
                '<p>text
- a</p>',
            ],
            'marker after heading' => [
                '# H
- a',
                '<section id="H">
  <h1>H</h1>
  <ul>
    <li>a</li>
  </ul>
</section>',
            ],
            'indented line after blank' => [
                'text

  indented',
                '<p>text</p>
<p>indented</p>',
            ],
            'abbreviation before text' => [
                '*[HTML]: hyper
text HTML',
                '<p>text <abbr title="hyper">HTML</abbr></p>',
            ],
            'abbreviation inside a paragraph' => [
                'text
*[HTML]: hyper
HTML',
                '<p>text
<abbr title="hyper">HTML</abbr></p>',
            ],
            'heading between table rows' => [
                '| a |
# H
| b |',
                '<table>
  <tbody>
    <tr><td>a</td></tr>
  </tbody>
</table>
<section id="H">
  <h1>H</h1>
  <table>
    <tbody>
      <tr><td>b</td></tr>
    </tbody>
  </table>
</section>',
            ],
            'attribute before heading' => [
                '{.c}
# H
text',
                '<section id="H">
  <h1 class="c">H</h1>
  <p>text</p>
</section>',
            ],
            'text after a list' => [
                '- a

text
# H',
                '<ul>
  <li>a</li>
</ul>
<p>text</p>
<section id="H">
  <h1>H</h1>
</section>',
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testShape(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }
}
