<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` payload line of a wider description span keeps only its columns past the body (PART 9 §28, carve-php#4536).
 */
final class ADescriptionSpanPayloadRunKeepsItsColumnInAnItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'in an item' => ["- a\n  :: u\n  : %%%%\n    n\n    %%%\n    %%%%\n", "n\n%%%"];
        yield 'two items deep' => ["- a\n  - b\n    :: u\n    : %%%%\n      n\n      %%%\n      %%%%\n", "n\n%%%"];
        yield 'one column past the body' => ["- a\n  :: u\n  : %%%%\n    n\n     %%%\n    %%%%\n", "n\n %%%"];
        yield 'at top level' => [":: u\n: %%%%\n  n\n  %%%\n  %%%%\n", "n\n%%%"];
    }

    #[DataProvider('documents')]
    public function testCommentContent(string $source, string $expected): void
    {
        $comments = [];
        $walk = static function (Node $node) use (&$walk, &$comments): void {
            if ($node instanceof Comment) {
                $comments[] = $node->getContent();
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk((new CarveConverter())->parse($source));

        self::assertSame([$expected], $comments);
    }
}
