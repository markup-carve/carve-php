<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AStructuralTitleOccupiesTheHtmlSlotTest extends TestCase
{
    public function testLinkDestinationTitleWinsTheHtmlSlot(): void
    {
        $this->assertSame(
            '<p><a href="/u" title="T">E</a></p>' . "\n",
            CarveConverter::create()->convert('[E](/u "T"){TITLE=Z}'),
        );
    }

    public function testImageDestinationTitleWinsTheHtmlSlot(): void
    {
        $this->assertSame(
            '<img src="/i" alt="A" title="T">' . "\n",
            CarveConverter::create()->convert('![A](/i "T"){title=Z}'),
        );
    }

    public function testAttributeTitleSurvivesWithoutADestinationTitle(): void
    {
        $this->assertSame(
            '<p><a href="/u" title="Z">E</a></p>' . "\n",
            CarveConverter::create()->convert('[E](/u){title=Z}'),
        );
    }
}
