<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotOrphanAttributesTest extends TestCase
{
    public function testAttributeOwnership(): void
    {
        foreach (
            [
                ["{#id} at beginning\n", '<p> at beginning</p>'],
                ["After {#id} space\n{.class}\n", "<p>After  space\n</p>"],
                ["not a [span] {#id}.\n", '<p>not a [span] .</p>'],
                ["{#id .class}\n\nA paragraph\n", '<p id="id" class="class">A paragraph</p>'],
                ["{.a}\n{.b}\n\npara", '<p class="a b">para</p>'],
                ["{.a}\n{.b}", ''],
                ["{.a}\n{.b}\n\n[r]: u\n\n[x][r]", '<p><a href="u" class="a b">x</a></p>'],
                ["{.a}\n{.b}\n[r]: u\n\n[x][r]", '<p><a href="u" class="a b">x</a></p>'],
                ["{.a}\n\n{.b}\n[r]: u\n\n[x][r]", '<p><a href="u" class="a b">x</a></p>'],
                ['[span]{#id}', '<p><span id="id">span</span></p>'],
                ['`x`{.c}', '<p><code class="c">x</code></p>'],
                ['<http://x.y>{.c}', '<p><a href="http://x.y" class="c">http://x.y</a></p>'],
            ] as [$source, $expected]
        ) {
            $carve = (new DjotToCarve())->convert($source);
            $this->assertSame($expected, trim((new CarveConverter())->convert($carve)), $source);
        }
    }

    public function testPendingRunsKeepTheirContainers(): void
    {
        foreach (
            [
                ["> {.a}\n> {.b}\n>\n> para", '<blockquote><p class="a b">para</p></blockquote>'],
                ["> {.a}\n> {.b}\n\npara", '<blockquote></blockquote><p class="a b">para</p>'],
                ["{.a}\n> {.b}\n\n", '<blockquote class="a"></blockquote>'],
                ["{.a}\n> {.b}\n>\n> para", '<blockquote class="a"><p class="b">para</p></blockquote>'],
                ["```\n{.a}\n{.b}\n\npara\n```", "<pre><code>{.a}\n{.b}\n\npara\n</code></pre>"],
            ] as [$source, $expected]
        ) {
            $html = trim((new CarveConverter())->convert((new DjotToCarve())->convert($source)));
            self::assertSame($expected, preg_replace('/>\s+</', '><', $html), $source);
        }
    }

    public function testPendingAttributesMatchDjot(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-pending-attributes.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            $html = (new CarveConverter())->convert((new DjotToCarve())->convert($row['source']));
            $normalize = static function (string $value): string {
                $value = preg_replace('/<\/?tbody>/', '', $value);
                $value = preg_replace('/\s+/', ' ', $value);

                return trim(preg_replace_callback('/> | </', static fn (array $match): string => trim($match[0]), $value));
            };
            self::assertSame($normalize($row['html']), $normalize($html), $row['source']);
        }
    }

    public function testManyPendingItemsSurviveThePcreStackLimit(): void
    {
        $items = array_map(static fn (int $index): string => 'c' . $index, range(0, 8191));
        $classes = implode(' ', $items);
        $source = '{.' . implode(' .', $items) . "}\n\nword";
        $imported = (new DjotToCarve())->convert($source);
        self::assertSame('<p class="' . $classes . '">word</p>', trim((new CarveConverter())->convert($imported)));
    }

    public function testEscapeDensePendingValuesSurviveThePcreStackLimit(): void
    {
        $source = '{title="' . str_repeat('\\"', 8192) . "\"}\n\nword";
        $imported = (new DjotToCarve())->convert($source);
        self::assertSame('<p title="' . str_repeat('&quot;', 8192) . '">word</p>', trim((new CarveConverter())->convert($imported)));
    }

    public function testLongPendingValuesSurviveChainedQuoteExits(): void
    {
        $value = str_repeat('v', 32768);
        $classes = [];
        $source = str_repeat('> ', 64) . '{title="' . $value . '"}' . "\n";
        for ($index = 0; $index < 63; $index++) {
            $classes[] = 'c' . $index;
            $source .= str_repeat('> ', 63 - $index) . '{.c' . $index . '}' . "\n";
        }
        $source .= "\nword";
        $imported = (new DjotToCarve())->convert($source);
        self::assertSame(1, substr_count($imported, $value));
        self::assertLessThan(strlen($source) * 2, strlen($imported));
        self::assertStringContainsString('<p title="' . $value . '" class="' . implode(' ', $classes) . '">word</p>', (new CarveConverter())->convert($imported));
    }
}
