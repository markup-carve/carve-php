<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §4: a parent's span contains every child's, so a multi-line comment
 * run published as a container's last child extends it (carve-php#5150).
 */
final class ATrailingCommentRunStaysInsideItsContainerTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>, int}>
     */
    public static function sources(): array
    {
        return [
            'list item' => ["- x\n\n  {% a\n   z %}\n", ['children', 0, 'items', 0], 19],
            'footnote' => ["x[^n]\n\n[^n]: p\n\n  {% a\n   z %}\n", ['children', 1], 30],
            'block quote' => ["> x\n>\n> {% a\n>  z %}\n", ['children', 0], 20],
        ];
    }

    /**
     * @param string $source
     * @param list<string|int> $path
     * @param int $end
     */
    #[DataProvider('sources')]
    public function testTheContainerEndsWithItsCommentRun(string $source, array $path, int $end): void
    {
        $node = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
        foreach ($path as $key) {
            $node = $node[$key];
        }
        $last = $node['children'][count($node['children']) - 1];

        self::assertSame('comment', $last['type']);
        self::assertSame($end, $last['pos']['endOffset']);
        self::assertSame($end, $node['pos']['endOffset']);
    }
}
