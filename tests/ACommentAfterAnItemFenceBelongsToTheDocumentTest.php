<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lower-column comment opener leaves an item's unclosed fence (PART 0 S1).
 */
class ACommentAfterAnItemFenceBelongsToTheDocumentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (['-' => 'ul', '*' => 'ul', '1.' => 'ol'] as $marker => $tag) {
            foreach (['```', '~~~'] as $fence) {
                $indent = str_repeat(' ', strlen($marker) + 1);
                $cases["$marker with $fence"] = [
                    "$marker $fence\n{$indent}%%%\n%%%\ny\n",
                    "<$tag>\n  <li>\n    <pre><code>%%%\n</code></pre>\n  </li>\n</$tag>\n",
                ];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function testTheCommentStaysOutsideTheItem(string $source, string $expected): void
    {
        self::assertSame(rtrim($expected, "\n"), CarveConverter::create()->convert($source));
    }
}
