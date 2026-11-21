<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class WriterLossMessageTest extends TestCase
{
    public function testExtensionFallbackUsesTheCatalogLossMessage(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'extensions' => [
                [
                    'name' => 'ex.box',
                    'version' => '1',
                    'required' => false,
                    'slots' => ['s'],
                ],
            ],
            'children' => [
                [
                    'type' => 'block_extension',
                    'name' => 'ex.box',
                    'version' => '1',
                    'children' => [],
                    'fallback' => [
                        [
                            'type' => 'paragraph',
                            'children' => [['type' => 'text', 'value' => 'f']],
                        ],
                    ],
                ],
            ],
        ];

        $result = Carve::writeWithDiagnostics($tree);
        $this->assertSame("f\n", $result->source);
        $this->assertSame('structure-unspellable', $result->diagnostics[0]->code);
        $this->assertSame('/children/0', $result->diagnostics[0]->path);
        $this->assertSame('Carve source cannot spell a block_extension node here', $result->diagnostics[0]->message);
    }
}
