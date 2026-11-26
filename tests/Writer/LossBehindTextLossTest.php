<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\TestCase;

final class LossBehindTextLossTest extends TestCase
{
    public function testInsignificantSpaceOnlyTextNeedsNoValueLoss(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => ' '],
                        ['type' => 'soft_break'],
                        ['type' => 'text', 'value' => 'x'],
                    ],
                ],
            ],
        ];

        $written = Writer::writeWithDiagnostics($tree);
        self::assertNotContains('field-unspellable /children/0/children/0/value', array_map(
            static fn ($entry): string => $entry->code . ' ' . $entry->path,
            $written->diagnostics,
        ));
    }

    public function testLaterTextStillReportsItsUnspellableFields(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => ' w'],
                        ['type' => 'text', 'value' => 'a *b*', 'dir' => 'rtl', 'metadata' => ['note' => 'later']],
                    ],
                ],
            ],
        ];
        $written = Writer::writeWithDiagnostics($tree);

        self::assertSame(" wa \\*b*\n", $written->source);
        self::assertSame([
            'field-unspellable /children/0/children/0/value',
            'field-unspellable /children/0/children/1/dir',
            'field-unspellable /children/0/children/1/metadata',
        ], array_map(static fn ($entry): string => $entry->code . ' ' . $entry->path, $written->diagnostics));
    }
}
