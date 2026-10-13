<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment retains a following line only below the content column.
 */
class CommentEndsTheParagraphInAnItemTest extends TestCase
{
    protected function html(string $source): string
    {
        return (string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function columnRows(): iterable
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/spec-parity/comment-control-columns.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            if (str_starts_with($row['id'], 'comment-item/')) {
                yield $row['id'] => [$row['source'], $row['html']];
            }
        }
    }

    #[DataProvider('columnRows')]
    public function testTheCommentColumnDecidesOwnership(string $source, string $expected): void
    {
        $this->assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }

    public function testNoCommentMeansAPlainLazyFold(): void
    {
        // The boundary the fix must not move: with no comment in between, a
        // below-column line still CONTINUES the paragraph rather than starting a
        // new one.
        $html = $this->html("- a\n b\n\n- c\n");

        $this->assertStringContainsString('a b', $html);
        $this->assertStringNotContainsString('<p>b</p>', $html);
    }
}
