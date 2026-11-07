<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkerLineAttachmentEndsAtBlankInEveryHostTest extends TestCase
{
    public static function hostsAndBlocks(): iterable
    {
        foreach (['[^1]: +' => '[^1]', ":: t\n: +" => ''] as $host => $reference) {
            foreach (['```' => '<pre><code></code></pre>', '::: note' => '</aside>'] as $block => $ended) {
                yield $host . ' / ' . $block => [$host . "\n" . $block . "\n\n  tail\n\n" . $reference, $ended];
            }
        }
    }

    #[DataProvider('hostsAndBlocks')]
    public function testHostResumesAfterItsAttachedBlockEnds(string $source, string $ended): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString($ended, $html);
        self::assertStringContainsString('<p>tail', $html);
        self::assertLessThan(strpos($html, '<p>tail'), strpos($html, $ended));
    }

    public function testAnIndentedMatchingRunBeforeTheBlankIsPayload(): void
    {
        $html = (new CarveConverter())->convert("[^a]: +\n```\nhi\n  ```\n\n  tail\n\nx[^a]\n");
        self::assertStringContainsString("<pre><code>hi\n  ```\n</code></pre>", $html);
        self::assertStringContainsString('<p>tail', $html);
    }
}
