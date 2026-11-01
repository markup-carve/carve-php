<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommentErasedBlankRunEndsListTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function threeBlankRuns(): iterable
    {
        yield 'two before comment' => ["- a\n\n\n%% c\n\n- b\n"];
        yield 'two after comment' => ["- a\n\n%% c\n\n\n- b\n"];
        yield 'ordered markers' => ["1. a\n\n%% c\n\n\n2. b\n"];
    }

    #[DataProvider('threeBlankRuns')]
    public function testLineCommentDoesNotSplitHardBoundary(string $source): void
    {
        $html = CarveConverter::create()->convert($source);

        self::assertSame(2, preg_match_all('/<(?:ul|ol)(?: [^>]*)?>/', $html));
        self::assertStringNotContainsString('<p>a</p>', $html);
    }

    public function testCommentAtItemContentColumnKeepsTheSiblingInOneList(): void
    {
        $source = "- a\n\n  %% c\n\n\n- b\n";
        $html = CarveConverter::create()->convert($source);

        self::assertSame(1, substr_count($html, '<ul>'));
        self::assertStringContainsString('<li><p>a</p></li>', $html);
    }
}
