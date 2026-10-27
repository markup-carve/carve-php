<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AFootnoteDescriptionBoundaryReleasesSiblingBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function siblingBlocks(): array
    {
        return [
            'quote' => ['> q', '<blockquote><p>q</p></blockquote>'],
            'heading' => ['# h', '<h1 id="h">h</h1>'],
            'list' => ['- a', "<ul>\n            <li>a</li>\n          </ul>"],
        ];
    }

    #[DataProvider('siblingBlocks')]
    public function testTwoBlanksReleaseFollowingBlock(string $opener, string $expectedHtml): void
    {
        $source = "[^n]: x\n\n  :: t\n  : d\n\n\n    " . $opener . "\n\na[^n]\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<p>d</p>\n          " . $expectedHtml . "\n        </dd>", $html);
    }

    public function testOneBlankKeepsIndentedContinuationInDescription(): void
    {
        $source = "[^n]: x\n\n  :: t\n  : d\n\n    > q\n\na[^n]\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("          <blockquote><p>q</p></blockquote>\n        </dd>", $html);
    }
}
