<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P11-002: a sub-list on a task item's marker line owns the columns after the task box.
 */
class ATaskMarkerLineSubListOwnsItsColumnsTest extends TestCase
{
    public function testAnUnclosedContainerClosesAtTheSubItemColumn(): void
    {
        $this->assertSame("* [ ] - :::\n\n        :::\n", CarveConverter::toCarve("* [ ] - :::\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        foreach (["* [ ] - :::\n", "* [x] - :::\n  a\n", "- [ ] 1. :::\n", "- [ ] - - :::\n", "> * [ ] - :::\n", "* [ ] - ```\n  x\n", "* [ ] - > a\n\n        > b\n", "* [ ] - a\n\n        b\n", "* [ ] - a\n        - b\n", "- - [ ] - :::\n"] as $source) {
            yield $source => [$source];
        }
    }

    #[DataProvider('sources')]
    public function testItRoundTrips(string $source): void
    {
        $written = CarveConverter::toCarve($source);
        $converter = new CarveConverter();
        $this->assertSame($converter->convert($source), $converter->convert($written));
        $this->assertSame($written, CarveConverter::toCarve($written));
    }

    public function testABlockOfTheTaskItemStaysAtItsColumn(): void
    {
        $this->assertSame("* [ ] - a\n\n  b\n", CarveConverter::toCarve("* [ ] - a\n\n  b\n"));
    }

    public function testASubListWithoutABoxStaysPut(): void
    {
        $this->assertSame("- - :::\n\n    :::\n", CarveConverter::toCarve("- - :::\n"));
        $this->assertSame("1. - :::\n\n     :::\n", CarveConverter::toCarve("1. - :::\n"));
    }
}
