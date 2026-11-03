<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedDefinitionTermColonFenceTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string, string}>
     */
    public static function fenceCases(): array
    {
        return [
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
                "<dl>\n  <dt>c</dt>\n</dl>\n<div>\n\n</div>",
            ],
            'literal' => [
                ':: a
: b
  :: c
    ::: not a fence
',
                "<dl>\n  <dt>a</dt>\n  <dd>\n    <p>b</p>\n    <dl>\n      <dt>c</dt>\n    </dl>\n    <div class=\"not\">\n\n    </div>\n  </dd>\n</dl>",
            ],
            'below' => [
                ':: a
: b
  :: c
 :::
',
                "<dl>\n  <dt>a</dt>\n  <dd>\n    <p>b</p>\n    <dl>\n      <dt>c</dt>\n    </dl>\n  </dd>\n</dl>\n<div>\n\n</div>",
            ],
        ];
    }

    /**
     * A term has no content column, so a colon fence indented past the
     * enclosing container's content column folds into the term
     * (markup-carve/carve#2411, superseding markup-carve/carve-php#2513).
     *
     * @return array<string, array{string, string}>
     */
    public static function foldedCases(): array
    {
        return [
            'reported' => [
                ':: a
: b
  :: c
    :::
',
                "<dl>\n  <dt>a</dt>\n  <dd>\n    <p>b</p>\n    <dl>\n      <dt>c</dt>\n    </dl>\n    <div>\n\n    </div>\n  </dd>\n</dl>",
            ],
            'closed' => [
                ':: a
: b
  :: c
    ::: box
    text
    :::
',
                "<dl>\n  <dt>a</dt>\n  <dd>\n    <p>b</p>\n    <dl>\n      <dt>c</dt>\n    </dl>\n    <div class=\"box\">\n      <p>text</p>\n    </div>\n  </dd>\n</dl>",
            ],
            'tab' => [
                ':: a
: b
  :: c
	::: box
	text
	:::
',
                "<dl>\n  <dt>a</dt>\n  <dd>\n    <p>b</p>\n    <dl>\n      <dt>c</dt>\n    </dl>\n    <div class=\"box\">\n      <p>text</p>\n    </div>\n  </dd>\n</dl>",
            ],
            'overindent' => [
                ':: a
: b
  :: c
      ::: box
      text
      :::
',
                "<dl>\n  <dt>a</dt>\n  <dd>\n    <p>b</p>\n    <dl>\n      <dt>c</dt>\n    </dl>\n    <div class=\"box\">\n      <p>text</p>\n    </div>\n  </dd>\n</dl>",
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
      <dt>c
  ::: box
  text
  :::</dt>
    </dl>
  </li>
</ul>
',
            ],
        ];
    }

    #[DataProvider('foldedCases')]
    public function testFenceIndentedUnderTheTermIsTermText(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source));
        $formatted = CarveConverter::toCarve($source);
        self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    #[DataProvider('fenceCases')]
    public function testFenceBelongsToTheEnclosingContainer(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source));
        $formatted = CarveConverter::toCarve($source);
        self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
