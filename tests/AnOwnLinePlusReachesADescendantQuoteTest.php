<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnOwnLinePlusReachesADescendantQuoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'paragraph follower' => [
                "> > a\n  +\n> tail\n",
                "<blockquote>\n  <blockquote>\n    <p>a</p>\n    <p>tail</p>\n  </blockquote>\n</blockquote>",
            ],
            'under a task item' => [
                "> > - [X] a\n  +\n> tail\n",
                "<blockquote>\n  <blockquote>\n    <ul class=\"task-list\">\n      <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled aria-label=\"a\"> a</li>\n    </ul>\n    <p>tail</p>\n  </blockquote>\n</blockquote>",
            ],
            'third level, list follower' => [
                "> > > a\n    +\n> > - s\n",
                "<blockquote>\n  <blockquote>\n    <blockquote>\n      <p>a</p>\n      <ul>\n        <li>s</li>\n      </ul>\n    </blockquote>\n  </blockquote>\n</blockquote>",
            ],
            'a follower off the column leaves the plus a letter' => [
                "> > a\n  +\ntail\n",
                "<blockquote>\n  <blockquote><p>a\n+\ntail</p></blockquote>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testPlus(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
