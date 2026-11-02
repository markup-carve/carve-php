<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unclosed fence at a marker-line note's body column, after a nested
 * definition, ends with the item: the column-0 line below is not its payload.
 */
class AnUnclosedFenceAfterANestedNoteEndsWithTheItemTest extends TestCase
{
    use RuleATodo;

    /**
     * @var string
     */
    private const REFS = '<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>'
        . '<a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a>';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        yield 'after a nested note holding a marker line' => [
            "- [^a]: text\n    - child\n    [^b]: text\n      - c2\n    ~~~\n    code\nafter [^a] [^b]\n",
            '<ul><li></li></ul><p>after ' . self::REFS . '</p>',
        ];

        yield 'a closed block in the nested note leaves no lazy claim' => [
            "- [^a]: text\n    - child\n    [^b]: text\n\n      - c2\n      ~~~\n      code\n      ~~~\nafter [^a] [^b]\n",
            '<ul><li></li></ul><p>after ' . self::REFS . '</p>',
        ];

        yield 'a list the note opened after a blank leaves no lazy claim' => [
            "- [^n]: text\n\n    - child\nlazy tail\n\nafter [^n]\n",
            '<ul><li></li></ul><p>lazy tail</p><p>after <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>',
        ];

        yield 'after a nested note holding a term, in a quote' => [
            "> - [^a]: text\n>     more\n>     [^b]: text\n> \n>       :: t\n>     ~~~\n>     code\n> after [^a] [^b]\n",
            '<blockquote><ul><li></li></ul><p>after ' . self::REFS . '</p></blockquote>',
        ];
    }

    #[DataProvider('rows')]
    public function testTheLineBelowLeavesTheList(string $source, string $expected): void
    {
        $html = trim((string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source)));

        $this->assertStringStartsWith($expected . '<section role="doc-endnotes"', $html);
    }
}
