<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\DecisionTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AShiftedMarkerEndsAnAttachedQuotedFenceOwnerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function owners(): iterable
    {
        yield 'item host' => ["- +\n> ```\n  > code\n> ```\n", 2, 'doc/item:1/quote:3', 'quote'];

        yield 'note host' => ["[^a]: +\n> ```\n  > code\n> ```\n\n[^a]\n", 2, 'doc/footnote:1/quote:3', 'quote'];

        yield 'description host' => [":: t\n: +\n> ```\n  > code\n> ```\n", 3, 'doc/dd:2/quote:4', 'quote'];

        yield 'ordinary quote keeps its fence' => ["> ```\n  > code\n> ```\n", 1, 'doc/quote:1/code:1', 'payload'];
    }

    #[DataProvider('owners')]
    public function testTheShiftedLineOwner(string $source, int $recordIndex, string $owner, string $form): void
    {
        $record = DecisionTrace::records($source)[$recordIndex];

        self::assertSame($owner, $record['owner']);
        self::assertSame($form, $record['form']);
    }
}
