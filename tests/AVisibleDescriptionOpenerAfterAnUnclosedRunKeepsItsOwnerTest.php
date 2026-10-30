<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AVisibleDescriptionOpenerAfterAnUnclosedRunKeepsItsOwnerTest extends TestCase
{
    public function testTheRunOpensAFenceInsideTheDescription(): void
    {
        // The run is a valid opener that interrupts the paragraph (carve#4032); the third field is the block's content.
        $sources = [
            ["- :: t\n  :   p\n      ```\n    ```\n        | a |\n", 'code_block', ''],
            ["- :: t\n  :   p\n      ```\n        | a |\n", 'code_block', "  | a |\n"],
            ["- :: a\n  : p\n    ```\n      > q\n", 'code_block', "  > q\n"],
            ["> - :: a\n>   : p\n>         ```\n>       | a |\n", 'code_block', "| a |\n"],
            ["> - :: term\n>   : payload\n   code\n>       ```=html\n>         :::\n>  :: t2\n", 'raw_block', "  :::\n"],
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
                    self::assertSame([], $codeNodes);
                    self::assertSame($code, $children[1]->getContent());
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
        self::assertStringContainsString("<pre><code>  x\n</code></pre>", $converter->convert("- :: a\n  : p\n    ```\n      x\n"));
        $html = $converter->convert("- :: term\n  : desc\n      ```\n      code\n      ```\nafter\n");
        self::assertStringContainsString("<pre><code>code\n</code></pre>", $html);
        self::assertStringEndsWith(preg_replace('/\n\z/', '', "<p>after</p>\n"), $html);
    }
}
