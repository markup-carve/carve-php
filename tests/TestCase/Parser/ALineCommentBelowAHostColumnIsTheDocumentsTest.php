<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P11-079 (b), markup-carve/carve#4850.
 * carve#5012 erases the comment, so the writer keeps one inside a paragraph at its line.
 */
class ALineCommentBelowAHostColumnIsTheDocumentsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'nested marker line' => ["- - a\n %% c\n\n b\n", "- - a\n\n%% c\n\nb\n"],
            'nested marker line, tight' => ["- - a\n %% c\n", "- - a\n\n%% c\n"],
            'reaches only the outer item' => ["- - a\n  %% c\n", "- - a\n\n%% c\n"],
            'description body' => [":: t\n:  d\n  %% c\n", ":: t\n: d\n\n%% c\n"],
            'description with a line below' => [":: t\n: d\n %% c\n x\n", ":: t\n: d\n  %% c\n  x\n"],
        ];
    }

    #[DataProvider('provider')]
    public function testTheWriterPutsItWhereItBelongs(string $source, string $written): void
    {
        $this->assertSame($written, CarveConverter::toCarve($source));
        $this->assertSame($written, CarveConverter::toCarve($written));
    }
}
