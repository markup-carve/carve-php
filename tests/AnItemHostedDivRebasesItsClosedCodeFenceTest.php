<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AnItemHostedDivRebasesItsClosedCodeFenceTest extends TestCase
{
    use RuleATodo;

    public function testLaterLineAndMarkerLineDivsOwnTheFence(): void
    {
        $converter = new CarveConverter();
        foreach (["- a\n  ::: box", '- ::: box'] as $lead) {
            foreach (['', '> '] as $prefix) {
                $source = $lead . "\n    ```\n    code\n    ```\nafter\n";
                if ($prefix !== '') {
                    $source = preg_replace('/^(?!after)/m', $prefix, rtrim($source)) . "\n";
                }
                $html = $converter->convert($source);
                self::assertStringContainsString("<pre><code>code\n</code></pre>", $html);
                self::assertStringEndsWith('<p>after</p>', $html);
            }
        }
        $html = $converter->convert("::: box\n    ```\n    code\n    ```\nafter\n");
        self::assertStringNotContainsString('<pre>', $html);
        self::assertStringContainsString('<code>', $html);
    }
}
