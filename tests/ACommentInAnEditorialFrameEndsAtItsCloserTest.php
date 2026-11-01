<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's (CARVE-P9-042).
 */
final class ACommentInAnEditorialFrameEndsAtItsCloserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'an arrowless comment leaves a one-sided substitution (#4395)' => ['{~a %% c~} d', '<p><s>a</s> d</p>'];
        yield 'the same inside an insertion (#4395)' => ['{+{~a %% c~} d+}', '<p><ins><s>a</s> d</ins></p>'];
        yield 'an empty comment closes the frame too' => ['{~a %%~} d', '<p><s>a</s> d</p>'];
        yield 'a comment holding the only arrow leaves the frame unclosed' => ['{~a %% x ~> y~} d', '<p>{~a d</p>'];
        yield 'an arrow before the comment still substitutes' => ['{~a ~> b %% c~} d', '<p><del>a </del><ins> b</ins> d</p>'];
        yield 'a backtick after a nested closer takes the outer closer (carve#4750)' => ['{+{~a %% `~}` c~} d+}', '<p>{+<s>a</s><code> c~} d+}</code></p>'];
        yield 'the same for a deletion in an insertion' => ['{+{-a %% `-}` c-} d+}', '<p>{+<del>a</del><code> c-} d+}</code></p>'];
        yield 'the same for an insertion in a deletion' => ['{-{+a %% `+}` c+} d-}', '<p>{-<ins>a</ins><code> c+} d-}</code></p>'];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
