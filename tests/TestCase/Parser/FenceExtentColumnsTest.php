<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Over-indented closers and trailing blanks stay fence payload (carve-php#2610).
 * Expectations were measured against layout.mjs and html.mjs at 97e47ae2.
 */
class FenceExtentColumnsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function descriptionClosers(): iterable
    {
        foreach (['```', '~~~', '```=html', '~~~=html'] as $opener) {
            $run = substr($opener, 0, 3);
            $raw = str_ends_with($opener, '=html');
            foreach ([0, 1, 2, 4] as $extra) {
                $indent = str_repeat(' ', 3 + $extra);
                foreach ([0, 1, 2, 3] as $past) {
                    $source = ":: term\n:  desc\n\n{$indent}{$opener}\n{$indent}a\n"
                        . $indent . str_repeat(' ', $past) . $run . "\n";
                    $payload = $past === 0 ? 'a' : "a\n" . str_repeat(' ', $past) . $run;
                    $block = $raw ? $payload . "\n" : "<pre><code>{$payload}\n</code></pre>\n";
                    $expected = "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>desc</p>\n    {$block}  </dd>\n</dl>\n";

                    yield "{$opener} base +{$extra} closer +{$past}" => [$source, $expected];

                    $leadSource = ':: term' . "\n:" . str_repeat(' ', 2 + $extra) . $opener . "\n"
                        . "{$indent}a\n" . $indent . str_repeat(' ', $past) . $run . "\n";
                    $leadExpected = "<dl>\n  <dt>term</dt>\n  <dd>\n    {$block}  </dd>\n</dl>\n";

                    yield "lead {$opener} base +{$extra} closer +{$past}" => [$leadSource, $leadExpected];
                }
            }
        }
    }

    #[DataProvider('descriptionClosers')]
    public function testDescriptionCloserMustReachTheOpenersColumn(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trailingBlanks(): iterable
    {
        foreach (['```', '~~~', '```=html', '~~~=html'] as $opener) {
            $raw = str_ends_with($opener, '=html');
            foreach ([0, 1, 2, 4] as $extra) {
                $indent = str_repeat(' ', 2 + $extra);
                foreach ([0, 1, 2] as $blanks) {
                    $source = "- item\n\n{$indent}{$opener}\n{$indent}a\n" . str_repeat("\n", $blanks);
                    $payload = 'a' . str_repeat("\n", $blanks);
                    $block = $raw ? $payload . "\n" : "<pre><code>{$payload}\n</code></pre>\n";
                    $expected = "<ul>\n  <li>item\n    {$block}  </li>\n</ul>\n";
                    $key = "{$opener} base +{$extra} blanks {$blanks}";

                    yield "item {$key}" => [$source, $expected];

                    $quoted = implode("\n", array_map(
                        static fn (string $line): string => '> ' . $line,
                        explode("\n", substr($source, 0, -1)),
                    )) . "\n";
                    $quotedExpected = "<blockquote>\n  <ul>\n    <li>item\n      {$block}    </li>\n  </ul>\n</blockquote>\n";

                    yield "quoted item {$key}" => [$quoted, $quotedExpected];
                }
            }
        }
    }

    public function testMarkerShapedPayloadDoesNotStartANestedList(): void
    {
        foreach (['```', '~~~', '```=html', '~~~=html'] as $opener) {
            $source = "- item\n\n  {$opener}\n  - payload\n\n";
            $block = str_ends_with($opener, '=html')
                ? "- payload\n\n"
                : "<pre><code>- payload\n\n</code></pre>\n";
            $expected = "<ul>\n  <li>item\n    {$block}  </li>\n</ul>\n";

            $this->assertSame($expected, (new CarveConverter())->convert($source), $opener);
        }
    }

    public function testBlankAfterAClosedFenceIsOutsideThePayload(): void
    {
        $source = "- item\n\n  ```\n  a\n  ```\n\n";
        $expected = "<ul>\n  <li>item\n    <pre><code>a\n</code></pre>\n  </li>\n</ul>\n";

        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testBlankBeforeTheNextItemIsPayloadAndLoosensTheList(): void
    {
        $source = "- item\n\n  ```\n  a\n\n- next\n";
        $expected = "<ul>\n  <li><p>item</p>\n    <pre><code>a\n\n</code></pre>\n  </li>\n"
            . "  <li><p>next</p></li>\n</ul>\n";

        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blockBoundaries(): array
    {
        return [
            'nested paragraph after blank ```' => [
                ":: t\n:  - x\n\n     y\n   ```\n\n    > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li><p>x</p>\n        <p>y\n<code></code></p>\n      </li>\n    </ul>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            ],
            'nested paragraph after blank ~~~' => [
                ":: t\n:  - x\n\n     y\n   ~~~\n\n    > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li><p>x</p>\n        <p>y\n~~~</p>\n      </li>\n    </ul>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            ],
            'nested paragraph after blank ```=html' => [
                ":: t\n:  - x\n\n     y\n   ```=html\n\n    > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li><p>x</p>\n        <p>y\n<code>=html</code></p>\n      </li>\n    </ul>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            ],
            'nested paragraph after blank ~~~=html' => [
                ":: t\n:  - x\n\n     y\n   ~~~=html\n\n    > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li><p>x</p>\n        <p>y\n~~~=html</p>\n      </li>\n    </ul>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            ],
            'fence after heading ```' => [
                ":: t\n:  # h\n   ```\n   a\n     ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <h1 id=\"h\">h</h1>\n    <pre><code>a\n  ```\n</code></pre>\n  </dd>\n</dl>\n",
            ],
            'fence after heading ~~~' => [
                ":: t\n:  # h\n   ~~~\n   a\n     ~~~\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <h1 id=\"h\">h</h1>\n    <pre><code>a\n  ~~~\n</code></pre>\n  </dd>\n</dl>\n",
            ],
            'fence after heading ```=html' => [
                ":: t\n:  # h\n   ```=html\n   a\n     ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <h1 id=\"h\">h</h1>\n    a\n  ```\n  </dd>\n</dl>\n",
            ],
            'fence after heading ~~~=html' => [
                ":: t\n:  # h\n   ~~~=html\n   a\n     ~~~\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <h1 id=\"h\">h</h1>\n    a\n  ~~~\n  </dd>\n</dl>\n",
            ],
            'fence after closed fence ```' => [
                ":: t\n:  ```\n   b\n   ```\n   ```\n   a\n     ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>b\n</code></pre>\n    <pre><code>a\n  ```\n</code></pre>\n  </dd>\n</dl>\n",
            ],
            'fence after closed fence ~~~' => [
                ":: t\n:  ~~~\n   b\n   ~~~\n   ~~~\n   a\n     ~~~\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>b\n</code></pre>\n    <pre><code>a\n  ~~~\n</code></pre>\n  </dd>\n</dl>\n",
            ],
            'fence after closed fence ```=html' => [
                ":: t\n:  ```=html\n   b\n   ```\n   ```=html\n   a\n     ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    b\n    a\n  ```\n  </dd>\n</dl>\n",
            ],
            'fence after closed fence ~~~=html' => [
                ":: t\n:  ~~~=html\n   b\n   ~~~\n   ~~~=html\n   a\n     ~~~\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    b\n    a\n  ~~~\n  </dd>\n</dl>\n",
            ],
            'item fence after sublist ```' => [
                "- item\n  - sub\n\n  ```\n  a\n\n",
                "<ul>\n  <li>item\n    <ul>\n      <li>sub</li>\n    </ul>\n    <pre><code>a\n\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'item fence after sublist ~~~' => [
                "- item\n  - sub\n\n  ~~~\n  a\n\n",
                "<ul>\n  <li>item\n    <ul>\n      <li>sub</li>\n    </ul>\n    <pre><code>a\n\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'item fence after sublist ```=html' => [
                "- item\n  - sub\n\n  ```=html\n  a\n\n",
                "<ul>\n  <li>item\n    <ul>\n      <li>sub</li>\n    </ul>\n    a\n\n  </li>\n</ul>\n",
            ],
            'item fence after sublist ~~~=html' => [
                "- item\n  - sub\n\n  ~~~=html\n  a\n\n",
                "<ul>\n  <li>item\n    <ul>\n      <li>sub</li>\n    </ul>\n    a\n\n  </li>\n</ul>\n",
            ],
            'item fence after sublist and paragraph ```' => [
                "- item\n  - sub\n\n  paragraph\n\n  ```\n  a\n\n",
                "<ul>\n  <li><p>item</p>\n    <ul>\n      <li>sub</li>\n    </ul>\n    <p>paragraph</p>\n    <pre><code>a\n\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'item fence after sublist and paragraph ~~~' => [
                "- item\n  - sub\n\n  paragraph\n\n  ~~~\n  a\n\n",
                "<ul>\n  <li><p>item</p>\n    <ul>\n      <li>sub</li>\n    </ul>\n    <p>paragraph</p>\n    <pre><code>a\n\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'item fence after sublist and paragraph ```=html' => [
                "- item\n  - sub\n\n  paragraph\n\n  ```=html\n  a\n\n",
                "<ul>\n  <li><p>item</p>\n    <ul>\n      <li>sub</li>\n    </ul>\n    <p>paragraph</p>\n    a\n\n  </li>\n</ul>\n",
            ],
            'item fence after sublist and paragraph ~~~=html' => [
                "- item\n  - sub\n\n  paragraph\n\n  ~~~=html\n  a\n\n",
                "<ul>\n  <li><p>item</p>\n    <ul>\n      <li>sub</li>\n    </ul>\n    <p>paragraph</p>\n    a\n\n  </li>\n</ul>\n",
            ],
            'nested fence boundary ```' => [
                "- a\n  - b\n\n    ```\n    a\n\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>a\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'nested fence boundary ~~~' => [
                "- a\n  - b\n\n    ~~~\n    a\n\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>a\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'nested fence boundary ```=html' => [
                "- a\n  - b\n\n    ```=html\n    a\n\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        a\n\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'nested fence boundary ~~~=html' => [
                "- a\n  - b\n\n    ~~~=html\n    a\n\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        a\n\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('blockBoundaries')]
    public function testFenceOwnershipFollowsTheCurrentBlock(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    #[DataProvider('trailingBlanks')]
    public function testUnterminatedItemFenceKeepsTrailingBlankPayload(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }
}
