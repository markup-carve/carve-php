<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class ImageDecorativeLossTest extends TestCase
{
    public function testSuppliedDecorativeFlagIsReportedAtItsPath(): void
    {
        foreach ([false, true] as $decorative) {
            $tree = [
                'type' => 'document',
                'astVersion' => '2.0',
                'children' => [
                    [
                        'type' => 'paragraph',
                        'role' => 'image',
                        'children' => [['type' => 'image', 'src' => 'x.png', 'decorative' => $decorative]],
                    ],
                ],
            ];
            $result = Carve::writeWithDiagnostics($tree);
            $this->assertSame("![](x.png)\n", $result->source);
            $this->assertSame(['field-unspellable'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
            $this->assertSame('/children/0/children/0/decorative', $result->diagnostics[0]->path);
            $this->assertSame('Carve source cannot spell the decorative field of a image node here', $result->diagnostics[0]->message);
        }
    }
}
