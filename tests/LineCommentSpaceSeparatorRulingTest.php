<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\TestCase;

final class LineCommentSpaceSeparatorRulingTest extends TestCase
{
    public function testTabAfterMarkerRemainsContentInEveryReader(): void
    {
        foreach (["%%\t x\n", "> %%\t x\n", "| %%\t x\n"] as $source) {
            self::assertSame(["\t x"], $this->comments($source), $source);
        }
    }

    public function testExactlyOneSpaceIsConsumed(): void
    {
        self::assertSame([' x'], $this->comments("%%  x\n"));
        self::assertSame(['x'], $this->comments("%% x\n"));
    }

    public function testFormatterKeepsTabInCommentContent(): void
    {
        foreach (["%%\t x\n", "> %%\t x\n", "| %%\t x\n"] as $source) {
            $written = CarveConverter::toCarve($source);
            self::assertSame(["\t x"], $this->comments($written));
            self::assertSame($written, CarveConverter::toCarve($written));
        }
    }

    /**
     * Collect comment content from the parsed document.
     *
     * @return list<string>
     */
    private function comments(string $source): array
    {
        $found = [];
        $walk = static function (Node $node) use (&$walk, &$found): void {
            if ($node instanceof Comment) {
                $found[] = $node->getContent();
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk((new CarveConverter())->parse($source));

        return $found;
    }
}
