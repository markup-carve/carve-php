<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class ImageDecorativeLossTest extends TestCase
{
    /**
     * C2-W-011 (carve#6804): only `decorative: false`, which needs alt text (C2-A-115), is reported.
     */
    public function testSuppliedDecorativeFalseIsReportedAtItsPath(): void
    {
        $result = Carve::writeWithDiagnostics(self::tree(['alt' => 'a', 'decorative' => false]));
        $this->assertSame("![a](x.png)\n", $result->source);
        $this->assertSame(['field-unspellable'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
        $this->assertSame('/children/0/children/0/decorative', $result->diagnostics[0]->path);
        $this->assertSame('Carve source cannot spell the decorative field of a image node here', $result->diagnostics[0]->message);
    }

    public function testDecorativeImageWritesEmptyBracketsWithNoReport(): void
    {
        $result = Carve::writeWithDiagnostics(self::tree(['decorative' => true]));
        $this->assertSame(["![](x.png)\n", []], [$result->source, $result->diagnostics]);
        $this->assertSame("![](x.png)\n", Carve::write(self::tree(['decorative' => true])));
    }

    public function testUnknownAltIsReportedAtTheImageAndWriteReturnsTheSource(): void
    {
        $result = Carve::writeWithDiagnostics(self::tree([]));
        $this->assertSame("![](x.png)\n", $result->source);
        $this->assertSame(['image-alt-unknown'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
        $this->assertSame('/children/0/children/0', $result->diagnostics[0]->path);
        $this->assertSame("![](x.png)\n", Carve::write(self::tree([])));
    }

    public function testDecorativeImageInsideEmphasisAndListReadsBack(): void
    {
        $image = ['type' => 'image', 'src' => 'x.png', 'decorative' => true];
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'list',
                    'style' => '-',
                    'spacing' => 'tight',
                    'children' => [
                        [
                            'type' => 'list_item',
                            'children' => [
                                [

                                    'type' => 'paragraph',
                                    'children' => [
                                        ['type' => 'text', 'value' => 'a '],
                                        ['type' => 'emphasis', 'children' => [$image]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $result = Carve::writeWithDiagnostics($tree);
        $this->assertSame(["- a /![](x.png)/\n", []], [$result->source, $result->diagnostics]);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function tree(array $fields): array
    {
        return [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'role' => 'image',
                    'children' => [['type' => 'image', 'src' => 'x.png'] + $fields],
                ],
            ],
        ];
    }
}
