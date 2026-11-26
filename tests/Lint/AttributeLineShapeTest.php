<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Lint\Linter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-090: a line has the `attribute_line` shape only when the attribute grammar reads it as glued valid blocks, as
 * carve-js reads it. A brace line the grammar refuses is paragraph text and no unattached attribute.
 */
final class AttributeLineShapeTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function cases(): array
    {
        return [
            'space inside an unquoted value' => ["{widths=50, 50}\n| a | b |\n", false],
            'empty value' => ["{header-rows=}\n```csv\na\n```\n", false],
            'empty value behind a key' => ["{table footer-rows=}\n```csv\na\nb\n```\n", false],
            'key starting with a digit' => ["{1x}\n\ntext\n", false],
            'valid block alone' => ["{.c}\n\ntext\n", true],
            'two glued blocks' => ["{.c}{#i}\n\ntext\n", true],
            'quoted value with a space' => ["{x=\"a b\"}\n\ntext\n", true],
        ];
    }

    #[DataProvider('cases')]
    public function testUnattachedBlockAttribute(string $source, bool $reported): void
    {
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, Linter::lint($source));
        $this->assertSame($reported, in_array('unattached-block-attribute', $codes, true));
    }
}
