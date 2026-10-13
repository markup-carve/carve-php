<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ACommentAtTheItemColumnLeavesNoParagraphClaimTest extends TestCase
{
    public function testCorpusUsesTheCommentExceptionReading(): void
    {
        foreach ([1, 2, 3, 4] as $row) {
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
