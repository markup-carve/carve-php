<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A task checkbox is content, so the authored gap after the bullet sets the
 * content column, exactly as for the plain item (carve#4618, CARVE-P9-083).
 */
final class TaskItemGapSetsTheContentColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function twins(): iterable
    {
        foreach (['-', '*', '-{#k}'] as $bullet) {
            foreach ([1, 3, 4] as $gap) {
                // A sibling has to be a task as well, or it is not the same list.
                foreach (["  # H\n" => '', "   - r\n" => '[ ] ', "    x\n" => '', "  > q\n" => ''] as $follower => $box) {
                    $lead = $bullet . str_repeat(' ', $gap);
                    $plainFollower = str_replace('- r', $bullet[0] . ' r', $follower);
                    $taskFollower = str_replace('- r', $bullet[0] . ' ' . $box . 'r', $follower);

                    yield "$bullet-$gap-" . trim($follower) => [$lead . "[ ] q\n" . $taskFollower, $lead . "q\n" . $plainFollower];
                }
            }
        }
    }

    #[DataProvider('twins')]
    public function testATaskItemClosesWhereItsPlainTwinCloses(string $task, string $plain): void
    {
        $converter = new CarveConverter();
        $strip = static fn (string $html): string => (string)preg_replace(
            ['/ class="task-list"/', '/<input[^>]*> /'],
            '',
            $html,
        );

        self::assertSame($strip($converter->convert($plain)), $strip($converter->convert($task)), $task);
    }
}
