<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\DecisionTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnAttachedUnclosedColonEndsAtItsFirstBlankTraceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unclosedContainers(): iterable
    {
        yield 'item' => ["- +\n::: note\n\n  tail\n", 'doc/item:1', 'doc/item:1'];
        yield 'nested item' => ["- a\n  * +\n  ::: note\n\n    - q\n", 'doc/item:1', 'doc/item:1/item:5'];
    }

    #[DataProvider('unclosedContainers')]
    public function testBlankAndFollowingLineLeaveTheUnclosedContainer(string $source, string $blankOwner, string $nextOwner): void
    {
        $rows = DecisionTrace::records($source);

        self::assertSame($blankOwner, $rows[count($rows) - 2]['owner']);
        self::assertSame($nextOwner, $rows[count($rows) - 1]['owner']);
        self::assertStringNotContainsString('div:', $rows[count($rows) - 1]['owner']);
    }

    public function testAColonContainerWithACloserKeepsItsInteriorBlank(): void
    {
        $source = "- +\n::: note\n\n  inside\n::: \n";
        $rows = DecisionTrace::records($source);

        self::assertSame('doc/div:2', $rows[2]['owner']);
        self::assertSame('doc/div:2', $rows[3]['owner']);
        self::assertStringContainsString('<p>inside</p>', (new CarveConverter())->convert($source));
    }

    public function testACloserAfterASiblingDoesNotKeepTheColonOpen(): void
    {
        $rows = DecisionTrace::records("- +\n::: note\n\n- next\n:::\n");

        self::assertSame('doc/item:1', $rows[2]['owner']);
        self::assertSame('doc/item:4', $rows[3]['owner']);
    }

    public function testAColonRunInsideACodeFenceIsNotACloser(): void
    {
        $rows = DecisionTrace::records("- +\n::: note\n\n~~~\n:::\n~~~\n  tail\n");

        self::assertSame('doc/item:1', $rows[2]['owner']);
        self::assertSame('doc/code:4', $rows[4]['owner']);
        self::assertStringNotContainsString('/div:', $rows[6]['owner']);
    }
}
