<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 6: only the path slot (`"`) and an `@key:` value slot open a quoted
 * run (carve#4462, corpus 854). A quote anywhere else is a token character.
 */
class AQuoteInABareIncludeTokenOpensNoRunTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function coreProvider(): iterable
    {
        yield 'apostrophe in a bare path' => [
            "{{ it's_x @lines:0-1 }} it's_y }}",
            "<p>{{ it's_x @lines:0-1 }} it\u{2019}s_y }}</p>",
        ];

        yield 'double quote inside a bare path' => [
            '{{ a"b @lines:0-1 }} c" }}',
            "<p>{{ a\"b @lines:0-1 }} c\u{201D} }}</p>",
        ];

        yield 'apostrophe in a later token' => [
            "{{ x it's @lines:0-1 }} y }}",
            "<p>{{ x it\u{2019}s <span class=\"mention\"><strong>@lines</strong></span>:0-1 }} y }}</p>",
        ];

        yield 'a single-quoted value still holds the pair' => [
            "{{ x @label:'a }} b' @lines:0-1 }} c",
            "<p>{{ x @label:'a }} b' @lines:0-1 }} c</p>",
        ];

        yield 'a quoted path still holds the pair' => [
            '{{ "a }} b" @lines:0-1 }} c',
            '<p>{{ "a }} b" @lines:0-1 }} c</p>',
        ];
    }

    #[DataProvider('coreProvider')]
    public function testTheCoreClosesWhereTheSpecModelDoes(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }

    public function testTheCarveWriterKeepsTheSource(): void
    {
        foreach (["{{ it's_x @lines:0-1 }} it's_y }}", '{{ a"b }} c" }}'] as $source) {
            $this->assertSame($source . "\n", CarveConverter::carve()->convert($source));
        }
    }
}
