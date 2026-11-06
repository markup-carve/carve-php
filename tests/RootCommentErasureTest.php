<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class RootCommentErasureTest extends TestCase
{
    public function testBlankAfterACommentRunKeepsTheTwoParagraphsApart(): void
    {
        $source = "one\n%% first\n%% second\n\n%%%\nbody\n%%%\n\ntwo\n";

        self::assertSame("<p>one</p>\n<p>two</p>", CarveConverter::create()->convert($source));
    }

    public function testCommentWithoutASeparatorJoinsTheParagraphLines(): void
    {
        $source = "one\n%% hidden\ntwo\n";

        self::assertSame("<p>one\ntwo</p>", CarveConverter::create()->convert($source));
    }
}
