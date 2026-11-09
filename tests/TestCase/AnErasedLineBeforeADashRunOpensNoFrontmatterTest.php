<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 1: only an opener on authored line one claims frontmatter. A `%%` line or a
 * definition before it is erased before layout, but still stands first (carve#5706).
 */
class AnErasedLineBeforeADashRunOpensNoFrontmatterTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function erasedFirstLines(): array
    {
        return [
            'line comment' => ["%% c\n---\nx\n"],
            'indented line comment' => ["  %% c\n---\nx\n"],
            'two line comments' => ["%% a\n%% b\n---\nx\n"],
            'footnote definition' => ["[^n]: a\n---\nx\n"],
            'reference definition' => ["[r]: /u\n---\nx\n"],
            'abbreviation definition' => ["*[A]: b\n---\nx\n"],
        ];
    }

    #[DataProvider('erasedFirstLines')]
    public function testTheDashRunStaysABreak(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString("<hr>\n<p>x</p>", $html);
    }

    public function testAClosedPairAfterACommentIsTwoBreaks(): void
    {
        $this->assertSame("<hr>\n<p>a: b</p>\n<hr>", (new CarveConverter())->convert("%% c\n---\na: b\n---\n"));
    }

    public function testAnOpenerOnLineOneStillClaims(): void
    {
        $this->assertSame("<p>y</p>", (new CarveConverter())->convert("---\n%% c\n---\ny\n"));
    }
}
