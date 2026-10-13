<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AVisibleDescriptionOpenerAfterAnUnclosedRunKeepsItsOwnerTest extends TestCase
{
    public function testPastColumnBlocksInterruptTheInlineRunInsideTheDescription(): void
    {
        $sources = [
            ["- :: t\n  :   p\n      ```\n    ```\n        | a |\n", 'table', "\n"],
            ["- :: t\n  :   p\n      ```\n        | a |\n", 'table', ''],
            ["- :: a\n  : p\n    ```\n      > q\n", 'block_quote', ''],
            ["> - :: a\n>   : p\n>         ```\n>       | a |\n", 'table', ''],
            ["> - :: term\n>   : payload\n   code\n>       ```=html\n>         :::\n>  :: t2\n", 'div', '=html'],
        ];
        foreach ($sources as [$source, $block, $code]) {
            $pending = [(new CarveConverter())->parse($source)];
            $found = false;
            while ($pending !== []) {
                $node = array_pop($pending);
                if ($node->getType() === 'definition_description') {
                    $children = $node->getChildren();
                    self::assertSame(['paragraph', $block], array_map(static fn ($child): string => $child->getType(), $children));
                    $codeNodes = array_filter($children[0]->getChildren(), static fn ($child): bool => $child->getType() === 'code');
                    self::assertSame($code, array_values($codeNodes)[0]->getContent());
                    $found = true;
                }
                array_push($pending, ...$node->getChildren());
            }
            self::assertTrue($found, $source);
            self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert(CarveConverter::toCarve($source)));
        }
    }

    public function testPlainContinuationAndClosedFenceControls(): void
    {
        $converter = new CarveConverter();
        self::assertStringContainsString("<code>\nx</code>", $converter->convert("- :: a\n  : p\n    ```\n      x\n"));
        $html = $converter->convert("- :: term\n  : desc\n      ```\n      code\n      ```\nafter\n");
        self::assertStringContainsString("<pre><code>code\n</code></pre>", $html);
        self::assertStringEndsWith("<p>after</p>\n", $html);
    }
}
