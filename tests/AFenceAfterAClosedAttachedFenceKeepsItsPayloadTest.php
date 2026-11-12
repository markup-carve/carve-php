<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\DecisionTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The miss rule of an own-line attached fence (PART 0 S1, carve#5635) ends that
 * fence only. After it closes, a later fence keeps its blanks and every line up
 * to its closer, as carve-js and carve-rs read it (carve-php#5215).
 */
final class AFenceAfterAClosedAttachedFenceKeepsItsPayloadTest extends TestCase
{
    public function testTheLaterFenceOwnsTheLineAfterItsBlank(): void
    {
        $records = DecisionTrace::records("[^f]: outer\n+\n```\n```\n```\n\n- a\n```\n");
        $owners = [];
        foreach ($records as $record) {
            if (is_int($record['line']) && ($record['step'] === 'S1' || $record['step'] === 'S5')) {
                $owners[$record['line']] = $record['owner'];
            }
        }

        self::assertSame('doc/code:5', $owners[6]);
        self::assertSame('doc/code:5', $owners[7]);
        self::assertSame('doc/code:5', $owners[8]);
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function documents(): iterable
    {
        $tail = ":::\n:: t\n:  head\n     %%%\n:  head\n     %%%\n%%%\n```\n  %%%\n:::\nOuter\n";
        $expected = "<div>\n  <dl>\n    <dt>t</dt>\n    <dd>head</dd>\n    <dd>head</dd>\n  </dl>\n"
            . "  <pre><code>  %%%\n:::\nOuter\n</code></pre>\n</div>";

        yield 'note host' => ["[^f]: outer\n+\n```\n```\n```\n\n- a\n```\n" . $tail, $expected];
        yield 'item host' => ["- outer\n+\n```\n```\n```\n\n- a\n```\n" . $tail, $expected];
        yield 'quote host' => ["> outer\n+\n~~~\n~~~\n```\n\na\n```\n" . $tail, $expected];
    }

    /**
     * @param string $source
     * @param non-empty-string|string $expected
     */
    #[DataProvider('documents')]
    public function testADivCloserInsideTheLaterFenceIsPayload(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);

        self::assertStringEndsWith($expected, $html);
    }
}
