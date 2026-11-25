<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * Editor images saved as trees the reader takes (carve-php#6021).
 */
final class ImageSaveTest extends TestCase
{
    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function readable(array $doc): array
    {
        $tree = (new ProseMirrorToCarve())->toTree($doc);
        Carve::decodeTree((string)json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $tree;
    }

    /**
     * @param array<array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private static function paragraph(array $content): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $content]]];
    }

    public function testAnImageWithNoAltSavesWithEmptyAlt(): void
    {
        $doc = self::paragraph([['type' => 'image', 'attrs' => ['src' => 'i.png', 'alt' => null]]]);

        $tree = self::readable($doc);

        $this->assertSame('', $tree['children'][0]['children'][0]['alt']);
        $this->assertSame("![](i.png)\n", (new ProseMirrorToCarve())->convert($doc));
    }

    public function testABlockImageWithNoAltSavesWithEmptyAlt(): void
    {
        $doc = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'i.png']]]];

        $this->assertSame('', self::readable($doc)['children'][0]['children'][0]['alt']);
    }

    public function testADecorativeImageKeepsNoAlt(): void
    {
        $image = ['type' => 'image', 'attrs' => ['src' => 'i.png', 'alt' => null, 'carve' => ['decorative' => true]]];
        $saved = self::readable(self::paragraph([$image]));

        $this->assertArrayNotHasKey('alt', $saved['children'][0]['children'][0]);
    }

    public function testALoadedImageParagraphEditedIntoTextLosesItsRole(): void
    {
        $doc = (new ProseMirrorRenderer())->renderSource("![a](a.png)\n");
        $doc['content'][0]['content'] = [['type' => 'text', 'text' => 'plain']];

        $tree = self::readable($doc);

        $this->assertArrayNotHasKey('role', $tree['children'][0]);
        $this->assertSame("plain\n", (new ProseMirrorToCarve())->convert($doc));
    }

    public function testASpaceBeforeALoneImageIsDropped(): void
    {
        $image = ['type' => 'image', 'attrs' => ['src' => 'a.png', 'alt' => 'a']];
        $doc = self::paragraph([['type' => 'text', 'text' => ' '], $image]);

        self::readable($doc);

        $this->assertSame("![a](a.png)\n", (new ProseMirrorToCarve())->convert($doc));
    }

    public function testASpaceTypedBeforeALoadedImageIsDroppedFromTheTree(): void
    {
        $doc = (new ProseMirrorRenderer())->renderSource("![a](a.png)\n");
        array_unshift($doc['content'][0]['content'], ['type' => 'text', 'text' => ' ']);

        $saved = (new ProseMirrorToCarve())->toTreeWithOrigins($doc);
        $paragraph = $saved['tree']['children'][0];

        $this->assertSame('image', $paragraph['role']);
        $this->assertCount(1, $paragraph['children']);
        $this->assertSame('/content/0/content/1', $saved['pointers']['/children/0/children/0'] ?? null);
        self::readable($doc);
        $this->assertSame("![a](a.png)\n", (new ProseMirrorToCarve())->convert($doc));
    }
}
