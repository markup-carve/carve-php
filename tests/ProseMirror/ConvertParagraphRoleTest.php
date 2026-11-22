<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-157: an editor paragraph that holds one image is saved as an image paragraph, so its source loses nothing.
 */
final class ConvertParagraphRoleTest extends TestCase
{
    /**
     * @return array<string, array{list<array<string, mixed>>, string}>
     */
    public static function cases(): array
    {
        $image = ['type' => 'image', 'attrs' => ['src' => 'a.png', 'alt' => 'a', 'title' => null]];

        return [
            'one image' => [[$image], "![a](a.png)\n"],
            'an image and a hard break' => [[$image, ['type' => 'hardBreak']], "![a](a.png)\\\n"],
            'an image and text' => [[$image, ['type' => 'text', 'text' => ' x']], "![a](a.png) x\n"],
        ];
    }

    /**
     * @param list<array<string, mixed>> $content
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testAParagraphWithAnImageSaves(array $content, string $source): void
    {
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $content]]];
        $this->assertSame($source, (new ProseMirrorToCarve())->convert($doc));
    }
}
