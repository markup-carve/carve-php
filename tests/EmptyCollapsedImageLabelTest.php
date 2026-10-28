<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class EmptyCollapsedImageLabelTest extends TestCase
{
    public function testAnEmptyCollapsedImageIsNotAnImageNode(): void
    {
        $paragraph = (new BlockParser())->parse("![][]\n")->getChildren()[0];

        foreach ($paragraph->getChildren() as $child) {
            self::assertNotInstanceOf(Image::class, $child);
        }
        self::assertSame(['text', 'link'], array_map(static fn ($child): string => $child->getType(), $paragraph->getChildren()));
    }

    public function testAnEmptyFullReferenceImageRemainsAnImageNode(): void
    {
        $first = (new BlockParser())->parse("[r]: /u\n![][r]\n")->getChildren()[0];

        self::assertInstanceOf(Image::class, $first);
    }
}
