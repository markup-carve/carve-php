<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ExternalLinksExtension;
use MarkupCarve\Carve\Extension\MentionsExtension;
use PHPUnit\Framework\TestCase;

class ExtensionTest extends TestCase
{
    public function testAddExtension(): void
    {
        $converter = new CarveConverter();
        $extension = new ExternalLinksExtension();

        $result = $converter->addExtension($extension);

        $this->assertSame($converter, $result);
        $this->assertCount(1, $converter->getExtensions());
        $this->assertSame($extension, $converter->getExtensions()[0]);
    }

    public function testMultipleExtensions(): void
    {
        $converter = new CarveConverter();

        $converter
            ->addExtension(new ExternalLinksExtension())
            ->addExtension(new MentionsExtension());

        $this->assertCount(2, $converter->getExtensions());
    }
}
