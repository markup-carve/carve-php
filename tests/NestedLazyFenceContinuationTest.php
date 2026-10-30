<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class NestedLazyFenceContinuationTest extends TestCase
{
    public function testPlainLineAfterAnUnclosedNestedFenceLeavesTheItem(): void
    {
        // The run opens an empty fence in the outer item (carve#4032), so the next line is not lazy.
        $converter = new CarveConverter();
        foreach (
            [
                "- - text\n  ~~~\n+ continuation\n" => ['text', '+ continuation'],
                "* * item\n  ~~~\n+ next\n" => ['item', '+ next'],
                "- - text\n  ~~~\nordinary words\n" => ['text', 'ordinary words'],
            ] as $source => [$content, $after]
        ) {
            $html = $converter->convert($source);
            self::assertStringContainsString('<li>' . $content . '</li>', $html);
            self::assertStringEndsWith("<pre><code></code></pre>\n  </li>\n</ul>\n<p>{$after}</p>", $html);
        }
    }
}
