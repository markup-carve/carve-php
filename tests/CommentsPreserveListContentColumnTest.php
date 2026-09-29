<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommentsPreserveListContentColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/fixtures/comment-list-content-column.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            yield $case['source'] => [$case['source'], $case['html']];
        }
    }

    #[DataProvider('cases')]
    public function testCommentsPreserveListContentColumn(string $source, string $html): void
    {
        $this->assertSame($html, trim(CarveConverter::create()->convert($source)));
    }
}
