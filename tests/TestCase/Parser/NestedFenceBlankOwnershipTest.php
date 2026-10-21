<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NestedFenceBlankOwnershipTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'child content column' => ["- - ```\n  +\n\n    x\n", true];
        yield 'child content column without final LF' => ["- - ```\n  +\n\n    x", true];
        yield 'child content column after two blanks' => ["- - ```\n  +\n\n\n    x\n", true];
        yield 'before child content column' => ["- - ```\n  +\n\n   x\n", false];
        yield 'outer content column' => ["- - ```\n\n\n  +\ntail\n", false];
        yield 'ordinary nested paragraph gap' => ["- - a\n\n  Body.\n", false];
        yield 'invisible definition leaves a separator' => ["- - body\n\n  [^g]: n\n   prose", false];
    }

    #[DataProvider('cases')]
    public function testNestedFenceOwnsOnlyBlanksAtItsContentColumn(string $source, bool $tight): void
    {
        $document = (new BlockParser())->parse($source);
        $outer = $document->getChildren()[0];

        self::assertInstanceOf(ListBlock::class, $outer);
        self::assertSame($tight, $outer->isTight());
    }
}
