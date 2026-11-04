<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ACommentAtTheItemColumnLeavesNoParagraphClaimTest extends TestCase
{
    use RuleATodo;

    public function testCorpusUsesTheCommentExceptionReading(): void
    {
        // Rows 3 and 4 moved under carve#5012; CarveCorpusTest checks them through AHEAD_OF_PIN.
        foreach ([1, 2] as $row) {
            $base = __DIR__ . '/spec/tests/corpus/557-a-line-retained-after-a-comment-opens-a-paragraph' . ($row === 1 ? '' : '-' . $row);
            $html = (new CarveConverter())->convert(file_get_contents($base . '.crv'));
            self::assertSame(trim(file_get_contents($base . '.html')), trim($html));
            if ($row <= 2) {
                self::assertStringContainsString("</blockquote>\n<ul>", $html);
                self::assertStringContainsString('<li>tail</li>', $html);
            }
        }
    }
}
