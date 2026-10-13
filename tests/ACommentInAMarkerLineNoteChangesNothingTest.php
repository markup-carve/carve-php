<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ACommentInAMarkerLineNoteChangesNothingTest extends TestCase
{
    #[DataProvider('noteComments')]
    public function testCommentLeavesTheNoteUnchanged(string $source): void
    {
        $converter = CarveConverter::create();

        self::assertSame(
            $converter->convert(str_replace("    %% c\n", '', $source)),
            $converter->convert($source),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function noteComments(): iterable
    {
        yield 'adjacent comment' => ["- [^n]: text\n    %% c\n    ```\n    code\n    ```\nafter [^n]\n"];
        yield 'comment after blank' => ["- [^n]: text\n\n    %% c\n    ```\n    code\n    ```\nafter [^n]\n"];
    }
}
