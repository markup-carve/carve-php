<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blank before a `+` that continues a nested item loosens the outer list
 * whether the sub-list opens on the marker line or on a following line
 * (corpus 632-21, 638).
 */
class ABlankBeforeAStackedSubListPlusLoosensTheOuterListTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function documents(): array
    {
        return [
            'stacked opener, fence payload blank' => ["- - ```\n\n  +\n  x\n", false],
            'lead line, fence payload blank' => ["- a\n  - ```\n    x\n\n  +\n  > q\n", false],
            'stacked opener, no blank' => ["- - ```\n  +\n  x\n", true],
        ];
    }

    #[DataProvider('documents')]
    public function testOuterListTightness(string $source, bool $tight): void
    {
        $document = (new CarveConverter())->parse($source);
        $list = $document->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame($tight, $list->isTight());
    }
}
