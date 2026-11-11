<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ABracedCommentOnlyParagraphPublishesItsAstChildrenTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function documents(): iterable
    {
        yield 'quoted item marker, row 1046' => ["- > {% a\n  >  b\n  >  z %}\n", ['list', 'list_item', 'block_quote'], ["a\nb\nz"]];
        yield 'note marker, row 1055' => ["x[^n]\n\n[^n]: {% a\n     b\n     z %}\n    {.x}\nplain\n", ['footnote'], ["a\nb\nz"]];
        yield 'outer quote and item marker, row 1056' => ["  > - {% a\n>    b %}\n", ['block_quote', 'list', 'list_item'], ["a\nb"]];
        yield 'top-level comment' => ["{% a\n b\n z %}\n", [], ["a\nb\nz"]];
        yield 'two comments in one invisible paragraph' => ["- {% a %} {% b %}\n", ['list', 'list_item'], ['a', 'b']];
    }

    /**
     * @param string $source
     * @param list<string> $path
     * @param list<string> $contents
     */
    #[DataProvider('documents')]
    public function testCommentsRemainInTheAstWithoutAnEmptyParagraph(string $source, array $path, array $contents): void
    {
        $ast = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $parent = $ast;
        foreach ($path as $type) {
            $candidates = $type === 'list_item' ? $parent['items'] : $parent['children'];
            $child = null;
            foreach ($candidates as $candidate) {
                if ($candidate['type'] === $type) {
                    $child = $candidate;

                    break;
                }
            }
            self::assertNotNull($child);
            $parent = $child;
        }

        self::assertCount(count($contents), $parent['children']);
        foreach ($contents as $index => $content) {
            $comment = $parent['children'][$index];
            self::assertSame('comment', $comment['type']);
            self::assertSame($content, $comment['content']);
            self::assertSame('braced', $comment['form']);
            self::assertTrue($comment['delimited']);
        }
    }
}
