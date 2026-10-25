<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class NestedLazyFenceContinuationTest extends TestCase
{
    public function testPlainLineAfterFoldedNestedFenceStaysInItem(): void
    {
        $converter = new CarveConverter();
        foreach (
            [
                "- - text\n  ~~~\n+ continuation\n" => "text\n~~~\n+ continuation",
                "* * item\n  ~~~\n+ next\n" => "item\n~~~\n+ next",
                "- - text\n  ~~~\nordinary words\n" => "text\n~~~\nordinary words",
            ] as $source => $content
        ) {
            $html = $converter->convert($source);
            self::assertStringContainsString('<li>' . $content . '</li>', $html);
            self::assertStringNotContainsString('</ul>\n<p>', $html);
        }
    }
}
