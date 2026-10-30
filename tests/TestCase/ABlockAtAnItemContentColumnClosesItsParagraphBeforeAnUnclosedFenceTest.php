<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block at an item's content column ends the item's paragraph
 * (CARVE-P0-010, CARVE-P0-011). An unclosed fence below it runs to the end of
 * the container, so the `:::` closer and the lines after it are payload
 * (carve#4032).
 */
class ABlockAtAnItemContentColumnClosesItsParagraphBeforeAnUnclosedFenceTest extends TestCase
{
    use RuleATodo;

    /**
     * @var string
     */
    private const RUNS_PAST_THE_CLOSER = "  <pre><code>code\n::::\n:::\nafter *b*\n</code></pre>\n</aside>";

    /**
     * @param array<string> $lines
     */
    #[DataProvider('closingProvider')]
    public function testTheFenceRunsPastTheCloser(array $lines): void
    {
        $this->assertStringEndsWith(self::RUNS_PAST_THE_CLOSER, $this->render($lines));
    }

    /**
     * @param array<string> $lines
     */
    #[DataProvider('openProvider')]
    public function testAParagraphLeftOpenStillLetsTheFenceInterrupt(array $lines): void
    {
        $this->assertStringEndsWith(self::RUNS_PAST_THE_CLOSER, $this->render($lines));
    }

    /**
     * @return array<string, array{0: array<string>}>
     */
    public static function closingProvider(): array
    {
        return [
            'link definition' => [['- para', '  [r]: /u']],
            'footnote definition' => [['- para', '  [^f]: n']],
            'ordered item definition' => [['1. para', '   [r]: /u']],
            'quoted item definition' => [['> - para', '>   [r]: /u']],
            'task item definition' => [['- [ ] para', '  [r]: /u']],
            'definition past the column' => [['- para', '    [r]: /u']],
            'definition at the outer item column' => [['- a', '  - para', '  [r]: /u']],
            'table row' => [['- para', '  | t |']],
            'ordered item table row' => [['10. para', '    | t |']],
            'quoted item table row' => [['> - para', '>   | t |']],
            'nested item table row' => [['- a', '  - para', '    | t |']],
            'attribute line' => [['- para', '  {#x}']],
            'heading' => [['- para', '  # H']],
            'table row under an attributed marker' => [['-{#x} para', '  | t |']],
            'table row under an attributed ordered marker' => [['1.{#x} para', '   | t |']],
        ];
    }

    /**
     * @return array<string, array{0: array<string>}>
     */
    public static function openProvider(): array
    {
        return [
            'definition below the column' => [['- para', ' [r]: /u']],
            'table row below the column' => [['10. para', '   | t |']],
            'item prose after a table row' => [['- para', '  | t |', '  more']],
            'definition below the column of an item with trailing spaces' => [['- para  ', ' [r]: /u']],
        ];
    }

    /**
     * @param array<string> $lines
     */
    private function render(array $lines): string
    {
        $source = implode("\n", ['::: note', ...$lines, '```', 'code', '::::', ':::', 'after *b*']) . "\n";

        return rtrim((new CarveConverter())->convert($source), "\n");
    }
}
