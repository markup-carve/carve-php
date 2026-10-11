<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AClosedMarkerLineFenceEndsBothHostsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function closedFences(): array
    {
        $cases = [];
        foreach (['```', '~~~', '``` php', '```=html'] as $opener) {
            foreach ([false, true] as $description) {
                $lead = $description ? ":: t\n: - - " : '- - ';
                $indent = $description ? '      ' : '    ';
                $closer = str_starts_with($opener, '~') ? '~~~' : '```';
                foreach (['x', '```'] as $follower) {
                    $source = $lead . $opener . "\n" . $indent . $closer . "\n" . $follower . "\n";
                    $cases[$opener . ($description ? ' description ' : ' item ') . $follower] = [$source, $follower];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('closedFences')]
    public function testTheFollowerBelongsToTheDocument(string $source, string $follower): void
    {
        $document = CarveConverter::create()->parse($source);
        $children = $document->getChildren();
        self::assertCount(2, $children);
        $html = CarveConverter::create()->convert($source);
        self::assertStringEndsWith(
            $follower === 'x' ? "<p>x</p>\n" : "<pre><code></code></pre>\n",
            $html,
        );
    }

    public function testAnUnfinishedOpenerKeepsItsBodyInBothHosts(): void
    {
        foreach (["- - ```\nx\n", ":: t\n: - - ```\nx\n"] as $source) {
            self::assertCount(1, CarveConverter::create()->parse($source)->getChildren());
            self::assertStringContainsString("<pre><code>x\n</code></pre>", CarveConverter::create()->convert($source));
        }
    }

    public function testAParagraphBelowTheCloserStillContinuesInBothHosts(): void
    {
        foreach (["- - ```\n    ```\n    y\nx\n", ":: t\n: - - ```\n      ```\n      y\nx\n"] as $source) {
            self::assertCount(1, CarveConverter::create()->parse($source)->getChildren());
            self::assertStringContainsString("y\nx", CarveConverter::create()->convert($source));
        }
    }
}
