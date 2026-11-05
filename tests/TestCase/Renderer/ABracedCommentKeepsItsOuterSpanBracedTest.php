<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ABracedCommentKeepsItsOuterSpanBracedTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function spans(): iterable
    {
        foreach (['*', '/', '_', '~', '='] as $delimiter) {
            yield $delimiter . ' after space' => ['{' . $delimiter . 'a {% x %}' . $delimiter . "}\n"];

            yield $delimiter . ' after line break' => ['{' . $delimiter . "a\n{% x %}" . $delimiter . "}\n"];
        }

        yield 'nested in list' => ["- {*a\n  {% x %}*}\n"];

        yield 'nested in quote' => ["> {*a {% x %}*}\n"];
    }

    #[DataProvider('spans')]
    public function testWriterKeepsTheSpanBraced(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($source, $formatted);
        self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($formatted));
    }
}
