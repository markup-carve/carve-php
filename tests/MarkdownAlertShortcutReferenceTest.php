<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownAlertShortcutReferenceTest extends TestCase
{
    public function testAlertShapedShortcutWritesCollapsedReference(): void
    {
        $converted = (new MarkdownToCarve())->convert("[!NOTE]: /u\n\n>\n> [!NOTE]\n> x\n");

        self::assertSame("> [!NOTE][]\n> x\n\n[!NOTE]: /u\n", $converted);
    }
}
