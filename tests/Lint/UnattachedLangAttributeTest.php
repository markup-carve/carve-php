<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Lint\Linter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attribute line the parse lifts into `lang` or `dir` (C2-A-027) still attaches to the block below it
 * (markup-carve/carve#6969, as carve-js#5774).
 */
final class UnattachedLangAttributeTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function cases(): array
    {
        return [
            'paragraph' => ["{:fr}\nUn paragraphe.\n", 0],
            'paragraph in a quote' => ["> {:fr}\n> Un paragraphe.\n", 0],
            'paragraph in a list item' => ["- {:fr}\n  Un paragraphe.\n", 0],
            'heading' => ["{:fr}\n# Titre\n", 0],
            'list' => ["{:fr}\n- item\n", 0],
            'quote' => ["{:fr}\n> cite\n", 0],
            'table' => ["{:fr}\n| a | b |\n", 0],
            'dir only' => ["{dir=rtl}\nUn paragraphe.\n", 0],
            'end of document' => ["{:fr}\n", 1],
            'blank line follows' => ["{:fr}\n\nUn paragraphe.\n", 1],
        ];
    }

    /**
     * @param string $source
     * @param int $expected
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testUnattachedBlockAttribute(string $source, int $expected): void
    {
        $found = array_filter(
            Linter::lint($source),
            static fn ($diagnostic): bool => $diagnostic->toArray()['code'] === 'unattached-block-attribute',
        );

        self::assertCount($expected, $found);
    }
}
