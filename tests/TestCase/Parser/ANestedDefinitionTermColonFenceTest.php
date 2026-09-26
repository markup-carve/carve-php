<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedDefinitionTermColonFenceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function fenceCases(): array
    {
        return [
            'reported' => [
                ':: a
: b
  :: c
    :::
',
                '<dl>
  <dt>a</dt>
  <dd>
    <p>b</p>
    <dl>
      <dt>c</dt>
    </dl>
    <div>

    </div>
  </dd>
</dl>
',
            ],
            'closed' => [
                ':: a
: b
  :: c
    ::: box
    text
    :::
',
                '<dl>
  <dt>a</dt>
  <dd>
    <p>b</p>
    <dl>
      <dt>c</dt>
    </dl>
    <div class="box">
      <p>text</p>
    </div>
  </dd>
</dl>
',
            ],
            'tab' => [
                ':: a
: b
  :: c
	::: box
	text
	:::
',
                '<dl>
  <dt>a</dt>
  <dd>
    <p>b</p>
    <dl>
      <dt>c</dt>
    </dl>
    <div class="box">
      <p>text</p>
    </div>
  </dd>
</dl>
',
            ],
            'overindent' => [
                ':: a
: b
  :: c
      ::: box
      text
      :::
',
                '<dl>
  <dt>a</dt>
  <dd>
    <p>b</p>
    <dl>
      <dt>c</dt>
    </dl>
    <div class="box">
      <p>text</p>
    </div>
  </dd>
</dl>
',
            ],
            'list' => [
                '- a
  :: c
    ::: box
    text
    :::
',
                '<ul>
  <li>a
    <dl>
      <dt>c</dt>
    </dl>
    <div class="box">
      <p>text</p>
    </div>
  </li>
</ul>
',
            ],
            'description' => [
                ':: a
: b
  :: c
  : d
    ::: box
    text
    :::
',
                '<dl>
  <dt>a</dt>
  <dd>
    <p>b</p>
    <dl>
      <dt>c</dt>
      <dd>
        <p>d</p>
        <div class="box">
          <p>text</p>
        </div>
      </dd>
    </dl>
  </dd>
</dl>
',
            ],
            'code' => [
                ':: a
: b
  :: c
  : ```
    :::
    ```
',
                '<dl>
  <dt>a</dt>
  <dd>
    <p>b</p>
    <dl>
      <dt>c</dt>
      <dd>
        <pre><code>:::
</code></pre>
      </dd>
    </dl>
  </dd>
</dl>
',
            ],
            'top' => [
                ':: c
  :::
',
                '<dl>
  <dt>c
  :::</dt>
</dl>
',
            ],
            'literal' => [
                ':: a
: b
  :: c
    ::: not a fence
',
                '<dl>
  <dt>a</dt>
  <dd>
    <p>b</p>
    <dl>
      <dt>c
  ::: not a fence</dt>
    </dl>
  </dd>
</dl>
',
            ],
            'below' => [
                ':: a
: b
  :: c
 :::
',
                '<dl>
  <dt>a</dt>
  <dd>
    <p>b</p>
    <dl>
      <dt>c</dt>
    </dl>
  </dd>
</dl>
<p>:::</p>
',
            ],
        ];
    }

    #[DataProvider('fenceCases')]
    public function testFenceBelongsToTheEnclosingContainer(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
        $formatted = CarveConverter::toCarve($source);
        self::assertSame($expected, (new CarveConverter())->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
