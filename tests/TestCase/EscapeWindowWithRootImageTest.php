<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\EscapeWindows;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

final class EscapeWindowWithRootImageTest extends TestCase
{
    use RuleATodo;

    public function testAnImageBesideBlocksKeepsLocalEscapeWindowsAvailable(): void
    {
        $source = "![a](/i)\n\n  ## H\n";
        $document = (new BlockParser())->parse($source);
        $children = $document->getChildren();
        self::assertInstanceOf(Image::class, $children[0]);
        self::assertInstanceOf(Paragraph::class, $children[1]);
        self::assertNotNull((new EscapeWindows($document))->windowFor([$children[1]->getChildren()[0]]));
        self::assertSame("![a](/i)\n\n\\#\\# H\n", CarveConverter::toCarve($source));
    }

    public function testDistantRootBlocksUseSeparateWindows(): void
    {
        $document = (new BlockParser())->parse("![a](/i)\n\n" . str_repeat("  ## H\n\n", 40));
        $paragraphs = array_values(array_filter(
            $document->getChildren(),
            static fn ($child): bool => $child instanceof Paragraph,
        ));
        $windows = (new EscapeWindows($document))->windowsFor([
            $paragraphs[0]->getChildren()[0],
            $paragraphs[count($paragraphs) - 1]->getChildren()[0],
        ]);
        self::assertCount(2, $windows);
    }

    public function testAnImageInsideADivDoesNotDisableItsChildWindow(): void
    {
        $document = (new BlockParser())->parse("::: box\n![a](/i)\n\n  ## H\n:::\n");
        $div = $document->getChildren()[0];
        self::assertInstanceOf(Div::class, $div);
        $paragraph = $div->getChildren()[1];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertCount(2, (new EscapeWindows($document))->windowFor([$paragraph->getChildren()[0]]));
    }

    public function testInlineImageSiblingsAreNotCutApart(): void
    {
        $document = (new BlockParser())->parse("![a](/i)![b](/j)![c](/k)\n");
        $paragraph = $document->getChildren()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        $windows = (new EscapeWindows($document))->windowFor([$paragraph->getChildren()[2]]);
        self::assertCount(1, $windows);
        self::assertSame($document, $windows[0]['owner']);
    }
}
