<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A deeper body line, a blank and a line at the content column stay in the open
 * block comment of a loose item (markup-carve/carve-php#2519).
 */
class AnOpenBlockCommentInALooseItemKeepsItsBodyPastABlankTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function commentProvider(): array
    {
        return [
            'three percent' => [
                "- a\n\n  %%%\n   y\n\n  %%%\n",
                " y\n",
                "- a\n  %%%\n   y\n\n  %%%\n",
            ],
            'a wider fence nests a shorter one' => [
                "- a\n\n  %%%%\n   %%%\n\n  %%%%\n",
                " %%%\n",
                "- a\n  %%%%\n   %%%\n\n  %%%%\n",
            ],
        ];
    }

    #[DataProvider('commentProvider')]
    public function testTheBodyStaysInTheComment(string $src, string $content, string $formatted): void
    {
        $comments = self::comments((new BlockParser())->parse($src));
        $this->assertCount(1, $comments);
        $this->assertNotNull($comments[0]->getFenceLength());
        $this->assertSame($content, $comments[0]->getContent());

        $this->assertSame("<ul>\n  <li>a</li>\n</ul>", rtrim((new CarveConverter())->convert($src), "\n"));

        $this->assertSame($formatted, CarveConverter::toCarve($src));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    /**
     * An opener with no closer ahead opens nothing (PART 9 section 28), so the
     * tracker must not latch and swallow the rest of the item.
     */
    public function testAnUnclosedOpenerStillDegradesToALineComment(): void
    {
        $src = "- a\n\n  %%%\n   y\n\n  b\n";
        $comments = self::comments((new BlockParser())->parse($src));
        $this->assertCount(1, $comments);
        $this->assertNull($comments[0]->getFenceLength());
        $this->assertSame('%', $comments[0]->getContent());

        $this->assertSame(
            "<ul>\n  <li><p>a</p>\n    <p>y</p>\n    <p>b</p>\n  </li>\n</ul>",
            rtrim((new CarveConverter())->convert($src), "\n"),
        );
    }

    /**
     * @return array<int, \MarkupCarve\Carve\Node\Block\Comment>
     */
    private static function comments(Node $node): array
    {
        $found = $node instanceof Comment ? [$node] : [];
        foreach ($node->getChildren() as $child) {
            $found = array_merge($found, self::comments($child));
        }

        return $found;
    }
}
