<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ACommentFenceEndsAtItsOwnerBoundaryTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a line leaving the quote ends the comment payload' => [
                "> %%%\n> x\ny\n> %%%\n",
                "<blockquote><p>x</p></blockquote>\n<p>y</p>\n<blockquote>\n\n</blockquote>",
            ],
            'the boundary does not depend on the delimiter width' => [
                "> %%%%\n> x\ny\n> %%%%\n",
                "<blockquote><p>x</p></blockquote>\n<p>y</p>\n<blockquote>\n\n</blockquote>",
            ],
            'a blank before the later delimiter leaves a paragraph open' => [
                "> %%%\n> x\ny\n\n> %%%\n",
                "<blockquote><p>x\ny</p></blockquote>\n<blockquote>\n\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheReaderFollowsTheOwnershipWalk(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
