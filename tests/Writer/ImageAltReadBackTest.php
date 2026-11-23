<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6443, C2-W-089, C2-W-097: an image alt with a bracket beside a footnote reference, a link tail or an atom is
 * spelled in a form that reads back as the same image.
 */
final class ImageAltReadBackTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unchanged(): array
    {
        return [
            'footnote reference and link tail' => ["![a [^n][a](b]) b](u \"t\")\n%% c\n"],
            'escaped bracket beside a code span and autolink' => ["![\\[`` ` ``<https://e.x/]>](a\\)b)\n%% c\n"],
            'reference image keeps its source' => ["| ![[^n] `x]` \\]][] |\n"],
            'code span at both ends' => ["x ![`x]` </#h> \\[ `x]`](u)\n"],
        ];
    }

    #[DataProvider('unchanged')]
    public function testWritesBackUnchanged(string $source): void
    {
        $sidecar = Carve::formatWithDiagnostics($source);
        $this->assertSame($source, $sidecar->source);
        $this->assertSame([], $sidecar->diagnostics);
    }

    public function testATreeImageReadsBack(): void
    {
        $image = ['type' => 'image', 'src' => 'u', 'alt' => 'a [^n][a](b]) b'];
        $tree = ['type' => 'doc', 'children' => [['type' => 'paragraph', 'role' => 'image', 'children' => [$image]]]];

        $result = Carve::writeWithDiagnostics($tree);
        $this->assertSame([], $result->diagnostics);
        $read = json_decode(Carve::toJson($result->source), true)['children'][0]['children'];
        $this->assertSame([['image', 'a [^n][a](b]) b']], array_map(static fn (array $n): array => [$n['type'], $n['alt'] ?? null], $read));
    }

    public function testAnEditedReferenceImageIsNotWrittenFromItsSource(): void
    {
        $parsed = Carve::parseWithLayout("![x][k]\n\n[k]: /u\n");
        $tree = json_decode(Carve::encodeTree($parsed->tree), true);
        $tree['children'][0]['children'][0]['alt'] = 'updated';

        $this->assertSame("![updated][k]\n\n[k]: /u\n", Carve::write($tree, $parsed->layout));
    }

    public function testADecorativeReferenceImageReportsTheLoss(): void
    {
        $parsed = Carve::parseWithLayout("![x][k]\n\n[k]: /u\n");
        $tree = json_decode(Carve::encodeTree($parsed->tree), true);
        $tree['children'][0]['children'][0]['decorative'] = true;

        $result = Carve::writeWithDiagnostics($tree, $parsed->layout);
        $this->assertSame(['field-unspellable'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
    }
}
