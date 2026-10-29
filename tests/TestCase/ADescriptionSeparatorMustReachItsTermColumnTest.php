<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ADescriptionSeparatorMustReachItsTermColumnTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'open term one column in' => [
            "- one\n  :: term\n :  def\n",
            '<ul> <li>one <dl> <dt>term : def</dt> </dl> </li> </ul>',
        ];

        yield 'wrapped term one column in' => [
            "- a\n  :: m\n  more\n : x\n",
            '<ul> <li>a <dl> <dt>m more : x</dt> </dl> </li> </ul>',
        ];

        yield 'wrapped term at outer marker column' => [
            "- a\n  :: m\n  more\n: x\n",
            '<ul> <li>a <dl> <dt>m more</dt> </dl> </li> </ul> <p>: x</p>',
        ];

        yield 'open ordered term one column in' => [
            "1. one\n   :: term\n  :  def\n",
            '<ol> <li>one <dl> <dt>term : def</dt> </dl> </li> </ol>',
        ];

        yield 'open task term one column in' => [
            "- [ ] one\n      :: term\n     :  def\n",
            '<ul class="task-list"> <li><input type="checkbox" disabled aria-label="one"> one <dl> <dt>term : def</dt> </dl> </li> </ul>',
        ];

        yield 'nested open term one column in' => [
            "- a\n  - b\n    - c\n      :: term\n     :  def\n",
            '<ul> <li>a <ul> <li>b <ul> <li>c <dl> <dt>term : def</dt> </dl> </li> </ul> </li> </ul> </li> </ul>',
        ];

        yield 'quoted open term one column in' => [
            "> - one\n>   :: term\n>  :  def\n",
            '<blockquote> <ul> <li>one <dl> <dt>term : def</dt> </dl> </li> </ul> </blockquote>',
        ];

        yield 'separator reaches the last of two terms' => [
            "- a\n   :: first\n  :: second\n  : def\n",
            '<ul> <li>a <dl> <dt>first</dt> <dt>second</dt> <dd>def</dd> </dl> </li> </ul>',
        ];

        yield 'bullet' => [
            "- a\n  :: m\n: l\n: l\n",
            '<ul> <li>a <dl> <dt>m</dt> </dl> </li> </ul> <p>: l : l</p>',
        ];

        yield 'ordered' => [
            "1. a\n   :: m\n: l\n",
            '<ol> <li>a <dl> <dt>m</dt> </dl> </li> </ol> <p>: l</p>',
        ];

        yield 'task' => [
            "- [ ] a\n      :: m\n: l\n",
            '<ul class="task-list"> <li><input type="checkbox" disabled aria-label="a"> a <dl> <dt>m</dt> </dl> </li> </ul> <p>: l</p>',
        ];

        yield 'two list levels' => [
            "- a\n  - b\n    :: m\n: l\n",
            '<ul> <li>a <ul> <li>b <dl> <dt>m</dt> </dl> </li> </ul> </li> </ul> <p>: l</p>',
        ];

        yield 'three list levels' => [
            "- a\n  - b\n    - c\n      :: m\n: l\n",
            '<ul> <li>a <ul> <li>b <ul> <li>c <dl> <dt>m</dt> </dl> </li> </ul> </li> </ul> </li> </ul> <p>: l</p>',
        ];

        yield 'two list levels with an open description paragraph' => [
            "- a\n  - b\n    :: m\n    : d\n: l\n",
            '<ul> <li>a <ul> <li>b <dl> <dt>m</dt> <dd>d</dd> </dl> </li> </ul> </li> </ul> <p>: l</p>',
        ];

        yield 'one column short with an open description paragraph' => [
            "- a\n  :: m\n  : d\n : l\n",
            '<ul> <li>a <dl> <dt>m</dt> <dd>d : l</dd> </dl> </li> </ul>',
        ];

        yield 'outer marker column with an open description paragraph' => [
            "- a\n  :: m\n  : d\n: l\n",
            '<ul> <li>a <dl> <dt>m</dt> <dd>d</dd> </dl> </li> </ul> <p>: l</p>',
        ];

        yield 'no open term' => [
            "- : x\n: y\n",
            '<ul> <li>: x : y</li> </ul>',
        ];

        yield 'no open term after a completed description' => [
            "- a\n  :: m\n  : d\n\n  p\n\n  : x\n: l\n",
            '<ul> <li><p>a</p> <dl> <dt>m</dt> <dd>d</dd> </dl> <p>p</p> <p>: x : l</p> </li> </ul>',
        ];

        yield 'quoted list' => [
            "> - a\n>   :: m\n> : l\n",
            '<blockquote> <ul> <li>a <dl> <dt>m</dt> </dl> </li> </ul> <p>: l</p> </blockquote>',
        ];

        yield 'nested quoted list' => [
            "> - a\n>   - b\n>     :: m\n> : l\n",
            '<blockquote> <ul> <li>a <ul> <li>b <dl> <dt>m</dt> </dl> </li> </ul> </li> </ul> <p>: l</p> </blockquote>',
        ];

        yield 'term on a quoted list marker' => [
            "> - :: m\n> : l\n",
            '<blockquote> <ul> <li> <dl> <dt>m</dt> </dl> </li> </ul> <p>: l</p> </blockquote>',
        ];

        yield 'open quote paragraph at column zero' => [
            "> q\n: l\n",
            '<blockquote><p>q : l</p></blockquote>',
        ];

        yield 'open quote paragraph at column two' => [
            "> q\n  : l\n",
            '<blockquote><p>q : l</p></blockquote>',
        ];
    }

    #[DataProvider('cases')]
    public function testTheSeparatorFollowsItsAuthoredColumn(string $source, string $expected): void
    {
        $html = trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');

        self::assertSame($expected, $html);
    }
}
