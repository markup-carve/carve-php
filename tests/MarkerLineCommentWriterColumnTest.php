<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkerLineCommentWriterColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sources(): iterable
    {
        yield 'glued item attribute' => ["-{.g} {% b\n       b %}\n", "-{.g} {% b\n       b %}\n"];
        yield 'long attribute' => ["-{.wide-name} {% b\n               b %}\n", "-{.wide-name} {% b\n               b %}\n"];
        yield 'task marker' => ["- [x] {% b\n        b %}\n", "- [x] {% b\n       b %}\n"];
        yield 'ordered item attribute' => ["1.{.g} {% b\n        b %}\n", "1.{.g} {% b\n        b %}\n"];
        yield 'following sibling' => ["-{.g} {% b\n       b %}\n- next\n", "-{.g} {% b\n       b %}\n- next\n"];
    }

    #[DataProvider('sources')]
    public function testContinuationRemainsPastWrittenBrace(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);
        self::assertSame($expected, $formatted);
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
    }
}
