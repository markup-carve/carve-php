<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-157, C2-B-158, C2-B-198, C2-W-117: a paragraph whose written line reads back with another `role` reports it.
 */
final class ParagraphRoleLossTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string, list<string>}>
     */
    public static function cases(): array
    {
        $math = ['type' => 'math', 'display' => true, 'value' => 'x'];
        $image = ['type' => 'image', 'src' => 'i.png', 'alt' => 'a'];
        $paragraph = static fn (array $children, ?string $role = null): array => ['type' => 'paragraph']
            + ($role === null ? [] : ['role' => $role]) + ['children' => $children];

        return [
            'display math without a role' => [$paragraph([$math]), "$$`x`\n", ['field-unspellable /children/0']],
            'an image without a role' => [$paragraph([$image]), "![a](i.png)\n", ['field-unspellable /children/0']],
            'display math with its role' => [$paragraph([$math], 'math'), "$$`x`\n", []],
            'an image with its role' => [$paragraph([$image], 'image'), "![a](i.png)\n", []],
            'a role on text' => [$paragraph([['type' => 'text', 'value' => 'q']], 'math'), "q\n", ['field-unspellable /children/0/role']],
            'display math and text' => [$paragraph([$math, ['type' => 'text', 'value' => ' y']]), "$$`x` y\n", []],
            'display math and an empty text' => [$paragraph([$math, ['type' => 'text', 'value' => '']], 'math'), "$$`x`\n", []],
            'a space and an image' => [
                $paragraph([['type' => 'text', 'value' => ' '], $image]),
                " ![a](i.png)\n",
                ['field-unspellable /children/0/children/0/value', 'field-unspellable /children/0'],
            ],
            'display math in a quote' => [
                ['type' => 'block_quote', 'children' => [$paragraph([$math])]],
                "> $$`x`\n",
                ['field-unspellable /children/0/children/0'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @param string $source
     * @param list<string> $losses
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testARoleTheParagraphDoesNotReadBackWithIsReported(array $block, string $source, array $losses): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [$block]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
    }
}
