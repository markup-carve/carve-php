<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptionBlankRunContinuationTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{int}>
     */
    public static function blankRuns(): array
    {
        return [
            'one blank' => [1],
            'two blanks' => [2],
            'three blanks' => [3],
        ];
    }

    #[DataProvider('blankRuns')]
    public function testAQuoteAtTheBodyColumnContinuesAfterBlankLines(int $blankCount): void
    {
        $source = ":: t\n: d\n" . str_repeat("\n", $blankCount) . "    > q\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testABlankRunDoesNotClaimALineBelowTheBodyColumn(): void
    {
        $source = ":: t\n: d\n\n\n > q\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>&gt; q</p>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
