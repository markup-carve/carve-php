<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` block comment body keeps its bytes, blank and whitespace-only lines
 * included, so `fmt` is a fixed point (markup-carve/carve-php#2506).
 */
class ABlockCommentBodyKeepsItsBytesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function bodies(): array
    {
        return [
            'whitespace-only and empty line' => ["%%%\n \n\nx\n\n%%%\n", " \n\nx\n"],
            'leading blank' => ["%%%\n\nx\n%%%\n", "\nx"],
            'trailing blank' => ["%%%\nx\n\n%%%\n", "x\n"],
            'blank between' => ["%%%\na\n\nb\n%%%\n", "a\n\nb"],
        ];
    }

    #[DataProvider('bodies')]
    public function testFmtIsAFixedPoint(string $source, string $content): void
    {
        $comment = (new CarveConverter())->parse($source)->getChildren()[0];
        $this->assertInstanceOf(Comment::class, $comment);
        $this->assertSame($content, $comment->getContent());

        $this->assertSame($source, CarveConverter::toCarve($source));
    }
}
