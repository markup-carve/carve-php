<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6465: a comment block in a line block is stripped by at most its opener's indent (C2-B-029, C2-B-123).
 */
final class LineBlockCommentIndentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function rows(): array
    {
        return [
            'spaces' => ["::: |\n  %%%\n  c\n  %%%\n:::\n", 'c'],
            'straddling tab in an item' => ["- ::: |\n\t%%%\n\tc\td\n\t%%%\n", "c\td"],
            'quote' => ["> ::: |\n>   %%%\n>   c\n>   %%%\n> :::\n", 'c'],
            'body less indented than the opener' => ["::: |\n   %%%\n c\n     d\n   %%%\n:::\n", "c\n  d"],
            'unclosed comment ends with the line block' => ["::: |\n  %%%\n  c\n:::\n", 'c'],
            'opener at the content column keeps the body' => ["::: |\n%%%\n  c\n%%%\n:::\n", '  c'],
        ];
    }

    #[DataProvider('rows')]
    public function testCommentValue(string $source, string $value): void
    {
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($tree);
        $this->assertSame([$value], self::comments($tree));
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function comments(array $node): array
    {
        $out = [];
        if (($node['type'] ?? null) === 'comment') {
            $out[] = $node['value'] ?? null;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $out = [...$out, ...self::comments($child)];
            }
        }

        return $out;
    }
}
